<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\RevocationDrops;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Channels\Subscriptions;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The receiving half of a revocation, and the budget that keeps it from
 * stopping the worker.
 *
 * Nothing here decides whether a message is allowed; that is one Redis read on
 * every frame. What this decides is how quickly a revoked connection stops
 * RECEIVING, and the failure modes are on both sides of that: a control entry
 * whose tag or instant is missing must drop NOTHING (dropping on a malformed
 * entry unsubscribes users nobody revoked), and a tag with more connections
 * than one pass may unwind must leave the remainder to the sweeper rather than
 * spending ten thousand blocking round trips inside one tick.
 */

/** A relay whose control listeners can be called by hand. */
class AuthMutDropsRelay extends RedisRelay
{
    /** @var array<string, callable> */
    public array $control = [];

    public function __construct()
    {
    }

    public function onControl(string $type, callable $listener): void
    {
        $this->control[$type] = $listener;
    }
}

/** A revocation log that hands back its listeners instead of touching Redis. */
class AuthMutDropsRevocationLog extends RevocationLog
{
    /** @var array<string, callable> */
    public array $listeners = [];

    public function __construct()
    {
    }

    public function onRevoked(string $name, callable $listener): void
    {
        $this->listeners[$name] = $listener;
    }
}

/** Unsubscribing, without the Redis half; optionally the failing kind. */
class AuthMutDropsSubscriptions extends Subscriptions
{
    /** @var list<array{0: int, 1: string}> */
    public array $dropped = [];

    public bool $explode = false;

    public function __construct(private readonly ConnectionGrants $grants)
    {
    }

    public function dropSubscription(int $fd, string $channel): void
    {
        if ($this->explode) {
            throw new \RuntimeException('presence store is unreachable');
        }

        $this->dropped[] = [$fd, $channel];
        $this->grants->forget($fd, $channel);
    }
}

/** The stale frame, recorded rather than written to a socket. */
class AuthMutDropsDelivery extends Delivery
{
    /** @var list<array{0: int, 1: string, 2: string}> */
    public array $stale = [];

    public function __construct()
    {
    }

    public function pushStale(SwooleServer $server, int $fd, string $channel, string $reason): void
    {
        $this->stale[] = [$fd, $channel, $reason];
    }
}

class AuthMutDropsLogger extends RuntimeLogger
{
    /** @var list<array{0: string, 1: array}> */
    public array $lines = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->lines[] = [$action, $fields];
    }
}

class AuthMutDropsServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }
}

/**
 * A revocation drain wired to doubles, with the pieces a test asserts on.
 *
 * @return array{0: RevocationDrops, 1: ConnectionGrants, 2: AuthMutDropsSubscriptions, 3: AuthMutDropsLogger, 4: AuthMutDropsRelay, 5: AuthMutDropsRevocationLog}
 */
function authMutDropsHarness(): array
{
    $grants = new ConnectionGrants();
    $subscriptions = new AuthMutDropsSubscriptions($grants);
    $logger = new AuthMutDropsLogger();
    $relay = new AuthMutDropsRelay();
    $revocations = new AuthMutDropsRevocationLog();

    $drops = new RevocationDrops(
        $grants,
        $revocations,
        $relay,
        $subscriptions,
        new AttachedServer(),
        new AuthMutDropsDelivery(),
        $logger,
    );

    $drops->bootRevocationListeners(AuthMutDropsServer::make());

    return [$drops, $grants, $subscriptions, $logger, $relay, $revocations];
}

function authMutDropsGrant(array $tags, int $issuedAt): Grant
{
    return new Grant($tags, [], $issuedAt, $issuedAt + 300_000_000);
}

test('a revocation arriving over the relay drops the connections carrying it', function () {
    [$drops, $grants, $subscriptions, , $relay] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['project:7'], 1_000));
    $grants->mint(12, 'private-a', authMutDropsGrant(['project:9'], 1_000));

    ($relay->control[RevocationLog::CONTROL_TYPE])(['tag' => 'project:7', 'revoked_at' => 2_000]);

    expect($subscriptions->dropped)->toBe([[11, 'private-a']]);
});

test('a control entry with no usable tag or instant drops nothing at all', function () {
    // Both halves have to hold before anything is unsubscribed. An entry that
    // lost its instant would otherwise be applied as instant zero, which
    // matches no grant, or as "now", which drops the connections that just
    // re-authorized; and an empty tag matches whatever an empty tag matches.
    [$drops, $grants, $subscriptions, , $relay] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant([''], 0));
    $grants->mint(12, 'private-a', authMutDropsGrant(['project:7'], 0));

    $apply = $relay->control[RevocationLog::CONTROL_TYPE];

    $apply(['tag' => '', 'revoked_at' => 2_000]);
    $apply(['tag' => 'project:7', 'revoked_at' => 'soon']);
    $apply(['tag' => 'project:7']);
    $apply(['revoked_at' => 2_000]);
    $apply(['tag' => ['project:7'], 'revoked_at' => 2_000]);

    expect($subscriptions->dropped)->toBe([]);
});

test('a numeric tag from the relay still reaches the connections carrying it', function () {
    // "7" makes the round trip through JSON as a value rather than an object
    // key, so it arrives as a string here, but a guard that insisted on one
    // made revoke a permanent no-op for numeric tags on a live server.
    [$drops, $grants, $subscriptions, , $relay] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['7'], 1_000));

    ($relay->control[RevocationLog::CONTROL_TYPE])(['tag' => 7, 'revoked_at' => 2_000]);

    expect($subscriptions->dropped)->toBe([[11, 'private-a']]);
});

