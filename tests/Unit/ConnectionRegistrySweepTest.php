<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\PusherPaths;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: while a worker is holding a socket
 * open, the rest of the cluster can find out that it is holding it.
 *
 * The registry is the only answer there is to "which process is serving socket
 * X right now". Nothing inside the worker needs it, because the worker has the
 * fd; everything OUTSIDE the process does. `lightspeed:presence-probe` and
 * `lightspeed:load-probe` both refuse to run without it, and both say what they
 * are really asking: group these sockets by the worker that accepted them, then
 * exercise the path between two of those workers. A host application or an
 * operator tool asking the same question through `ConnectionRegistry::metadata()`
 * is asking it for the same reason.
 *
 * The entry was written once, with `SETEX 3600`, on the handshake, and never
 * again. So the answer was not "wrong after an hour", it was ABSENT after an
 * hour, and absent is indistinguishable from "that socket is not connected
 * anywhere": the entry Teardown deletes on close and the entry Redis expired
 * under a live connection leave exactly the same evidence behind. A caller that
 * treats a missing entry as a closed connection (the probes' own
 * resolveSocketMetadata() loop treats it as a connection that never arrived)
 * concludes that a client which is at that moment receiving broadcasts does not
 * exist.
 *
 * An hour is not a hypothetical for this package. A tab left open over lunch
 * outlives it, and long-lived connections are the entire point.
 *
 * The fix is the same shape as the two sweepers that already exist: the worker
 * that is holding the socket is the only thing that can honestly say it still
 * holds it, so it says so on a timer, and a worker that stops saying it is
 * precisely the worker whose entries should lapse.
 */

/** Records pushes and disconnects, in place of a real websocket. */
class ConnectionSweepSwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push(int $fd, Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        return true;
    }
}

/** An application worker that never boots one; nothing here reaches it. */
class ConnectionSweepOctaneWorker extends Lightspeed\Http\OctaneWorker
{
    public function __construct()
    {
    }

    public function isBooted(): bool
    {
        return true;
    }
}

/** A registry that cannot reach Redis, in the middle of a sweep. */
class UnreachableSweepRegistry extends ConnectionRegistry
{
    public function __construct()
    {
    }

    public function refresh(array $socketIds): int
    {
        throw new \RedisException('Connection lost');
    }
}

/** Keeps the reasons the runtime logger was given, instead of writing them out. */
class ConnectionSweepRuntimeLogger extends RuntimeLogger
{
    /** @var list<string|null> the `reason` field of every line, in order */
    public array $reasons = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->reasons[] = $fields['reason'] ?? $action;
    }

    public function maybePayloadSnippet(mixed $payload): ?string
    {
        return null;
    }
}

/**
 * A Server composed as serve() composes it, with a socket server attached.
 *
 * @param array<string, object> $overrides
 * @return array{0: Server, 1: ConnectionSweepSwooleServer}
 */
function connectionSweepServer(array $overrides = []): array
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = array_merge([
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new ConnectionSweepOctaneWorker(),
    ], $overrides);

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    // These tests drive the callbacks directly, so nothing has run the
    // workerStart that a real process would. Say so, or every handshake here is
    // refused by the readiness gate rather than judged on its merits.
    markLightspeedWorkerReady($server);

    $swoole = ConnectionSweepSwooleServer::make();

    // The sweeps return early without one, so every assertion below would be
    // about a pass that never ran.
    lightspeedSurface($server, 'attach')->attach($swoole);

    return [$server, $swoole];
}

/** Open one real Pusher connection on this worker and return its socket id. */
function connectionSweepOpen(Server $server, ConnectionSweepSwooleServer $swoole, int $fd): string
{
    $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $request->fd = $fd;
    $request->header = ['host' => 'localhost'];
    $request->server = [
        'request_uri' => PusherPaths::websocketPath(
            config('lightspeed.reverb_compat.path_prefix'),
            (string) config('lightspeed.reverb_compat.app_key'),
        ),
        'remote_addr' => '127.0.0.1',
    ];

    driveLightspeed($server, 'handleOpen', [$swoole, $request]);

    $socketId = app(ChannelManager::class)->socketIdFor($fd);

    expect($socketId)->toBeString('the handshake did not complete, so nothing below is about the registry');

    return (string) $socketId;
}

/**
 * One tick of everything this worker runs on a timer, in the order it runs them.
 *
 * The subject is a connection that stays open across many of these, so the
 * question the tests ask is not "does a method refresh a key" but "does an
 * ordinary, healthy, busy worker keep its own live connections findable while
 * it does everything else it does".
 */
