<?php

use Lightspeed\Protocol\PusherPaths;

/**
 * The null every one of these functions is declared to accept.
 *
 * All four take `?string`, and they mean it: the websocket handler reads
 * `$request->server['request_uri']`, the HTTP router reads a path off a request
 * that may not carry one, and the prefix comes from config, where an
 * unset env var is null rather than a string. Each guard exists so that null
 * turns into "not one of these paths" here, at the top, rather than inside
 * preg_match() or rtrim().
 *
 * Handing null to a string parameter is a deprecation today and a TypeError in
 * a future PHP, so "it happens to return the same answer" is not the property
 * worth pinning: not reaching those calls at all is. These tests therefore run
 * each probe with deprecations promoted to exceptions, which is the only way
 * the difference is visible from inside a test.
 */
function proto_mut_paths_strictly(callable $probe): mixed
{
    set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    }, E_DEPRECATED | E_WARNING);

    try {
        return $probe();
    } finally {
        restore_error_handler();
    }
}

test('a null path is refused as neither pusher path, without being handed to preg_match', function () {
    expect(proto_mut_paths_strictly(static fn () => PusherPaths::appKeyFromWebsocketPath(null, '/app')))->toBeNull()
        ->and(proto_mut_paths_strictly(static fn () => PusherPaths::isWebsocketPath(null, '/app')))->toBeFalse()
        ->and(proto_mut_paths_strictly(static fn () => PusherPaths::appIdFromEventsPath(null)))->toBeNull()
        ->and(proto_mut_paths_strictly(static fn () => PusherPaths::isEventsPath(null)))->toBeFalse();
});

test('a null prefix means the default prefix, without being handed to rtrim', function () {
    // `LIGHTSPEED_REVERB_PATH_PREFIX` unset, or written as `null`, is exactly
    // this. The path a client is told to dial has to come out of it anyway, and
    // it has to be the one the open handler will match.
    expect(proto_mut_paths_strictly(static fn () => PusherPaths::websocketPath(null, 'app-key')))
        ->toBe('/app/app-key');
});
