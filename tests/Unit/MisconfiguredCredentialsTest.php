<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Broadcasting\LightspeedBroadcaster;

/**
 * An empty `LIGHTSPEED_APP_SECRET` must break Lightspeed, and nothing else.
 *
 * It used to break the entire host application. The provider's
 * `Broadcast::extend('lightspeed', ...)` closure built the Pusher client
 * eagerly, and that builder threw when app_id, app_key or app_secret was empty.
 * `Broadcast::channel()` is not a method on BroadcastManager, so it falls
 * through `__call` to `driver()`, which runs that closure. And Laravel 11
 * loads `routes/channels.php` during provider boot via
 * `withRouting(channels: ...)`. So one empty env var took down every artisan
 * command and every web request in the application, including applications
 * that were not using realtime at all:
 *
 *   RuntimeException
 *   Lightspeed broadcaster requires lightspeed.reverb_compat app_id, app_key,
 *   and app_secret ...
 *   at src/LightspeedServiceProvider.php:140
 *   8   routes/channels.php:16   Facade::__callStatic("channel")
 *
 * It also bought nothing. `BootValidation::validateCredentials()` already refuses to
 * start the server on an empty key or secret, so a misconfigured server cannot
 * run either way; the provider-level throw only added the ability to take down
 * applications that had not started using the package yet.
 *
 * The refusal therefore moves from CONSTRUCTION to USE. Constructing a Pusher
 * client with empty strings is harmless, `Pusher::__construct` assigns them
 * into `$this->settings` with no validation of its own, because credentials
 * are not what construction needs. Signing is.
 *
 * Both halves below are the requirement, and neither is worth anything alone:
 * the application must boot, AND an attempt to authorize a channel must refuse
 * loudly rather than hand out a signature computed with an empty secret.
 */

/** Empty every credential, the way a missing env var leaves them. */
function emptyLightspeedCredentials(): void
{
    config()->set('lightspeed.reverb_compat.app_id', '');
    config()->set('lightspeed.reverb_compat.app_key', '');
    config()->set('lightspeed.reverb_compat.app_secret', '');
}

/**
 * Make the DEFAULT broadcast connection a `lightspeed` one.
 *
 * The name is deliberately a parameter: what the design requires is a
 * connection whose DRIVER is `lightspeed`, not a connection called
 * `lightspeed`.
 */
function useLightspeedConnection(string $name = 'lightspeed'): void
{
    config()->set('broadcasting.default', $name);
    config()->set("broadcasting.connections.{$name}", ['driver' => 'lightspeed']);
}

/** A broadcaster over whatever `lightspeed.reverb_compat` currently says. */
function misconfiguredBroadcaster(): LightspeedBroadcaster
{
    return new LightspeedBroadcaster(
        app(BroadcastBridge::class),
        app(PendingGrants::class),
        app(RevocationLog::class),
        new \Pusher\Pusher(
            (string) config('lightspeed.reverb_compat.app_key'),
            (string) config('lightspeed.reverb_compat.app_secret'),
            (string) config('lightspeed.reverb_compat.app_id'),
        ),
    );
}

// ---------------------------------------------------------------------------
// Half one: the application boots, and unrelated work runs.
// ---------------------------------------------------------------------------

test('the broadcast driver resolves with empty credentials so the application still boots', function () {
    useLightspeedConnection();
    emptyLightspeedCredentials();

    // What `routes/channels.php` does on line one of a stock Laravel 11 app,
    // during provider boot. `channel()` is not a BroadcastManager method, so
    // this resolves the driver and runs the provider's extend closure.
    Broadcast::channel('lightspeed-boot-probe.{id}', fn () => true);

    expect(Broadcast::driver())->toBeInstanceOf(LightspeedBroadcaster::class);
});

test('unrelated application work runs with empty credentials', function () {
    useLightspeedConnection();
    emptyLightspeedCredentials();

    // routes/channels.php, loaded during provider boot.
    Broadcast::channel('lightspeed-boot-probe.{id}', fn () => true);

    // Stands in for `php artisan migrate`, `php artisan tinker`, or the front
    // page: work that has nothing to do with realtime and used to die at boot.
    $this->artisan('list')->assertExitCode(0);
});