function connectionSweepTick(Server $server): void
{
    driveLightspeed($server, 'runGrantSweep');
    driveLightspeed($server, 'sweepPresence');
    driveLightspeed($server, 'sweepConnections');
}

/**
 * The question every out-of-process consumer of this registry actually asks.
 *
 * Not "does the key exist" (nothing in the package asks that) but "which
 * process is holding this socket", which is what both probes call
 * `resolveSocketMetadata()` for and what a host application routing to a socket
 * it does not hold has to know. A null here is the failure: the caller has no
 * way to tell it apart from a socket that has closed.
 */
function connectionSweepLocate(string $socketId): ?string
{
    $metadata = app(ConnectionRegistry::class)->metadata($socketId);

    return is_string($metadata['process_key'] ?? null) ? $metadata['process_key'] : null;
}

function connectionSweepKey(string $socketId): string
{
    return "lightspeed:connection:{$socketId}";
}

function connectionSweepFd(): int
{
    return random_int(1000, 999999);
}

/**
 * THE BUG, AS THE CLUSTER EXPERIENCES IT.
 *
 * Nothing here reaches for a Redis key. A connection is opened on a worker, the
 * worker then does what a worker does for longer than the registry TTL, and the
 * question asked at the end is the one the probes ask: which process is holding
 * this socket? The socket is still open, this worker is still serving it, and
 * before the sweeper existed the only available answer was "nowhere".
 *
 * The TTL is shortened rather than the clock being moved, because the failure
 * is Redis expiring a key on its own schedule and no fake can expire it for
 * real. `ttl_seconds` is set below the floor deliberately: the floor derives a
 * minimum of three sweep intervals, so this asserts against a live 3 second
 * entry that must be refreshed roughly fifteen times to survive the wait.
 */
test('a connection this worker has held for longer than the registry TTL is still locatable', function () {
    config()->set('lightspeed.connections.ttl_seconds', 1);
    config()->set('lightspeed.connections.sweep_interval_ms', 200);

    [$server, $swoole] = connectionSweepServer();
    $fd = connectionSweepFd();
    $socketId = connectionSweepOpen($server, $swoole, $fd);

    expect(connectionSweepLocate($socketId))->toBe(app(WorkerContext::class)->currentProcessKey());

    // Longer than the entry the handshake wrote, spent exactly as a live
    // worker spends it.
    $deadline = microtime(true) + 2.5;

    while (microtime(true) < $deadline) {
        usleep(200_000);
        connectionSweepTick($server);
    }

    // The connection is unambiguously still here: the socket is established and
    // this worker still maps the fd to the same socket id. So a registry that
    // cannot name its process is wrong, not merely stale.
    expect($swoole->isEstablished($fd))->toBeTrue()
        ->and(app(ChannelManager::class)->socketIdFor($fd))->toBe($socketId)
        ->and(connectionSweepLocate($socketId))
        ->toBe(
            app(WorkerContext::class)->currentProcessKey(),
            'a live connection became unfindable to every other process in the cluster while its worker was still serving it',
        );

    driveLightspeed($server, 'handleClose', [$swoole, $fd]);
});

/**
 * The other half of the same claim, and the reason a sweep is allowed to write
 * at all: an entry it refreshes must belong to a connection this worker still
 * holds.
 *
 * A sweep sourced from anything other than the live fd table would resurrect
 * the entry of a socket that has closed, and the cost of that is worse than the
 * expiry it fixes: a caller routed to a worker that will never deliver, with an
 * entry that now outlives the connection by a full TTL every time the sweep
 * runs. Teardown deletes the entry; the next tick must leave it deleted.
 */
test('a sweep does not resurrect the entry of a connection that has closed', function () {
    [$server, $swoole] = connectionSweepServer();
    $fd = connectionSweepFd();
    $socketId = connectionSweepOpen($server, $swoole, $fd);

    driveLightspeed($server, 'handleClose', [$swoole, $fd]);

    expect(connectionSweepLocate($socketId))->toBeNull();

    connectionSweepTick($server);

    expect(connectionSweepLocate($socketId))
        ->toBeNull('a closed socket was advertised as live, so every consumer routes to a worker that no longer holds it');
});

