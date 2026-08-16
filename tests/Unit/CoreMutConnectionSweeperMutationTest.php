<?php

use Lightspeed\Channels\ChannelManager;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Connections\ConnectionSweeper;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Swoole\Timer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The renewal timer: that it is armed against a server, that it replaces itself
 * rather than accumulating, that a pass counts up, and that an outage in the
 * middle of one is reported instead of ending the worker.
 *
 * Every effect here is a side effect on a timer or on Redis, which is the shape
 * of code a suite that only ever drives frames cannot see at all.
 */

/**
 * Every sweeper this file boots, so its timers can be cleared again.
 *
 * A Swoole timer is process-global and outlives the test that armed it. Left
 * running, the first tick after Testbench tears the container down calls
 * config() against an application that no longer has one.
 *
 * @var list<ConnectionSweeper>
 */
$GLOBALS['coreMutSweepers'] = [];

afterEach(function () {
    foreach ($GLOBALS['coreMutSweepers'] as $sweeper) {
        try {
            $sweeper->shutdownConnectionSweeper();
        } catch (\Throwable) {
            // Clearing the timer is all this needs.
        }
    }

    $GLOBALS['coreMutSweepers'] = [];
});

/** A ChannelManager holding exactly the socket ids a test names. */
class CoreMutHoldingChannels extends ChannelManager
{
    /** @param list<string> $held */
    public function __construct(private array $held)
    {
    }

    public function heldSocketIds(): array
    {
        return $this->held;
    }
}

/** A registry that counts renewals instead of talking to Redis. */
class CoreMutCountingRegistry extends ConnectionRegistry
{
    /** @var list<int> the size of every chunk it was handed */
    public array $chunks = [];

    public function __construct()
    {
    }

    public function refresh(array $socketIds): int
    {
        $this->chunks[] = count($socketIds);

        return count($socketIds);
    }
}

/** A registry whose Redis is unreachable. */
class CoreMutUnreachableRegistry extends ConnectionRegistry
{
    public function __construct()
    {
    }

    public function refresh(array $socketIds): int
    {
        throw new \RedisException('connection refused');
    }
}

/** Records the structured lines instead of writing them to stdout. */
class CoreMutRecordingRuntimeLogger extends RuntimeLogger
{
    /** @var list<array{action: string, fields: array}> */
    public array $websocket = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->websocket[] = ['action' => $action, 'fields' => $fields];
    }
}

/**
 * @param list<string> $held
 */
function coreMutSweeper(array $held, ?ConnectionRegistry $registry = null, ?RuntimeLogger $logger = null): ConnectionSweeper
{
    $sweeper = new ConnectionSweeper(
        new CoreMutHoldingChannels($held),
        $registry ?? new CoreMutCountingRegistry(),
        new AttachedServer(),
        $logger ?? new CoreMutRecordingRuntimeLogger(),
    );

    $GLOBALS['coreMutSweepers'][] = $sweeper;

    return $sweeper;
}

