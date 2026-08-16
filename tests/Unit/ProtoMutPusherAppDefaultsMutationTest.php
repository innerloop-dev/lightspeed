<?php

use Lightspeed\Protocol\PusherApp;

/**
 * What this server's identity reads when nobody has configured it yet.
 *
 * The shipped config resolves all four of these through `env()`, and `env()`
 * hands back NULL for a variable that was never set (and for one written as
 * `null`). So the unconfigured install is not a hypothetical: it is what every
 * `composer require` produces before the app key is filled in, and it is the
 * state a `doctor` run and the boot refusals both have to be able to describe.
 *
 * Each accessor here therefore has to answer with its declared type rather than
 * pass a null through, and the fallbacks have to be the values the rest of the
 * package compares against: an empty credential (which BootValidation refuses),
 * the Pusher path prefix, and the activity timeout that goes out in the
 * handshake frame.
 */
test('an unconfigured install reads as empty credentials rather than a type error', function () {
    // `env('LIGHTSPEED_APP_KEY', env('REVERB_APP_KEY'))` with neither variable
    // set. Without the casts these accessors return that null straight out of a
    // `: string` method, so the first thing to touch the app key on the
    // handshake path dies instead of refusing the connection.
    config()->set('lightspeed.reverb_compat.app_key', null);
    config()->set('lightspeed.reverb_compat.app_secret', null);
    config()->set('lightspeed.reverb_compat.path_prefix', null);
    config()->set('lightspeed.reverb_compat.activity_timeout', null);

    $app = new PusherApp();

    expect($app->key())->toBe('')
        ->and($app->secret())->toBe('')
        ->and($app->pathPrefix())->toBe('')
        ->and($app->activityTimeout())->toBe(0);
});

test('a missing reverb_compat block falls back to the values the protocol expects', function () {
    // The other shape of "not configured": the block is absent entirely, so the
    // in-code fallbacks are what answer. An empty credential is what
    // BootValidation refuses on; a non-empty stand-in would let a server start
    // signing with a key nobody chose. The prefix and the timeout are protocol
    // values: the prefix is the path every Pusher client dials, and the timeout
    // is the seconds a client waits before it pings.
    config()->set('lightspeed.reverb_compat', []);

    $app = new PusherApp();

    expect($app->key())->toBe('')
        ->and($app->secret())->toBe('')
        ->and($app->pathPrefix())->toBe('/app')
        ->and($app->activityTimeout())->toBe(30);
});