/**
 * A SWEEP HAS TO RECREATE AN ENTRY, NOT MERELY EXTEND ONE.
 *
 * The distinction is invisible in every test that sweeps an entry which is
 * still there, where SETEX and EXPIRE do the same thing. They are not the same
 * instruction: EXPIRE on a missing key does nothing at all, and the key is
 * missing in exactly the case that matters. A worker that missed enough ticks
 * (a long garbage collection pause, a blocked event loop, a Redis outage that
 * swallowed several sweeps) comes back to a lapsed entry, and with EXPIRE there
 * is nothing left to extend, so that connection is unfindable for the rest of
 * its life however long the worker goes on holding it.
 *
 * The entry is deleted outright here, which is precisely what the lapse leaves
 * behind.
 */
test('a sweep recreates a registry entry that has already lapsed', function () {
    [$server, $swoole] = connectionSweepServer();
    $fd = connectionSweepFd();
    $socketId = connectionSweepOpen($server, $swoole, $fd);

    Redis::connection()->del(connectionSweepKey($socketId));

    expect(connectionSweepLocate($socketId))->toBeNull();

    connectionSweepTick($server);

    expect(connectionSweepLocate($socketId))
        ->toBe(
            app(WorkerContext::class)->currentProcessKey(),
            'a worker that missed a tick could never make its own live connections findable again',
        )
        ->and(Redis::connection()->ttl(connectionSweepKey($socketId)))->toBeGreaterThan(0);

    driveLightspeed($server, 'handleClose', [$swoole, $fd]);
});

/**
 * The TTL and the sweep that refreshes it are one mechanism, so their
 * relationship is enforced rather than left to whoever edits the config.
 *
 * An entry that can lapse between two ticks of a HEALTHY worker is the original
 * bug with a smaller number on it, and it fails the same silent way: nothing
 * logs, nothing throws, connections simply stop being findable. Same floor, and
 * for the same reason, as `presence.connection_ttl_seconds`.
 */
test('the registry TTL cannot be configured shorter than the sweep that refreshes it', function () {
    config()->set('lightspeed.connections.sweep_interval_ms', 30000);
    config()->set('lightspeed.connections.ttl_seconds', 5);

    [$server, $swoole] = connectionSweepServer();
    $fd = connectionSweepFd();
    $socketId = connectionSweepOpen($server, $swoole, $fd);

    expect(Redis::connection()->ttl(connectionSweepKey($socketId)))->toBeGreaterThan(30);

    driveLightspeed($server, 'handleClose', [$swoole, $fd]);
});

/**
 * The sweeper may not be able to kill the worker.
 *
 * It runs in a Swoole timer callback, where an escaping exception is fatal to
 * the process, and `enable_coroutine` ships FALSE so there is no coroutine to
 * contain it. Redis being unreachable is the ordinary case: the registry's own
 * retry hides a dropped link, and what is left is a real outage, during which
 * this worker must go on serving every connection it holds.
 */
test('a registry that cannot reach Redis does not take the worker down with it', function () {
    [$server, $swoole] = connectionSweepServer(['connectionRegistry' => new UnreachableSweepRegistry()]);

    app(ChannelManager::class)->connect(connectionSweepFd(), 'sweep-outage-'.bin2hex(random_bytes(6)), []);

    expect(driveLightspeed($server, 'sweepConnections'))->toBe(0);
});

/**
 * And the sweep that failed is a sweep, not a silence: an outage that nothing
 * reports is an outage nobody fixes, and these entries lapse quietly.
 */
test('a failed sweep says so on the runtime log', function () {
    $logger = new ConnectionSweepRuntimeLogger();

    [$server] = connectionSweepServer([
        'connectionRegistry' => new UnreachableSweepRegistry(),
        'runtimeLogger' => $logger,
    ]);

    app(ChannelManager::class)->connect(connectionSweepFd(), 'sweep-outage-'.bin2hex(random_bytes(6)), []);

    driveLightspeed($server, 'sweepConnections');

    expect($logger->reasons)->toContain('connection-sweep-failed');
});

/**
 * A sweep with no server attached is a sweep in a process that is not serving
 * websockets: an artisan command, an HTTP-only worker, a worker that has
 * already stopped. Refreshing there would vouch for connections this process
 * does not hold.
 */
test('a sweep with no attached server refreshes nothing', function () {
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    foreach ([
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new ConnectionSweepOctaneWorker(),
    ] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    $socketId = 'sweep-detached-'.bin2hex(random_bytes(6));
    app(ChannelManager::class)->connect(connectionSweepFd(), $socketId, []);

    expect(driveLightspeed($server, 'sweepConnections'))->toBe(0)
        ->and(connectionSweepLocate($socketId))->toBeNull();
});
