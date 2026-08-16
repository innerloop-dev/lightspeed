<?php

use Lightspeed\Protocol\PusherPaths;
use Lightspeed\Protocol\SocketId;

/**
 * Both of these paths gate authentication, so a loose match is a security
 * problem rather than a routing inconvenience.
 *
 * A websocket path that is not the Pusher path routes the socket to the
 * diagnostic protocol's loopback gate instead, and a path that is not the
 * publish endpoint never reaches the signature check at all. So "close
 * enough" matching in either direction sends a request somewhere it was never
 * meant to go. The socket id lives here too: its format is a wire contract,
 * and its random half is what stops one client from signing an auth string for
 * another client's connection.
 */

test('it reads the app key out of the websocket path it builds', function () {
    expect(PusherPaths::websocketPath('/app', 'local-key'))->toBe('/app/local-key')
        ->and(PusherPaths::appKeyFromWebsocketPath('/app/local-key', '/app'))->toBe('local-key')
        ->and(PusherPaths::isWebsocketPath('/app/local-key', '/app'))->toBeTrue();
});

test('it reads and writes a caller supplied path prefix', function () {
    // The prefix is an argument, not something this class looks up: these are
    // pure functions of their inputs, so a caller with a configured prefix
    // passes it in and nothing here reads global state.
    expect(PusherPaths::websocketPath('/realtime', 'local-key'))->toBe('/realtime/local-key')
        ->and(PusherPaths::appKeyFromWebsocketPath('/realtime/local-key', '/realtime'))->toBe('local-key')
        ->and(PusherPaths::appKeyFromWebsocketPath('/app/local-key', '/realtime'))->toBeNull();
});

test('it normalizes a prefix that is empty or trailing slashed', function () {
    expect(PusherPaths::websocketPath('/realtime/', 'local-key'))->toBe('/realtime/local-key')
        ->and(PusherPaths::appKeyFromWebsocketPath('/realtime/local-key', '/realtime/'))->toBe('local-key')
        ->and(PusherPaths::websocketPath('', 'local-key'))->toBe('/app/local-key')
        ->and(PusherPaths::appKeyFromWebsocketPath('/app/local-key', ''))->toBe('local-key')
        ->and(PusherPaths::appKeyFromWebsocketPath('/app/local-key', null))->toBe('local-key');
});

test('it refuses websocket paths that only look like the pusher path', function () {
    expect(PusherPaths::isWebsocketPath('/app', '/app'))->toBeFalse()
        ->and(PusherPaths::isWebsocketPath('/app/', '/app'))->toBeFalse()
        ->and(PusherPaths::isWebsocketPath('/app/key/extra', '/app'))->toBeFalse()
        ->and(PusherPaths::isWebsocketPath('/apps/key', '/app'))->toBeFalse()
        ->and(PusherPaths::isWebsocketPath('', '/app'))->toBeFalse()
        ->and(PusherPaths::isWebsocketPath(null, '/app'))->toBeFalse();
});

test('it reads the app id out of the publish path it builds', function () {
    expect(PusherPaths::eventsPath('12345'))->toBe('/apps/12345/events')
        ->and(PusherPaths::appIdFromEventsPath('/apps/12345/events'))->toBe('12345')
        ->and(PusherPaths::isEventsPath('/apps/12345/events'))->toBeTrue();
});

test('it refuses publish paths that only look like the events endpoint', function () {
    expect(PusherPaths::isEventsPath('/apps/12345/events/extra'))->toBeFalse()
        ->and(PusherPaths::isEventsPath('/apps//events'))->toBeFalse()
        ->and(PusherPaths::isEventsPath('/apps/12345/batch_events'))->toBeFalse()
        ->and(PusherPaths::isEventsPath('/healthz'))->toBeFalse()
        ->and(PusherPaths::isEventsPath(null))->toBeFalse();
});

test('it mints a socket id that names the connection', function () {
    expect(SocketId::generate(7))->toMatch('/^7\.\d{18}$/')
        // The wire contract, which is the reason the suffix cannot simply be
        // made as wide as PHP will go.
        ->and(\Lightspeed\Protocol\SubscriptionAuthorizer::isValidSocketId(SocketId::generate(7)))->toBeTrue();
});

/**
 * The security half, and it used to be worth nothing.
 *
 * The old test was `expect(count(array_unique($ids)))->toBeGreaterThan(1)` over
 * fifty draws, it required TWO DISTINCT VALUES. Replacing the generator with a
 * strictly alternating one-bit counter (0, 1, 0, 1, …) satisfied it, and so
 * would any generator with two possible outputs. A socket id is signed into
 * every subscription auth string, so what was being asserted at that strength
 * was the unpredictability of a credential.
 *
 * Each assertion below kills a different degenerate generator, and none of them
 * can flake: the weakest is "200 fair draws from a 2^60 space collide", at
 * roughly one run in 10^13.
 */
test('a socket id suffix is not guessable from the connection it names', function () {
    $suffixes = array_map(
        static fn () => (int) explode('.', SocketId::generate(7))[1],
        range(1, 200),
    );

    // A counter, a two-value alternator, a stuck value: all repeat.
    expect(array_unique($suffixes))->toHaveCount(200);

    // A monotonic counter is all-distinct, so distinctness alone is not enough.
    $sortedAscending = $suffixes;
    sort($sortedAscending);
    $sortedDescending = array_reverse($sortedAscending);

    expect($suffixes)->not->toBe($sortedAscending)
        ->and($suffixes)->not->toBe($sortedDescending);

    // And the draws have to be spread across the space that was promised, not
    // clustered in a corner of it. Which is what a generator that kept the old
    // narrow window while widening the format would look like.
    $span = max($suffixes) - min($suffixes);
    $space = SocketId::MAX_SUFFIX - SocketId::MIN_SUFFIX;

    expect($span)->toBeGreaterThan((int) ($space * 0.5));

    // The strongest of the four: every low bit is observed both set and clear.
    // A fair bit fails this with probability 2^-199, and an alternating counter,
    // a stuck bit, or any generator with a small period fails it immediately.
    for ($bit = 0; $bit < 48; $bit++) {
        $mask = 1 << $bit;
        $set = 0;

        foreach ($suffixes as $suffix) {
            if (($suffix & $mask) !== 0) {
                $set++;
            }
        }

        expect($set)->toBeGreaterThan(0, "bit {$bit} of the socket id suffix is never set")
            ->and($set)->toBeLessThan(200, "bit {$bit} of the socket id suffix is never clear");
    }
});

/**
 * The space itself, asserted as a number rather than left implicit in a call.
 *
 * Widening the format while leaving the range narrow would satisfy every
 * statistical check above on its own terms, because they are all relative to
 * the declared bounds. This is the one that says how big the declaration is.
 */
test('the socket id space is large enough to be worth calling unguessable', function () {
    $space = SocketId::MAX_SUFFIX - SocketId::MIN_SUFFIX;

    // Just under 2^60. The floor is stated as 2^59 so that a future widening
    // does not fail this and a narrowing does.
    expect($space)->toBeGreaterThan(2 ** 59);
});
