<?php

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Relay\RedisRelay;

/**
 * The record that decides every message: when each tag was last revoked.
 *
 * Everything here is either a refusal or a clock. The refusals have to fire on
 * a reply that does not answer the question (a truncated MGET, a TIME that is
 * not two numbers, an instant of zero), because a read that fails open is the
 * one failure this design exists to remove. The clock has to be Redis's, to
 * the microsecond, because "was this tag revoked after this grant was issued"
 * spans two processes that may not agree about the time.
 */

/** A relay that records what would have been published to peer workers. */
class AuthMutRecordingRelay extends RedisRelay
{
    /** @var list<array{0: string, 1: array}> control type and payload, in order */
    public array $published = [];

    public function __construct()
    {
    }

    public function publishControl(string $type, array $payload): void
    {
        $this->published[] = [$type, $payload];
    }
}

/** A Redis connection that answers exactly what a test wants to see handled. */
class AuthMutFakeRedisConnection
{
    public function __construct(
        public mixed $timeReply = null,
        public mixed $evalReply = null,
        public mixed $mgetReply = null,
    ) {
    }

    public function time(): mixed
    {
        return $this->timeReply;
    }

    public function eval(mixed ...$arguments): mixed
    {
        return $this->evalReply;
    }

    public function mget(mixed $keys): mixed
    {
        return $this->mgetReply;
    }
}

/** Put a fixed reply behind every Redis call this class makes. */
function authMutFakeRedis(AuthMutFakeRedisConnection $connection): void
{
    Redis::shouldReceive('connection')->andReturn($connection);
}

function authMutRevocationLog(?RedisRelay $relay = null): RevocationLog
{
    return new RevocationLog(app(ConfigRepository::class), $relay ?? new AuthMutRecordingRelay());
}

function authMutTag(): string
{
    return 'authmut:'.bin2hex(random_bytes(8));
}

/** Take a dial out of the config entirely, so the code's own default decides. */
function authMutForgetLogDial(string $dial): void
{
    $auth = config('lightspeed.auth');
    unset($auth[$dial]);

    config()->set('lightspeed.auth', $auth);
}

test('a lifetime past the ceiling is refused by name, value and remedy', function () {
    // This refusal fires in php-fpm, in queue workers and in artisan, because
    // that is where mint() and revoke() run. Its whole job is to say which
    // dial, what it is set to, what the limit is and which environment
    // variable to change; a message that scrambles those is a 500 with no
    // remedy attached, in a tier nobody was watching.
    config()->set('lightspeed.auth.grant_lifetime_seconds', 31_536_001);

    try {
        RevocationLog::validateConfiguration(app(ConfigRepository::class));

        $this->fail('an over-ceiling grant lifetime was accepted');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe(
            'Lightspeed cannot run: [lightspeed.auth.grant_lifetime_seconds] is 31536001, '
            .'which is longer than the 31536000 second ceiling. '
            .'Set LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS to a value in seconds. '
            .'It is handed to Redis as an expiry when a grant is minted, so a value Redis will '
            .'not accept fails every tagged /broadcasting/auth request instead of failing here.'
        );
    }
});

test('a lifetime that is not a number is read as a number, not compared as text', function () {
    // PHP compares a non-numeric string against an integer as a string, and
    // "abc" is greater than "31536000". Without the cast this boot check would
    // refuse to start every tier of an application whose dial arrived as text.
    config()->set('lightspeed.auth.grant_lifetime_seconds', 'abc');

    RevocationLog::validateConfiguration(app(ConfigRepository::class));

    expect(true)->toBeTrue();
});

test('the shipped dials are a 300 second grant and a 3600 second retention floor', function () {
    authMutForgetLogDial('grant_lifetime_seconds');
    authMutForgetLogDial('revocation_retention_floor_seconds');

    $log = authMutRevocationLog();

    expect($log->grantLifetimeSeconds())->toBe(300)
        ->and($log->revocationRetentionFloorSeconds())->toBe(3600);
});

