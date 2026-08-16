<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Workers\WorkerContext;

/**
 * The registry's edges: what it refuses to write, what it writes verbatim, how
 * long the cluster is asked to believe it, and which failures are worth a
 * second attempt.
 *
 * Every one of these is invisible from the outside in the ordinary case, which
 * is why they are worth pinning separately. A socket id that should have been
 * skipped produces a key nobody will ever delete; a TTL that is one dial short
 * lapses under a live connection and makes it unfindable; and a retry predicate
 * that has widened turns a genuine application error into two of them.
 */

function coreMutRegistry(): ConnectionRegistry
{
    return new ConnectionRegistry(app('config'), app(WorkerContext::class));
}

/** Run one command through the registry's private retry wrapper. */
function coreMutThroughRetry(ConnectionRegistry $registry, callable $command): mixed
{
    $handle = new ReflectionMethod(ConnectionRegistry::class, 'command');
    $handle->setAccessible(true);

    return $handle->invoke($registry, $command);
}

function coreMutRegistryKey(string $socketId): string
{
    return "lightspeed:connection:{$socketId}";
}

// ---------------------------------------------------------------------------
// What never becomes a key.
// ---------------------------------------------------------------------------

test('an empty socket id is never remembered, so no key is written that nothing will delete', function () {
    // forget() is keyed by the same socket id, so an entry written under an
    // empty one is an entry no close path can ever remove: it would sit there
    // being renewed by every sweep for as long as the worker lived.
    Redis::connection()->del(coreMutRegistryKey(''));

    coreMutRegistry()->remember('');

    expect(Redis::connection()->exists(coreMutRegistryKey('')))->toBe(0);
});

test('a refresh skips ids that are empty or not strings, and counts only what it wrote', function () {
    // The list comes from ChannelManager::heldSocketIds(), and the sweep runs
    // on the event loop serving every connection this worker has. A junk entry
    // that reaches the pipeline costs a write per tick, forever.
    $socketId = 'core-mut-refresh-'.getmypid();
    $registry = coreMutRegistry();

    Redis::connection()->del(coreMutRegistryKey(''), coreMutRegistryKey('123'), coreMutRegistryKey($socketId));

    $renewed = $registry->refresh(['', 123, $socketId]);

    expect($renewed)->toBe(1)
        ->and(Redis::connection()->exists(coreMutRegistryKey($socketId)))->toBe(1)
        ->and(Redis::connection()->exists(coreMutRegistryKey('')))->toBe(0)
        ->and(Redis::connection()->exists(coreMutRegistryKey('123')))->toBe(0);
});

test('a sweep with nothing to renew costs no round trip', function () {
    // The sweep runs on the single event loop that serves every connection this
    // worker holds, with `enable_coroutine` off by default, so a round trip is
    // a stall for all of them. A worker holding no sockets ticks every five
    // minutes forever and must not pay for it.
    Redis::spy();

    expect(coreMutRegistry()->refresh([]))->toBe(0);

    Redis::shouldNotHaveReceived('connection');
});

test('forgetting an empty socket id does not delete the key an empty id would have had', function () {
    // The same guard from the other direction. Without it the close path of one
    // connection with no socket id issues a DEL against a key name that any
    // other broken write would share.
    Redis::connection()->setex(coreMutRegistryKey(''), 60, 'sentinel');

    coreMutRegistry()->forget('');
    coreMutRegistry()->forget(null);

    expect(Redis::connection()->get(coreMutRegistryKey('')))->toBe('sentinel');

    Redis::connection()->del(coreMutRegistryKey(''));
});

// ---------------------------------------------------------------------------
// What is written, byte for byte.
// ---------------------------------------------------------------------------