/**
 * The connection may be called anything. Its DRIVER is what has to be
 * `lightspeed`.
 *
 * The docblocks on the provider and the broadcaster both used to state that the
 * application MUST set `BROADCAST_CONNECTION=lightspeed`, which is false and
 * was the reason a doctor check once failed a working setup. Laravel registers
 * `Broadcast::channel()` callbacks on the DEFAULT connection's broadcaster, so
 * what the design actually requires is that the default connection resolve a
 * LightspeedBroadcaster, under whatever name.
 */
test('a connection named anything resolves the driver as long as its driver is lightspeed', function () {
    useLightspeedConnection('realtime');

    Broadcast::channel('lightspeed-name-probe', fn () => true);

    expect(Broadcast::driver())->toBeInstanceOf(LightspeedBroadcaster::class);
});

// ---------------------------------------------------------------------------
// Half two: signing refuses, loudly, naming the env var to set.
// ---------------------------------------------------------------------------

test('channel authorization refuses when the app secret is empty', function () {
    config()->set('lightspeed.reverb_compat.app_secret', '');

    expect(fn () => misconfiguredBroadcaster()->auth(
        Request::create('/broadcasting/auth', 'POST', [
            'socket_id' => '7.1234',
            'channel_name' => 'private-orders',
        ]),
    ))->toThrow(RuntimeException::class, 'LIGHTSPEED_APP_SECRET');
});

test('channel authorization refuses when the app key is empty', function () {
    config()->set('lightspeed.reverb_compat.app_key', '');

    expect(fn () => misconfiguredBroadcaster()->auth(
        Request::create('/broadcasting/auth', 'POST', [
            'socket_id' => '7.1234',
            'channel_name' => 'private-orders',
        ]),
    ))->toThrow(RuntimeException::class, 'LIGHTSPEED_APP_KEY');
});

/**
 * The one that matters most. Deferring the check must not become skipping it.
 *
 * `validAuthenticationResponse()` is where the bytes are actually produced, 
 * the untagged form by the inherited Pusher path, the tagged form by this
 * class's own `hash_hmac`. It is a public method reachable without `auth()`
 * ever running, so guarding only the entry point would leave the signer open.
 */
test('no auth signature is produced with an empty secret', function () {
    config()->set('lightspeed.reverb_compat.app_secret', '');

    expect(fn () => misconfiguredBroadcaster()->validAuthenticationResponse(
        Request::create('/broadcasting/auth', 'POST', [
            'socket_id' => '7.1234',
            'channel_name' => 'private-orders',
        ]),
        true,
    ))->toThrow(RuntimeException::class, 'LIGHTSPEED_APP_SECRET');
});

test('no tagged auth signature is produced with an empty secret', function () {
    config()->set('lightspeed.reverb_compat.app_secret', '');

    app(PendingGrants::class)->begin();
    \Lightspeed\Facades\Lightspeed::tag(['user:1'])->with(['can_edit' => true]);

    expect(fn () => misconfiguredBroadcaster()->validAuthenticationResponse(
        Request::create('/broadcasting/auth', 'POST', [
            'socket_id' => '7.1234',
            'channel_name' => 'private-orders',
        ]),
        true,
    ))->toThrow(RuntimeException::class, 'LIGHTSPEED_APP_SECRET');
});

/**
 * The positive control. Without it, a guard that threw unconditionally would
 * satisfy every assertion above while breaking the package outright.
 */
test('a configured application still signs and still authorizes', function () {
    // TestCase::defineEnvironment sets real (arbitrary) credentials.
    $request = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => '7.1234',
        'channel_name' => 'private-orders',
    ]);

    // A guarded channel needs a user; what the auth middleware would have put
    // on the request in a real `/broadcasting/auth` call.
    $request->setUserResolver(fn () => tap(new \Illuminate\Foundation\Auth\User, function ($user) {
        $user->forceFill(['id' => 1]);
    }));

    $broadcaster = misconfiguredBroadcaster();

    // The registry this instance carries: what `Broadcast::channel()` writes to
    // on the default connection's broadcaster.
    $broadcaster->channel('orders', fn () => true);

    // The untagged form, byte for byte what Pusher specifies. auth() returns it
    // because Laravel's verifyUserCanAccessChannel() hands back whatever
    // validAuthenticationResponse() produced.
    $expected = 'test-key:'.hash_hmac('sha256', '7.1234:private-orders', 'test-secret');

    expect($broadcaster->auth($request)['auth'])->toBe($expected)
        ->and($broadcaster->validAuthenticationResponse($request, true)['auth'])->toBe($expected);
});