test('both dials floor rather than reaching Redis with a value it rejects', function () {
    // A zero or negative expiry is an error from Redis, not a short key, and
    // it would arrive on the mint path in php-fpm. The retention floor's own
    // floor is 2 because it is compared against a doubled lifetime.
    config()->set('lightspeed.auth.grant_lifetime_seconds', 0);
    config()->set('lightspeed.auth.revocation_retention_floor_seconds', 0);

    expect(authMutRevocationLog()->grantLifetimeSeconds())->toBe(1)
        ->and(authMutRevocationLog()->revocationRetentionFloorSeconds())->toBe(2);

    config()->set('lightspeed.auth.grant_lifetime_seconds', 1);
    config()->set('lightspeed.auth.revocation_retention_floor_seconds', 2);

    expect(authMutRevocationLog()->grantLifetimeSeconds())->toBe(1)
        ->and(authMutRevocationLog()->revocationRetentionFloorSeconds())->toBe(2);

    config()->set('lightspeed.auth.grant_lifetime_seconds', 'abc');
    config()->set('lightspeed.auth.revocation_retention_floor_seconds', 'abc');

    expect(authMutRevocationLog()->grantLifetimeSeconds())->toBe(1)
        ->and(authMutRevocationLog()->revocationRetentionFloorSeconds())->toBe(2);
});

test('a revocation is published to peer workers with its tag and its instant', function () {
    // The peers cannot re-derive either: the tag says which connections, and
    // the instant is what keeps a connection that re-subscribed AFTER the
    // revoke subscribed. A control entry missing one of them is a peer that
    // drops the wrong connections or none.
    $relay = new AuthMutRecordingRelay();
    $tag = authMutTag();

    $revokedAt = authMutRevocationLog($relay)->revoke($tag);

    expect($relay->published)->toHaveCount(1)
        ->and($relay->published[0][0])->toBe(RevocationLog::CONTROL_TYPE)
        ->and($relay->published[0][1])->toBe(['tag' => $tag, 'revoked_at' => $revokedAt]);
});

test('a revoke with no high-water mark to read says what it fell back to', function () {
    // The mark is how one process learns what lifetime another minted under,
    // and its absence is invisible from here: the revocation looks correct and
    // lapses early. The warning is the only notice anybody gets, so it has to
    // carry both numbers the retention actually fell back to.
    Redis::connection()->del(RevocationLog::HIGH_WATER_KEY);

    config()->set('lightspeed.auth.grant_lifetime_seconds', 111);
    config()->set('lightspeed.auth.revocation_retention_floor_seconds', 222);

    Log::spy();

    $tag = authMutTag();
    authMutRevocationLog()->revoke($tag);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($tag): bool {
        return $context === [
            'tag' => $tag,
            'lifetime_seconds' => 111,
            'floor_seconds' => 222,
        ];
    })->once();
});

test('a revocation Redis did not stamp is an exception, not a returned zero', function () {
    // The revoke has to have landed before anything else happens, so an
    // unreadable or absent instant is a failure the caller must see.
    authMutFakeRedis(new AuthMutFakeRedisConnection(evalReply: '0:1'));

    expect(fn () => authMutRevocationLog()->revoke(authMutTag()))
        ->toThrow(RuntimeException::class, 'could not write the revocation');
});

test('a stamp of one is a stamp, however unlikely a clock it implies', function () {
    // The refusal above is about a stamp that is missing, not about a stamp
    // that is small: the boundary is zero, and moving it upward would turn a
    // successful revocation into an exception that tempts a caller to retry a
    // thing that worked.
    $relay = new AuthMutRecordingRelay();

    authMutFakeRedis(new AuthMutFakeRedisConnection(evalReply: '1:1'));

    expect(authMutRevocationLog($relay)->revoke(authMutTag()))->toBe(1)
        ->and($relay->published)->toHaveCount(1);
});

test('an empty tag is refused before Redis is asked to revoke it', function () {
    expect(fn () => authMutRevocationLog()->revoke(''))
        ->toThrow(InvalidArgumentException::class, 'cannot revoke an empty tag');
});