test('the stored identity keeps slashes unescaped, on both the connect and the sweep write', function () {
    // The payload is read back by probes and by application code, and the JSON
    // flags are what decides whether an instance id containing a path separator
    // comes back as itself. JSON_THROW_ON_ERROR travels in the same expression:
    // dropping it turns an unencodable payload into a silent `false` written
    // to Redis in place of an identity.
    config()->set('lightspeed.server.instance_id', 'eu/west-1');

    $context = new WorkerContext(app('config'));
    $context->boot((new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor(), 0);

    $registry = new ConnectionRegistry(app('config'), $context);
    $socketId = 'core-mut-slash-'.getmypid();

    $registry->remember($socketId);
    $remembered = Redis::connection()->get(coreMutRegistryKey($socketId));

    $registry->refresh([$socketId]);
    $refreshed = Redis::connection()->get(coreMutRegistryKey($socketId));

    expect($remembered)->toContain('eu/west-1')
        ->and($remembered)->not->toContain('eu\\/west-1')
        ->and($refreshed)->toContain('eu/west-1')
        ->and($refreshed)->not->toContain('eu\\/west-1');

    Redis::connection()->del(coreMutRegistryKey($socketId));
});

test('the two writes are distinguishable, because only one of them is a renewal', function () {
    // `reason` is what tells a reader which write it is looking at: an entry
    // that says socket-connect is a claim from the handshake, one that says
    // socket-sweep is this process vouching for the socket now.
    $registry = coreMutRegistry();
    $socketId = 'core-mut-reason-'.getmypid();

    $registry->remember($socketId);
    expect($registry->metadata($socketId)['reason'])->toBe('socket-connect');

    $registry->refresh([$socketId]);
    expect($registry->metadata($socketId)['reason'])->toBe('socket-sweep');

    Redis::connection()->del(coreMutRegistryKey($socketId));
});

// ---------------------------------------------------------------------------
// How long the cluster is asked to believe it.
// ---------------------------------------------------------------------------

/** The TTL Redis actually attached to one remembered socket. */
function coreMutObservedTtl(): int
{
    $socketId = 'core-mut-ttl-'.getmypid();

    coreMutRegistry()->remember($socketId);

    $ttl = (int) Redis::connection()->ttl(coreMutRegistryKey($socketId));

    Redis::connection()->del(coreMutRegistryKey($socketId));

    return $ttl;
}

test('the configured ttl is what Redis is told, when it already outlives three sweeps', function () {
    config()->set('lightspeed.connections.sweep_interval_ms', 1000);
    config()->set('lightspeed.connections.ttl_seconds', 4321);

    expect(coreMutObservedTtl())->toBe(4321);
});

test('a ttl shorter than three sweep intervals is raised to three of them', function () {
    // The floor is the whole relationship between the two dials: an entry that
    // can lapse between two ticks of a HEALTHY worker is the original bug with
    // a smaller number on it, and it fails the same silent way. Three, not two
    // and not four: one missed tick has to be survivable, and the entry is
    // rewritten rather than extended so a longer gap repairs itself.
    config()->set('lightspeed.connections.sweep_interval_ms', 600000);
    config()->set('lightspeed.connections.ttl_seconds', 60);

    expect(coreMutObservedTtl())->toBe(1800);
});

test('the sweep interval is converted to seconds by dividing, and rounded up', function () {
    // Rounded UP, because rounding a 1000.2 second cadence down to 1000 makes
    // the floor slightly shorter than three real intervals, which is the exact
    // gap the floor exists to close.
    config()->set('lightspeed.connections.sweep_interval_ms', 1000200);
    config()->set('lightspeed.connections.ttl_seconds', 1);

    expect(coreMutObservedTtl())->toBe(3003);
});

test('the shipped defaults give an hour, not three sweep intervals', function () {
    // Both dials at their package defaults: a five minute sweep floors the
    // entry at fifteen minutes, and the shipped hour is longer, so the hour
    // wins. Reading a default wrong here changes the TTL on every install.
    config()->set('lightspeed.connections', [
        'redis_connection' => 'default',
    ]);

    expect(coreMutObservedTtl())->toBe(3600);
});

test('the sweep interval default alone decides the floor when no ttl is configured', function () {
    config()->set('lightspeed.connections', [
        'redis_connection' => 'default',
        'ttl_seconds' => 1,
    ]);

    // 300000ms -> 300s -> three of them.
    expect(coreMutObservedTtl())->toBe(900);
});

test('dials that arrive as unusable strings fall back rather than becoming a type error', function () {
    // Both dials come from env vars, so a typo arrives here as a string that is
    // not a number at all. The registry is on the handshake path: a TypeError
    // here refuses every connection.
    config()->set('lightspeed.connections.sweep_interval_ms', 'five minutes');
    config()->set('lightspeed.connections.ttl_seconds', 'one hour');

    // Floor of 100ms -> 1 second -> three of them, against a ttl that reads 0.
    expect(coreMutObservedTtl())->toBe(3);
});

test('a sweep interval below the floor is raised to it rather than honoured', function () {
    config()->set('lightspeed.connections.sweep_interval_ms', 1);
    config()->set('lightspeed.connections.ttl_seconds', 1);

    expect(coreMutObservedTtl())->toBe(3);
});

// ---------------------------------------------------------------------------
// Which failures are worth a second attempt.
// ---------------------------------------------------------------------------

test('a dropped Redis link is retried once, so a blip does not cost a client its handshake', function () {
    // Laravel's PhpRedisConnection notices a dead socket, rebuilds its client,
    // and still rethrows that first failure. Every registry write sits on the
    // handshake path, so without the retry that rethrow reaches the server as a
    // refused connection while the rebuilt client is already usable.
    $attempts = 0;

    $result = coreMutThroughRetry(coreMutRegistry(), function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new \RedisException('read error on connection');
        }

        return 'served on the second attempt';
    });

    expect($attempts)->toBe(2)
        ->and($result)->toBe('served on the second attempt');
});

test('a Redis that is genuinely down still fails rather than retrying forever', function () {
    // The cap is what keeps the handshake from hanging on an outage instead of
    // refusing during one.
    $attempts = 0;

    $thrown = null;

    try {
        coreMutThroughRetry(coreMutRegistry(), function () use (&$attempts) {
            $attempts++;

            throw new \RedisException('connection refused');
        });
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($attempts)->toBe(2)
        ->and($thrown)->toBeInstanceOf(\RedisException::class);
});

test('anything that is not a link failure is not retried at all', function () {
    // Retrying is only safe because every operation here is idempotent AND the
    // failure is known to be transport-level. A bad command, a JSON failure or
    // an application exception retried is one fault reported as two.
    $attempts = 0;

    $thrown = null;

    try {
        coreMutThroughRetry(coreMutRegistry(), function () use (&$attempts) {
            $attempts++;

            throw new \RuntimeException('the application threw');
        });
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($attempts)->toBe(1)
        ->and($thrown)->toBeInstanceOf(\RuntimeException::class);
});