test('a revocation this process served drops its own connections too', function () {
    // The relay deliberately never replays a process's own publishes back to
    // it, so without this listener the worker serving revoke() is the one
    // worker that keeps fanning out to the revoked connection.
    [$drops, $grants, $subscriptions, , , $revocations] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['project:7'], 1_000));

    ($revocations->listeners['server'])('project:7', 2_000);

    expect($subscriptions->dropped)->toBe([[11, 'private-a']]);
});

test('a tag with more connections than the budget defers the remainder and says so', function () {
    // The unit of work is a blocking Redis round trip on an event loop with no
    // coroutine to yield to. Revoking one popular tag was ten thousand of them
    // inside a single relay tick, which is the whole server answering nothing;
    // what is left over is handed to the sweeper, not dropped.
    config()->set('lightspeed.auth.sweep_max_per_tick', 1);

    [$drops, $grants, $subscriptions, $logger] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['project:7'], 1_000));
    $grants->mint(12, 'private-a', authMutDropsGrant(['project:7'], 1_000));
    $grants->mint(13, 'private-a', authMutDropsGrant(['project:7'], 1_000));

    $drops->dropConnectionsCarrying('project:7', 2_000);

    // The first connections in the index, not a window further along it: the
    // rest are reached by the drain, and one skipped here would be dropped
    // twice while another was never dropped at all.
    expect($subscriptions->dropped)->toBe([[11, 'private-a']])
        ->and($logger->lines)->toBe([
            ['revocation-drop-deferred', ['tag' => 'project:7', 'remaining' => 2]],
        ]);

    // And the remainder really is deferred rather than forgotten.
    expect($drops->drainPendingRevocations(10))->toBe(2)
        ->and($subscriptions->dropped)->toBe([[11, 'private-a'], [12, 'private-a'], [13, 'private-a']]);
});

test('the backlog keeps the latest instant a tag was revoked at', function () {
    // One entry per tag is the whole backlog however many times it is revoked,
    // which only holds if the entry is the LATEST instant: a second revoke
    // covers every grant the first did, and a lower instant would leave the
    // grants issued between them subscribed.
    [$drops, $grants, $subscriptions] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['project:7'], 300));

    $drops->deferRevocation('project:7', 500);
    $drops->deferRevocation('project:7', 100);

    expect($drops->drainPendingRevocations(10))->toBe(1)
        ->and($subscriptions->dropped)->toBe([[11, 'private-a']]);
});

test('the backlog neither invents an instant nor floors one it was given', function () {
    // The fallback is only for a tag with no entry yet, and it is zero: raise
    // it and a deferred revocation reaches grants issued after it, lower it
    // and one at the very start of the clock reaches none.
    [$drops, $grants, $subscriptions] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['later'], 1));
    $grants->mint(12, 'private-b', authMutDropsGrant(['earlier'], 0));

    $drops->deferRevocation('later', 0);
    $drops->deferRevocation('earlier', -1);

    expect($drops->drainPendingRevocations(10))->toBe(1)
        ->and($subscriptions->dropped)->toBe([[12, 'private-b']]);
});

test('a drain spends its budget once across every tag in the backlog', function () {
    // ONE budget for the tick, not one per tag: the ceiling exists because the
    // tick is a blocking event loop, and a per-tag ceiling would not bound it.
    [$drops, $grants, $subscriptions] = authMutDropsHarness();

    $grants->mint(11, 'private-a', authMutDropsGrant(['alpha'], 1_000));

    foreach ([12, 13, 14] as $fd) {
        $grants->mint($fd, 'private-b', authMutDropsGrant(['beta'], 1_000));
    }

    $drops->deferRevocation('alpha', 2_000);
    $drops->deferRevocation('beta', 2_000);

    expect($drops->drainPendingRevocations(2))->toBe(2)
        ->and($subscriptions->dropped)->toBe([[11, 'private-a'], [12, 'private-b']]);

    // alpha finished, because it dropped fewer than it was allowed to; beta
    // spent everything it was allowed and is therefore still owed a pass.
    expect($drops->drainPendingRevocations(10))->toBe(2)
        ->and($subscriptions->dropped)->toHaveCount(4);
});

test('a drop whose cleanup fails still takes the connection out of the fan-out', function () {
    // Dropping a PRESENCE subscription is a Redis call, and one connection
    // whose cleanup throws must not cost every connection after it its drop,
    // nor its own client the frame that makes it re-authorize.
    [$drops, $grants, $subscriptions, $logger] = authMutDropsHarness();

    $subscriptions->explode = true;

    $grants->mint(11, 'private-a', authMutDropsGrant(['project:7'], 1_000));
    $grants->mint(12, 'private-a', authMutDropsGrant(['project:7'], 1_000));

    $drops->dropConnectionsCarrying('project:7', 2_000);

    // Both failures are reported, and each line names the connection it is
    // about: a log that says only that a drop failed cannot be matched to the
    // subscription that is now in an unknown state.
    expect($logger->lines)->toBe([
        ['error', [
            'fd' => 11,
            'channel' => 'private-a',
            'reason' => 'revocation-drop-failed',
            'message' => 'presence store is unreachable',
        ]],
        ['error', [
            'fd' => 12,
            'channel' => 'private-a',
            'reason' => 'revocation-drop-failed',
            'message' => 'presence store is unreachable',
        ]],
    ]);
});
