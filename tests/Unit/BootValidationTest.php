<?php

use Lightspeed\Server;

/**
 * S2. An empty `app_secret` makes every private and presence channel forgeable,
 * and the server used to boot without a word about it.
 *
 * The secret is the whole of channel security on the websocket surface: an auth
 * string is `key:hash_hmac('sha256', "socketId:channel", $secret)`, so with the
 * secret empty anybody can compute one for any channel and land in a private
 * channel's fan-out. The same empty key makes the relay's control entries. the
 * ones 221f2d6 signed precisely so Redis credentials would not be enough to
 * force connections off a channel, forgeable by anyone.
 *
 * It WAS validated, but only where the broadcaster is resolved, which is the
 * first `/broadcasting/auth` request. `Server::serve()` validated handler class
 * names and nothing else, and both `Server::pusherAppSecret()` and
 * `RedisRelay::appSecret()` defaulted to `''` with no check at all. So a server
 * whose secret env var was missing, misspelled or emptied started, listened,
 * and accepted forged subscriptions until somebody happened to authorize a
 * channel.
 *
 * The check belongs at boot, where an operator is watching.
 */

/** Call one of the server's private methods, wherever it lives. */
function driveBoot(Server $server, string $method, array $arguments = []): mixed
{
    return driveLightspeed($server, $method, $arguments);
}

test('serve refuses to start when the app secret is empty', function () {
    config()->set('lightspeed.reverb_compat.app_secret', '');

    // A handler class that does not exist, so that if the credential check were
    // ever removed this test still fails on the handler validation rather than
    // reaching `new Swoole\WebSocket\Server` and binding a port. The message is
    // what distinguishes the two.
    config()->set('lightspeed.client_event_handlers', ['Lightspeed\\Tests\\NoSuchHandler']);

    expect(fn () => app(Server::class)->serve('127.0.0.1', 0))
        ->toThrow(RuntimeException::class, 'app_secret');
});

test('serve refuses to start when the app key is empty', function () {
    config()->set('lightspeed.reverb_compat.app_key', '');
    config()->set('lightspeed.client_event_handlers', ['Lightspeed\\Tests\\NoSuchHandler']);

    expect(fn () => app(Server::class)->serve('127.0.0.1', 0))
        ->toThrow(RuntimeException::class, 'app_key');
});

test('the credential check passes on a configured application', function () {
    // The positive half. Without it a validator that threw unconditionally
    // would satisfy both assertions above.
    expect(fn () => driveBoot(app(Server::class), 'validateCredentials'))->not->toThrow(RuntimeException::class);
});

