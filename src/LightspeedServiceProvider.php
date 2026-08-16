<?php

namespace Lightspeed;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\GrantManager;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Broadcasting\LightspeedBroadcaster;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Console\Commands\LightspeedDoctor;
use Lightspeed\Console\Commands\LightspeedInstall;
use Lightspeed\Console\Commands\LightspeedLoadProbe;
use Lightspeed\Console\Commands\LightspeedPresenceProbe;
use Lightspeed\Console\Commands\LightspeedProbe;
use Lightspeed\Console\Commands\LightspeedRelayProbe;
use Lightspeed\Console\Commands\LightspeedRestart;
use Lightspeed\Console\Commands\LightspeedServe;
use Lightspeed\Console\Commands\LightspeedStop;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Pusher\Pusher;

/**
 * Wires Lightspeed into a Laravel application.
 *
 * Owns: the runtime singletons (channel/connection/presence state, relay,
 * owner-command bus, resource router, per-message grants), the `lightspeed`
 * broadcast driver, the package config defaults, and the console commands
 * (serve/stop/restart, the `lightspeed:install` setup command and the
 * `lightspeed:doctor` setup check, plus the generic protocol probes).
 *
 * Deliberately does not own: application client-event, connection-closed or
 * owner-command handlers. Those are app policy: the app lists handler class
 * names in `lightspeed.client_event_handlers` /
 * `lightspeed.connection_closed_handlers` / `lightspeed.owner_command_handlers`
 * and the dispatchers resolve them from the container lazily.
 *
 * Broadcast driver shape:
 *
 *   broadcast()  --> LightspeedBroadcaster --> BroadcastBridge --> sockets/relay
 *   channel auth --> inherited PusherBroadcaster auth (own callback registry,
 *                    local HMAC signing only, no network)
 *
 * The Pusher client is built directly from `lightspeed.reverb_compat`
 * credentials so a consuming app needs no Reverb/Pusher connection configured.
 *
 * What the app MUST do is make its DEFAULT broadcast connection one whose
 * `driver` is `lightspeed`. The connection's NAME is free. Laravel registers
 * Broadcast::channel() callbacks on the default connection's broadcaster, and
 * channel auth verifies against that instance's own registry, so the
 * requirement is about which broadcaster is default. Not what it is called.
 *
 *   'realtime' => ['driver' => 'lightspeed']   with BROADCAST_CONNECTION=realtime
 *
 * resolves a LightspeedBroadcaster and works exactly as the conventional
 * `BROADCAST_CONNECTION=lightspeed` does. This docblock used to state the name
 * as a requirement, which is why a doctor check once failed a working setup.
 */
class LightspeedServiceProvider extends ServiceProvider
{
    /**
     * Register the package config and the runtime singletons.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lightspeed.php', 'lightspeed');

        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(WorkerContext::class);
        $this->app->singleton(ConnectionRegistry::class);
        $this->app->singleton(OwnerCommandDispatcher::class);
        $this->app->singleton(OwnerCommandBus::class);
        $this->app->singleton(RedisRelay::class);
        $this->app->singleton(ResourceRouter::class);
        $this->app->singleton(PresenceStore::class);
        $this->app->singleton(BroadcastBridge::class);
        $this->app->singleton(RuntimeLogger::class);

        // Per-message authorization. Singletons because each holds process-wide
        // state a second instance would not see: the grants of the connections
        // attached to this worker, and the listeners that drop them.
        //
        // PendingGrants is a singleton too, but it does NOT hold per-request
        // state on itself; see that class for why a singleton holding the
        // pending grant directly leaked one request's permissions onto another
        // request's socket in the design this replaces.
        $this->app->singleton(PendingGrants::class);
        $this->app->singleton(ConnectionGrants::class);
        $this->app->singleton(RevocationLog::class);
        $this->app->singleton(GrantManager::class);
    }

    /**
     * Register the broadcast driver, publishable config, and console commands.
     */
    public function boot(): void
    {
        Broadcast::extend('lightspeed', function ($app, array $config = []) {
            return new LightspeedBroadcaster(
                $app->make(BroadcastBridge::class),
                $app->make(PendingGrants::class),
                $app->make(RevocationLog::class),
                $this->pusherClient(),
            );
        });

        $this->publishes([
            __DIR__.'/../config/lightspeed.php' => config_path('lightspeed.php'),
        ], 'lightspeed-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                LightspeedServe::class,
                LightspeedStop::class,
                LightspeedRestart::class,
                LightspeedInstall::class,
                LightspeedDoctor::class,
                LightspeedProbe::class,
                LightspeedRelayProbe::class,
                LightspeedPresenceProbe::class,
                LightspeedLoadProbe::class,
            ]);
        }
    }

    /**
     * Build the Pusher client used for channel-auth signing.
     *
     * Only used for local HMAC auth signatures: it never talks to a Pusher
     * server, so no host/cluster options are needed.
     *
     * DOES NOT VALIDATE THE CREDENTIALS, deliberately, and this is the fix for
     * a defect that took down whole applications.
     *
     * It used to throw a RuntimeException when app_id, app_key or app_secret
     * was empty. This method runs inside the `Broadcast::extend('lightspeed')`
     * closure, so it runs whenever the broadcaster is RESOLVED. and
     * `Broadcast::channel()` is not a method on BroadcastManager, so it falls
     * through `__call` to `driver()`, which resolves it. Laravel 11 loads
     * `routes/channels.php` during provider boot via `withRouting(channels:)`.
     * So one empty env var threw before any application code ran, and every
     * artisan command and every web request in the host application died at
     * boot, `php artisan migrate`, `php artisan tinker`, the front page, in
     * applications that were not using realtime at all.
     *
     * It bought nothing, either. `BootValidation::validateCredentials()` already
     * refuses to start the server on an empty key or secret, so a misconfigured
     * server cannot run either way.
     *
     * The refusal therefore lives where the credentials are actually USED, not
     * where the client is built. Constructing is safe: `Pusher::__construct`
     * assigns its arguments straight into `$this->settings` and validates
     * nothing, so empty strings here produce a client that exists and cannot
     * sign. `LightspeedBroadcaster` refuses, naming the env vars, on every path
     * that produces or verifies a signature. See
     * `LightspeedBroadcaster::assertCanSign()`.
     */
    private function pusherClient(): Pusher
    {
        $compat = (array) $this->app['config']->get('lightspeed.reverb_compat', []);

        return new Pusher(
            (string) ($compat['app_key'] ?? ''),
            (string) ($compat['app_secret'] ?? ''),
            (string) ($compat['app_id'] ?? ''),
        );
    }
}