test('a revoked tag is found even when an earlier tag on the grant was never revoked', function () {
    // The MGET answers in key order and a tag that has never been revoked has
    // no key at all, which is the COMMON case: stopping the scan at the first
    // of them would mean only a grant whose FIRST tag was revoked is ever
    // refused. A fail-open that reads as a tidy early exit.
    $log = authMutRevocationLog();

    $quiet = authMutTag();
    $revoked = authMutTag();

    $grant = new Grant([$quiet, $revoked], [], $log->now(), $log->now() + 300_000_000);

    $log->revoke($revoked);

    expect($log->isRevoked($grant))->toBeTrue();
});

test('a grant issued after the revocation is left alone', function () {
    $log = authMutRevocationLog();

    $tag = authMutTag();
    $log->revoke($tag);

    $grant = new Grant([$tag], [], $log->now() + 1, $log->now() + 300_000_000);

    expect($log->isRevoked($grant))->toBeFalse();
});

test('a TIME reply that is not two numbers refuses rather than inventing a clock', function () {
    // A grant with an untrustworthy issue time is worse than no grant: it is
    // compared against every revocation instant ever written, and an issue
    // time of zero predates all of them.
    $malformed = [
        'not an array at all',
        ['abc', 5],
        [5, 'abc'],
        [5, 'abc', 7],
        [-1 => 5, 0 => 'abc', 1 => 5],
        [],
        [5],
    ];

    $connection = new AuthMutFakeRedisConnection();
    authMutFakeRedis($connection);

    foreach ($malformed as $reply) {
        $connection->timeReply = $reply;

        expect(fn () => authMutRevocationLog()->now())
            ->toThrow(RuntimeException::class, 'could not read the current time from Redis');
    }
});

test('the clock is seconds times a million plus microseconds', function () {
    // Microseconds, and the fractional part of a second is dropped rather than
    // scaled: the resolution IS the size of the window in which a mint and a
    // revoke cannot be ordered.
    $connection = new AuthMutFakeRedisConnection(timeReply: ['5', '7']);
    authMutFakeRedis($connection);

    expect(authMutRevocationLog()->now())->toBe(5_000_007);

    $connection->timeReply = ['5.9', '7'];

    expect(authMutRevocationLog()->now())->toBe(5_000_007);
});

test('minting records the lifetime it was minted under, floored at one second', function () {
    // The mark is what a LATER revoke reads to decide how long to keep itself,
    // and it is handed to Redis as its own expiry. A zero there is an error
    // reply on the mint path, and rounding it up to two would keep the mark
    // alive past the grants it describes.
    Redis::connection()->del(RevocationLog::HIGH_WATER_KEY);

    $issuedAt = authMutRevocationLog()->noteGrantLifetime(0);

    expect($issuedAt)->toBeGreaterThan(1_500_000_000_000_000)
        ->and(Redis::connection()->get(RevocationLog::HIGH_WATER_KEY))->toBe('1');
});

test('a mint instant of zero refuses, and one of one is accepted', function () {
    // Same boundary as the revoke stamp, and the same reason: no instant at
    // all must not be minted into a grant, and a small one is not the same
    // observation as none.
    $connection = new AuthMutFakeRedisConnection(evalReply: '0');
    authMutFakeRedis($connection);

    expect(fn () => authMutRevocationLog()->noteGrantLifetime(300))
        ->toThrow(RuntimeException::class, 'could not read the current time from Redis');

    $connection->evalReply = '1';

    expect(authMutRevocationLog()->noteGrantLifetime(300))->toBe(1);
});

test('a connection name that is not a string still comes back as one', function () {
    // The diagnostic asks this for the name to report on, and the fallback
    // chain runs through whatever the application's config file holds.
    config()->set('lightspeed.auth.redis_connection', null);
    config()->set('lightspeed.relay.redis_connection', null);

    expect(RevocationLog::connectionNameFor(app(ConfigRepository::class)))->toBe('');

    config()->set('lightspeed.auth.redis_connection', 7);

    expect(RevocationLog::connectionNameFor(app(ConfigRepository::class)))->toBe('7');
});