function coreMutSwooleServer(): SwooleServer
{
    return (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor();
}

function coreMutTimerId(ConnectionSweeper $sweeper): ?int
{
    $handle = new ReflectionProperty(ConnectionSweeper::class, 'connectionSweepTimerId');
    $handle->setAccessible(true);

    return $handle->getValue($sweeper);
}

// ---------------------------------------------------------------------------
// Arming.
// ---------------------------------------------------------------------------

test('a sweep does nothing until a server has been attached to it', function () {
    // The attach is what tells the sweep this worker is actually up. Without
    // it every tick returns immediately and renews nothing, which is the
    // failure the sweeper exists to fix, silently reintroduced.
    $sweeper = coreMutSweeper(['socket-a', 'socket-b']);

    expect($sweeper->sweepConnections())->toBe(0);

    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    expect($sweeper->sweepConnections())->toBe(2);
});

test('booting twice replaces the timer rather than joining a second one to it', function () {
    // A worker that restarts in the same process would otherwise leave the old
    // timer running against a server this object no longer holds, and every
    // renewal would happen twice per tick for as long as the process lived.
    $sweeper = coreMutSweeper(['socket-a']);

    $sweeper->bootConnectionSweeper(coreMutSwooleServer());
    $first = coreMutTimerId($sweeper);

    $sweeper->bootConnectionSweeper(coreMutSwooleServer());
    $second = coreMutTimerId($sweeper);

    expect($first)->not->toBeNull()
        ->and($second)->not->toBe($first)
        ->and(Timer::exists($first))->toBeFalse()
        ->and(Timer::exists($second))->toBeTrue();
});

test('the timer ticks at the configured sweep interval, floored at a tenth of a second', function () {
    // The floor is the other half of the relationship ConnectionRegistry
    // enforces: the entry TTL refuses to be shorter than three of these, so a
    // sweep interval of zero would ask Redis for a TTL of zero.
    config()->set('lightspeed.connections.sweep_interval_ms', 45000);

    $sweeper = coreMutSweeper([]);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    expect(Timer::info(coreMutTimerId($sweeper))['interval'])->toBe(45000);

    config()->set('lightspeed.connections.sweep_interval_ms', 1);

    $floored = coreMutSweeper([]);
    $floored->bootConnectionSweeper(coreMutSwooleServer());

    expect(Timer::info(coreMutTimerId($floored))['interval'])->toBe(100);
});

test('the shipped sweep interval is five minutes', function () {
    // Read from the package default, not from a test override: this number is
    // the one the entry TTL is floored against on every install.
    config()->set('lightspeed.connections', ['redis_connection' => 'default']);

    $sweeper = coreMutSweeper([]);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    expect(Timer::info(coreMutTimerId($sweeper))['interval'])->toBe(300000);
});

test('a sweep interval that arrives as an unusable string falls back to the floor', function () {
    // It comes from an env var, and a timer armed on a non-numeric interval is
    // a worker that dies at boot rather than one that sweeps badly.
    config()->set('lightspeed.connections.sweep_interval_ms', 'five minutes');

    $sweeper = coreMutSweeper([]);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    expect(Timer::info(coreMutTimerId($sweeper))['interval'])->toBe(100);
});

// ---------------------------------------------------------------------------
// One pass.
// ---------------------------------------------------------------------------

test('every held connection is renewed on every tick, in pipelines of the configured size', function () {
    // Deliberately not a rotating budget: a connection skipped for long enough
    // does not have its renewal delayed, it loses its entry and becomes
    // invisible to the whole cluster. The chunk size bounds only how much of
    // the pass is in flight at once.
    config()->set('lightspeed.connections.sweep_chunk_size', 2);

    $registry = new CoreMutCountingRegistry();
    $sweeper = coreMutSweeper(['a', 'b', 'c', 'd', 'e'], $registry);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    expect($sweeper->sweepConnections())->toBe(5)
        ->and($registry->chunks)->toBe([2, 2, 1]);
});

test('the shipped chunk size is five hundred, and a chunk of nothing is refused', function () {
    config()->set('lightspeed.connections', ['redis_connection' => 'default']);

    $registry = new CoreMutCountingRegistry();
    $sweeper = coreMutSweeper(array_map(fn (int $n) => "socket-{$n}", range(1, 501)), $registry);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());
    $sweeper->sweepConnections();

    expect($registry->chunks)->toBe([500, 1]);

    // A chunk size of zero would make array_chunk throw, which on a Swoole
    // timer callback is the worker rather than the sweep.
    config()->set('lightspeed.connections.sweep_chunk_size', 0);

    $floored = new CoreMutCountingRegistry();
    $sweeper = coreMutSweeper(['a', 'b'], $floored);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());
    $sweeper->sweepConnections();

    expect($floored->chunks)->toBe([1, 1]);
});

test('a chunk size that arrives as an unusable string falls back to one rather than to zero', function () {
    config()->set('lightspeed.connections.sweep_chunk_size', 'five hundred');

    $registry = new CoreMutCountingRegistry();
    $sweeper = coreMutSweeper(['a', 'b'], $registry);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());
    $sweeper->sweepConnections();

    expect($registry->chunks)->toBe([1, 1]);
});

// ---------------------------------------------------------------------------
// An outage in the middle of a pass.
// ---------------------------------------------------------------------------

test('a Redis outage during a sweep is reported and does not escape the timer callback', function () {
    // This is a Swoole timer callback, an escaping exception is fatal to the
    // worker, and `enable_coroutine` defaults to false so there is no coroutine
    // to contain it. Redis being down is the expected failure here, not an
    // exotic one, and the worker must go on serving every connection it holds.
    $logger = new CoreMutRecordingRuntimeLogger();
    $sweeper = coreMutSweeper(['a', 'b'], new CoreMutUnreachableRegistry(), $logger);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    $escaped = null;

    try {
        expect($sweeper->sweepConnections())->toBe(0);
    } catch (\Throwable $e) {
        $escaped = $e;
    }

    expect($escaped)->toBeNull()
        ->and($logger->websocket)->toHaveCount(1)
        ->and($logger->websocket[0]['action'])->toBe('error')
        ->and($logger->websocket[0]['fields'])->toBe([
            'reason' => 'connection-sweep-failed',
            'renewed' => 0,
            'message' => 'connection refused',
        ]);
});

test('the failure line says how much of the pass had already landed', function () {
    // The whole pass is abandoned rather than the failing chunk alone, so how
    // many entries were already renewed is the difference between "the sweep
    // did nothing" and "the sweep stopped part way", which is what tells an
    // operator whether the outage started before this tick or during it.
    config()->set('lightspeed.connections.sweep_chunk_size', 1);

    $registry = new class extends ConnectionRegistry
    {
        private int $calls = 0;

        public function __construct()
        {
        }

        public function refresh(array $socketIds): int
        {
            if (++$this->calls > 2) {
                throw new \RedisException('connection refused');
            }

            return count($socketIds);
        }
    };

    $logger = new CoreMutRecordingRuntimeLogger();
    $sweeper = coreMutSweeper(['a', 'b', 'c'], $registry, $logger);
    $sweeper->bootConnectionSweeper(coreMutSwooleServer());

    expect($sweeper->sweepConnections())->toBe(2)
        ->and($logger->websocket[0]['fields']['renewed'])->toBe(2);
});