test('the credential message names the env vars an operator would set', function () {
    config()->set('lightspeed.reverb_compat.app_secret', '');

    try {
        driveBoot(app(Server::class), 'validateCredentials');
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('LIGHTSPEED_APP_SECRET')
        ->and($message)->toContain('REVERB_APP_SECRET');
});

/**
 * A10. The floor-versus-own-lifetime boot refusal was aimed at something that
 * cannot happen, and refused configurations that are safe.
 *
 * REVOKE_SCRIPT computes `ttl = max(max(ARGV[1], mark) * 2, floor)`, and ARGV[1]
 * is the REVOKING process's own dial. So a process's own grants are covered by
 * `own dial * 2` whatever the floor says, and the floor is consulted only when
 * it is larger than that. `floor >= own dial * 2` is therefore precisely the
 * condition under which the floor is dead code, and refusing to boot without it
 * cannot make anything safer.
 *
 * Measured against real Redis with the Lua verbatim, mark deleted:
 *
 *   own dial 7200, floor 3600 -> revocation TTL 14400   (the docblock said 3600)
 *   own dial  300, floor 3600 -> revocation TTL  3600
 *
 * The second line is the configuration the check PASSED, and it is the holed
 * one: a peer still minting 7200s grants keeps them live for an hour after the
 * revocation has lapsed. The first line is the configuration it REFUSED, and it
 * was never in danger from its own grants.
 *
 * The real invariant is about the longest lifetime anywhere in the fleet, which
 * one process cannot see at boot. It is stated in the docs as a residual, and
 * `revoke()` already logs the moment it goes live: the pass where there was no
 * high-water mark to read.
 */
test('a grant lifetime longer than half the retention floor is not a reason to refuse a server', function () {
    // The shipped floor default is 3600, so before this every deployment that
    // raised the lifetime past 1800 without also raising the floor could not
    // start at all.
    config()->set('lightspeed.auth.grant_lifetime_seconds', 7200);
    config()->set('lightspeed.auth.revocation_retention_floor_seconds', 3600);

    expect(fn () => driveBoot(app(Server::class), 'validateGrantLifetime'))->not->toThrow(RuntimeException::class);
});

/**
 * A10b. All three boot refusals live in `Server::serve()`, and nothing else
 * calls it.
 *
 * `GrantManager` is a plain container singleton, so `Lightspeed::revoke()` and
 * `Lightspeed::tag()` run in queue workers, artisan commands, the scheduler and
 * php-fpm, none of which serve(). A `grant_lifetime_seconds` Redis will not
 * accept therefore 500'd every tagged /broadcasting/auth in production with
 * nothing having been said anywhere, which is the exact failure the ceiling was
 * added to prevent.
 *
 * The check now lives on the object that reads the dial, so it fires the first
 * time the grant machinery is resolved on ANY tier. serve() still calls it too,
 * so a misconfigured server aborts the command before the listener exists.
 */
test('the grant lifetime ceiling is enforced in a tier that never calls serve', function () {
    config()->set('lightspeed.auth.grant_lifetime_seconds', 1_000_000_000_000);

    expect(fn () => app(\Lightspeed\Auth\RevocationLog::class))
        ->toThrow(RuntimeException::class, 'grant_lifetime_seconds');
});

/**
 * THE CEILING'S OWN EDGE. `>` and `>=` differ on exactly one value, and the
 * ceiling is a documented constant rather than a clock, so that value can be
 * named.
 *
 * Which side the edge falls on is a real decision and not a detail: the number
 * is stated in the message an operator reads ("longer than the 31536000 second
 * ceiling"), and a `>=` would make the largest lifetime the docs say is allowed
 * the one that refuses to boot. A year is far past where a backstop is a
 * backstop and comfortably inside the expiry range Redis accepts, so it is
 * ALLOWED, and the second past it is not.
 */
test('the grant lifetime ceiling admits exactly the ceiling and refuses the second past it', function () {
    config()->set('lightspeed.auth.grant_lifetime_seconds', \Lightspeed\Auth\RevocationLog::MAX_GRANT_LIFETIME_SECONDS);

    expect(fn () => \Lightspeed\Auth\RevocationLog::validateConfiguration(config()))
        ->not->toThrow(RuntimeException::class);

    config()->set('lightspeed.auth.grant_lifetime_seconds', \Lightspeed\Auth\RevocationLog::MAX_GRANT_LIFETIME_SECONDS + 1);

    expect(fn () => \Lightspeed\Auth\RevocationLog::validateConfiguration(config()))
        ->toThrow(RuntimeException::class, 'grant_lifetime_seconds');
});

test('resolving the grant machinery on a sane configuration does not throw', function () {
    // The positive half. Without it a validator that threw unconditionally
    // would satisfy the assertion above while breaking every tier.
    expect(fn () => app(\Lightspeed\Auth\RevocationLog::class))->not->toThrow(RuntimeException::class);
});

/**
 * F9. `grant_lifetime_seconds` is unvalidated at the top end.
 *
 * `max(1, ...)` guards only the floor. The value is handed to Redis as an `EX`
 * expiry inside MINT_SCRIPT, on the /broadcasting/auth path, so a value Redis
 * rejects turns every tagged channel authorization into a 500, at mint time,
 * in production, with nothing having said anything at boot.
 */
test('serve refuses to start when the grant lifetime is longer than an expiry means anything', function () {
    config()->set('lightspeed.auth.grant_lifetime_seconds', 1_000_000_000_000);
    config()->set('lightspeed.auth.revocation_retention_floor_seconds', 9_000_000_000_000);
    config()->set('lightspeed.client_event_handlers', ['Lightspeed\\Tests\\NoSuchHandler']);

    expect(fn () => app(Server::class)->serve('127.0.0.1', 0))
        ->toThrow(RuntimeException::class, 'grant_lifetime_seconds');
});

test('the shipped defaults satisfy the grant lifetime check', function () {
    // The positive half. Without it a validator that threw unconditionally
    // would satisfy both assertions above while refusing to start any server.
    expect(fn () => driveBoot(app(Server::class), 'validateGrantLifetime'))->not->toThrow(RuntimeException::class);
});

/**
 * The handler check's POSITIVE half, which nothing was watching.
 *
 * `validateHandlerConfiguration()` has three refusals and every one of them was
 * tested; that a correctly configured handler PASSES was not. Replacing
 *
 *     if (!class_exists($handlerClass)) {
 *
 * with `if (true)`, so that every handler class is reported missing, left the
 * entire suite green. That mutant refuses to start any server whose application
 * configured a client-event or owner-command handler at all, which is every
 * application the feature exists for, and the refusal it prints names a class
 * that is sitting right there in the autoloader.
 *
 * A validator is two claims, not one, and a suite that only ever tests the
 * refusals is testing `throw new RuntimeException` with extra steps.
 */
final class BootValidationFixtureClientEventHandler implements \Lightspeed\Contracts\ClientEventHandler
{
    public function handle(\Lightspeed\ClientEvents\ClientEvent $event): ?\Lightspeed\ClientEvents\ClientEventResult
    {
        return null;
    }
}

final class BootValidationFixtureOwnerCommandHandler implements \Lightspeed\Contracts\OwnerCommandHandler
{
    public function handle(\Lightspeed\Owner\OwnerCommand $command): ?array
    {
        return null;
    }
}

test('a handler that exists and implements the contract is accepted', function () {
    config()->set('lightspeed.client_event_handlers', [BootValidationFixtureClientEventHandler::class]);
    config()->set('lightspeed.owner_command_handlers', [BootValidationFixtureOwnerCommandHandler::class]);

    expect(fn () => driveBoot(app(Server::class), 'validateHandlerConfiguration'))
        ->not->toThrow(RuntimeException::class);
});

test('a handler list of several real handlers is accepted, not just a list of one', function () {
    // `class_exists()` is inside a loop over the configured list, so a check
    // that only ever saw a single-element list would not notice a mutant that
    // refuses on the second iteration.
    config()->set('lightspeed.client_event_handlers', [
        BootValidationFixtureClientEventHandler::class,
        BootValidationFixtureClientEventHandler::class,
    ]);

    expect(fn () => driveBoot(app(Server::class), 'validateHandlerConfiguration'))
        ->not->toThrow(RuntimeException::class);
});

test('the three refusals still refuse, so the acceptance above is a judgement', function () {
    // The negative half, restated here beside the positive one: a validator
    // tested only from one side is a validator that can be replaced with a
    // constant in either direction.
    $cases = [
        // Not a class name at all.
        [['' ], 'must contain handler class names'],
        // A class name that no autoloader can resolve.
        [['Lightspeed\\Tests\\NoSuchHandlerAtAll'], 'does not exist'],
        // A real class that does not implement the contract.
        [[BootValidationFixtureOwnerCommandHandler::class], 'must implement'],
    ];

    foreach ($cases as [$handlers, $expected]) {
        config()->set('lightspeed.client_event_handlers', $handlers);

        expect(fn () => driveBoot(app(Server::class), 'validateHandlerConfiguration'))
            ->toThrow(RuntimeException::class, $expected);
    }
});

test('an empty handler list is not a misconfiguration', function () {
    // The shipped default. An application that uses neither feature must still
    // be able to start.
    config()->set('lightspeed.client_event_handlers', []);
    config()->set('lightspeed.owner_command_handlers', []);

    expect(fn () => driveBoot(app(Server::class), 'validateHandlerConfiguration'))
        ->not->toThrow(RuntimeException::class);
});
