<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\GrantManager;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Broadcasting\LightspeedBroadcaster;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\LightspeedServiceProvider;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;

/**
 * The wiring, asserted as wiring.
 *
 * Nothing in this file is clever, and that is the point: every registration in
 * the provider is a line whose absence has no symptom until something much
 * further away misbehaves. A missing singleton is the failure this package has
 * already been bitten by twice, because a second copy of a runtime service
 * holds process-wide state the first copy cannot see: the grants of the
 * connections attached to this worker, the listeners that drop them, and the
 * channel membership every fan-out reads. Both copies work perfectly. They just
 * disagree.
 *
 * A missing console command, a missing publish tag or a broadcast driver that
 * was never extended are the same shape of failure one tier out.
 */

/** Every runtime service that must be one object per process, and why. */
dataset('coreMutSingletons', [
    // Channel membership: the fan-out reads what the subscribe wrote.
    'channel manager' => [ChannelManager::class],
    // Worker identity: every Redis coordination key in the package is written
    // under the process key this holds.
    'worker context' => [WorkerContext::class],
    'connection registry' => [ConnectionRegistry::class],
    'owner command dispatcher' => [OwnerCommandDispatcher::class],
    // Holds the poller's lease and its timer.
    'owner command bus' => [OwnerCommandBus::class],
    // Holds the relay's stream position and its attached server.
    'relay' => [RedisRelay::class],
    'resource router' => [ResourceRouter::class],
    'presence store' => [PresenceStore::class],
    'broadcast bridge' => [BroadcastBridge::class],
    'runtime logger' => [RuntimeLogger::class],
    // Per-message authorization. Each holds state a second instance cannot see.
    'pending grants' => [PendingGrants::class],
    'connection grants' => [ConnectionGrants::class],
    'revocation log' => [RevocationLog::class],
    'grant manager' => [GrantManager::class],
]);

test('a runtime service resolves to one object per process', function (string $service) {
    expect(app($service))->toBe(app($service));
})->with('coreMutSingletons');

test('the package config defaults are merged, so an app that publishes nothing still has them', function () {
    // mergeConfigFrom() is what makes every other dial in this package have a
    // value. Without it, an application that never published the config file
    // reads null for the sweep intervals, the grant lifetime, the diagnostics
    // gate and the credential block, and each of those has its own idea of what
    // null means.
    expect(config('lightspeed.connections.sweep_interval_ms'))->toBe(300000)
        ->and(config('lightspeed.auth.grant_lifetime_seconds'))->not->toBeNull()
        ->and(config('lightspeed.diagnostics.enabled'))->toBeFalse();
});

test('the broadcast driver named lightspeed resolves to this package broadcaster', function () {
    // `Broadcast::extend()` is the whole integration point: without it an
    // application whose default connection has `'driver' => 'lightspeed'` gets
    // Laravel's "Driver [lightspeed] is not supported" and no realtime at all.
    config()->set('broadcasting.connections.core-mut-realtime', ['driver' => 'lightspeed']);

    expect(Broadcast::connection('core-mut-realtime'))->toBeInstanceOf(LightspeedBroadcaster::class);
});

test('the config file is publishable under the tag the install instructions name', function () {
    // `php artisan vendor:publish --tag=lightspeed-config` is the documented
    // first step. A missing tag, or a destination that is not config_path,
    // makes that command report nothing to publish.
    $paths = ServiceProvider::pathsToPublish(LightspeedServiceProvider::class, 'lightspeed-config');

    expect($paths)->toBe([
        dirname(__DIR__, 2).'/src/../config/lightspeed.php' => config_path('lightspeed.php'),
    ]);
});

test('every console command this package ships is registered', function () {
    // Registered only when running in console, and that condition is the one
    // thing standing between an operator and `lightspeed:serve` not existing.
    // Each name here is a documented entry point: serve, stop and restart run
    // the server, install and doctor set it up and check it, and the four
    // probes are what docs/PRODUCTION.md tells an operator to reach for.
    $registered = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());

    expect($registered)->toContain(
        'lightspeed:serve',
        'lightspeed:stop',
        'lightspeed:restart',
        'lightspeed:install',
        'lightspeed:doctor',
        'lightspeed:probe',
        'lightspeed:relay-probe',
        'lightspeed:presence-probe',
        'lightspeed:load-probe',
    );
});

// ---------------------------------------------------------------------------
// The Pusher client, which signs every channel authorization.
// ---------------------------------------------------------------------------

/** The credentials the broadcaster's Pusher client was actually built with. */
function coreMutPusherSettings(): array
{
    config()->set('broadcasting.connections.core-mut-pusher-probe', ['driver' => 'lightspeed']);

    $broadcaster = Broadcast::connection('core-mut-pusher-probe');

    $handle = new ReflectionProperty(\Illuminate\Broadcasting\Broadcasters\PusherBroadcaster::class, 'pusher');
    $handle->setAccessible(true);

    return $handle->getValue($broadcaster)->getSettings();
}

test('the signing client is built from the reverb_compat credentials, verbatim', function () {
    // These three are the whole of channel security on this surface: an auth
    // string is key plus an HMAC of "{socketId}:{channel}" under the secret. A
    // client built from the wrong field, or from a default in place of the
    // configured value, signs something no other tier will verify, and the
    // symptom is every private channel failing authorization with a message
    // about signatures rather than about configuration.
    config()->set('lightspeed.reverb_compat.app_key', 'core-mut-key');
    config()->set('lightspeed.reverb_compat.app_secret', 'core-mut-secret');
    config()->set('lightspeed.reverb_compat.app_id', 'core-mut-id');

    $settings = coreMutPusherSettings();

    expect($settings['auth_key'])->toBe('core-mut-key')
        ->and($settings['secret'])->toBe('core-mut-secret')
        ->and($settings['app_id'])->toBe('core-mut-id');
});

test('a numeric app id arrives as the string the signer expects', function () {
    // An app id of `1` in a config file, or from an env var read as a number,
    // is the ordinary way a non-string reaches here.
    config()->set('lightspeed.reverb_compat.app_id', 12345);

    expect(coreMutPusherSettings()['app_id'])->toBe('12345');
});

test('missing credentials build a client that cannot sign rather than one that throws', function () {
    // Deliberate, and the fix for a defect that took down whole applications:
    // this runs inside the Broadcast::extend closure, so it runs whenever the
    // broadcaster is RESOLVED, and Laravel resolves it while loading
    // routes/channels.php during provider boot. Throwing here killed every
    // artisan command and every web request in applications that were not
    // using realtime at all. The refusal lives where a signature is actually
    // produced; here, absent credentials must simply be absent.
    config()->set('lightspeed.reverb_compat', []);

    $settings = coreMutPusherSettings();

    expect($settings['auth_key'])->toBe('')
        ->and($settings['secret'])->toBe('')
        ->and($settings['app_id'])->toBe('');
});

test('a reverb_compat block that is not an array does not become a fatal at provider boot', function () {
    // This method runs inside the Broadcast::extend closure, so it runs
    // whenever the broadcaster is RESOLVED, which Laravel does while loading
    // routes/channels.php during provider boot. Anything thrown here therefore
    // takes down every artisan command and every web request in the host
    // application, including the ones that have nothing to do with realtime.
    // A credentials block that arrives as an object rather than an array is
    // one of the shapes a config file can hand it.
    config()->set('lightspeed.reverb_compat', (object) ['app_key' => 'core-mut-from-object']);

    expect(coreMutPusherSettings()['auth_key'])->toBe('core-mut-from-object');
});
