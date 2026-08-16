<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;

/**
 * The claim this file exists to defend: a presence member whose process was
 * killed leaves the channel, and a member whose process is alive does not.
 *
 * Both halves matter and they pull against each other. Presence rows are
 * REFERENCE COUNTS, and the only thing that ever decremented one was the
 * server's close handler. So a SIGKILL, an OOM kill or a container stop that
 * reaps the process left a phantom member in every future snapshot of that
 * channel with no decrement left in the world that could remove it. But
 * anything aggressive enough to clear those, a blanket TTL most obviously,
 * removes live members along with the ghosts, and Redis cannot expire an
 * individual hash field before 7.4.
 *
 * A killed connection is simulated by deleting its liveness marker without
 * calling leave(), which is exactly what the marker's own expiry does when the
 * worker that was refreshing it stops existing.
 */

function leakChannel(): string
{
    return 'presence-lightspeed-leak-'.bin2hex(random_bytes(6));
}

function leakRedis()
{
    return Redis::connection();
}

/** Redis::keys() returns prefixed names and del() prefixes again. */
function leakUnprefixed(string $key): string
{
    $prefix = (string) config('database.redis.options.prefix');

    return $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
}

/** Records the fan-out instead of publishing it. */
class PresenceLeakBroadcastBridge extends BroadcastBridge
{
    /** @var list<array{channel: string, event: string, payload: mixed}> */
    public array $fannedOut = [];

    public function __construct()
    {
    }

    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        foreach ($channels as $channel) {
            $this->fannedOut[] = ['channel' => $channel, 'event' => $event, 'payload' => $payload];
        }

        return count($channels);
    }
}

/** @param array<string, object> $overrides */
function leakServer(BroadcastBridge $bridge, array $overrides = []): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = array_merge([
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => $bridge,
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => app(Lightspeed\Http\OctaneWorker::class),
    ], $overrides);

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    // Builds the websocket surfaces out of the collaborators just injected,
    // exactly as the constructor does after building the Octane worker.
    driveLeak($server, 'compose');

    // The sweeper refuses to run without an attached server, exactly as the
    // grant sweeper does, so give it one. Nothing here pushes to a socket.
    $swoole = (new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor();
    writeLightspeedProperty($server, 'swoole', $swoole);

    return $server;
}

function driveLeak(object $target, string $method, array $arguments = []): mixed
{
    return driveLightspeed($target, $method, $arguments);
}

/** The key whose absence means "this connection's process is gone". */
function leakMarkerKey(string $channel, string $connectionId): string
{
    return "lightspeed:presence:channel:{$channel}:live:{$connectionId}";
}

afterEach(function () {
    foreach ((array) leakRedis()->keys('*lightspeed:presence:channel:presence-lightspeed-leak-*') as $key) {
        leakRedis()->del(leakUnprefixed($key));
    }
});

test('a member whose process was killed is reaped out of the snapshot', function () {
    $channel = leakChannel();
    $store = app(PresenceStore::class);

    $store->join($channel, 'conn-alive', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    $store->join($channel, 'conn-killed', ['user_id' => 'grace', 'user_info' => ['name' => 'Grace']]);

    expect($store->snapshot($channel)['count'])->toBe(2);

    // SIGKILL: no close frame, no leave(), the row simply outlives its process.
    // What actually happens is that nothing refreshes the marker and it lapses;
    // deleting it is the same state, reached in one step.
    leakRedis()->del(leakMarkerKey($channel, 'conn-killed'));

    expect($store->reapAbandoned($channel))->toBe(['grace']);

    $snapshot = $store->snapshot($channel);

    expect($snapshot['count'])->toBe(1)
        ->and($snapshot['ids'])->toBe(['ada']);
});

test('reaping decrements the refcount rather than deleting the row', function () {
    // The distinction the whole design turns on. A user with two connections
    // who loses one is still present; if the reaper deleted rows instead of
    // going through leave(), it would take the survivor with it.
    $channel = leakChannel();
    $store = app(PresenceStore::class);

    $store->join($channel, 'conn-tab-1', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    $store->join($channel, 'conn-tab-2', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);

    leakRedis()->del(leakMarkerKey($channel, 'conn-tab-1'));

    // Nothing to announce: the member did not leave the channel.
    expect($store->reapAbandoned($channel))->toBe([])
        ->and($store->snapshot($channel)['ids'])->toBe(['ada']);

    leakRedis()->del(leakMarkerKey($channel, 'conn-tab-2'));

    expect($store->reapAbandoned($channel))->toBe(['ada'])
        ->and($store->snapshot($channel)['count'])->toBe(0);
});

test('a live member is not reaped, however many sweeps run', function () {
    $channel = leakChannel();
    $bridge = new PresenceLeakBroadcastBridge();
    $server = leakServer($bridge);

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);

    $connectionId = driveLeak($server, 'presenceConnectionId', [$fd]);
    app(PresenceStore::class)->join($channel, $connectionId, ['user_id' => 'ada', 'user_info' => []]);

    // A marker about to lapse is the case that matters: the sweep must renew
    // it, not merely read it.
    leakRedis()->expire(leakMarkerKey($channel, $connectionId), 1);

    expect(driveLeak($server, 'sweepPresence'))->toBe(0)
        ->and(driveLeak($server, 'sweepPresence'))->toBe(0)
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada'])
        ->and(leakRedis()->ttl(leakMarkerKey($channel, $connectionId)))->toBeGreaterThan(5);
});

test('the sweeper reaps a dead peer and tells the channel it left', function () {
    // The reaping worker holds a live connection of its own on the channel,
    // which is what puts the channel in its sweep at all; the ghost belongs to
    // a worker that is gone.
    $channel = leakChannel();
    $bridge = new PresenceLeakBroadcastBridge();
    $server = leakServer($bridge);

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);

    $connectionId = driveLeak($server, 'presenceConnectionId', [$fd]);
    app(PresenceStore::class)->join($channel, $connectionId, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join($channel, 'dead-worker:socket:9.9', ['user_id' => 'grace', 'user_info' => []]);

    leakRedis()->del(leakMarkerKey($channel, 'dead-worker:socket:9.9'));

    expect(driveLeak($server, 'sweepPresence'))->toBe(1)
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada']);

    // A member removed from the snapshot with no member_removed leaves every
    // client painting a collaborator who is not there until it reloads.
    expect($bridge->fannedOut)->toHaveCount(1)
        ->and($bridge->fannedOut[0]['event'])->toBe('pusher_internal:member_removed')
        ->and($bridge->fannedOut[0]['channel'])->toBe($channel)
        ->and($bridge->fannedOut[0]['payload'])->toBe(['user_id' => 'grace']);
});

/** A store that cannot vouch for anything, standing in for an unreachable Redis. */
class UnvouchablePresenceStore extends PresenceStore
{
    public function __construct(private readonly PresenceStore $inner)
    {
    }

    public function heartbeatConnections(array $connectionIdsByChannel): void
    {
        throw new \RedisException('Connection lost');
    }

    public function heartbeatChannel(string $channel): void
    {
        throw new \RedisException('Connection lost');
    }

    public function reapAbandoned(string $channel): array
    {
        return $this->inner->reapAbandoned($channel);
    }
}

test('a sweep that could not vouch for its own members does not reap them', function () {
    // The dangerous ordering, and it is not hypothetical: an earlier version of
    // this sweeper caught a failing heartbeat and carried on to the reap, which
    // removed the live members of the very worker doing the reaping. Nothing
    // about "Redis was briefly unreachable" should empty a presence channel.
    $channel = leakChannel();
    $bridge = new PresenceLeakBroadcastBridge();
    $server = leakServer($bridge, [
        'presenceStore' => new UnvouchablePresenceStore(app(PresenceStore::class)),
    ]);

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);

    $connectionId = driveLeak($server, 'presenceConnectionId', [$fd]);
    app(PresenceStore::class)->join($channel, $connectionId, ['user_id' => 'ada', 'user_info' => []]);

    // The marker is gone, so this member looks exactly like a ghost. The only
    // thing that distinguishes it is a heartbeat that never got to run.
    leakRedis()->del(leakMarkerKey($channel, $connectionId));

    expect(driveLeak($server, 'sweepPresence'))->toBe(0)
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada'])
        ->and($bridge->fannedOut)->toBe([]);
});

test('presence keys carry a TTL so a channel every worker left cannot live forever', function () {
    // The backstop, not the mechanism: a channel with a live member anywhere
    // has this refreshed on every sweep. It only ever expires a channel that
    // no worker is left holding, which is the one case a marker cannot cover.
    $channel = leakChannel();
    app(PresenceStore::class)->join($channel, 'conn-1', ['user_id' => 'ada', 'user_info' => []]);

    foreach (['connections', 'user-counts', 'users', 'actors'] as $suffix) {
        expect(leakRedis()->ttl("lightspeed:presence:channel:{$channel}:{$suffix}"))
            ->toBeGreaterThan(0, "the {$suffix} key never expires");
    }
});

/**
 * The CHANNEL TTL has the same floor, and its `max(` was invertible in silence.
 *
 * Its sibling on `connectionTtlSeconds()` is killed by the test below; this one
 * survived, which is exactly the asymmetry that makes a floor worth pinning
 * twice. Turn this `max(` into a `min(` and a lowered `channel_ttl_seconds`
 * becomes the answer outright: the channel's rows. The reference counts every
 * snapshot is built from, expire out from under members who are alive and
 * heartbeating, and every one of them silently vanishes from presence.
 *
 * The floor is derived rather than declared, so the numbers are chosen to put
 * the two candidates far apart: a 30s sweep gives a 90s marker TTL and so a 180s
 * channel floor, against the 5s the operator asked for.
 */
test('the channel TTL cannot be configured below the marker TTL it has to outlive', function () {
    config()->set('lightspeed.presence.sweep_interval_ms', 30000);
    config()->set('lightspeed.presence.channel_ttl_seconds', 5);

    $channel = leakChannel();
    app(PresenceStore::class)->join($channel, 'conn-1', ['user_id' => 'ada', 'user_info' => []]);

    foreach (['connections', 'user-counts', 'users', 'actors'] as $suffix) {
        expect(leakRedis()->ttl("lightspeed:presence:channel:{$channel}:{$suffix}"))
            ->toBeGreaterThan(100, "the {$suffix} key expires before its own members' markers do");
    }
});

/**
 * A HEARTBEAT HAS TO RECREATE A MARKER, NOT MERELY EXTEND ONE.
 *
 * The `SETEX` in heartbeatConnections() can be turned into `EXPIRE` and the
 * whole suite stays green, because every test that heartbeats does so on a
 * marker that still exists, where the two are indistinguishable. They are not
 * the same instruction. EXPIRE on a missing key does nothing at all.
 *
 * The case they differ on is the one this entire file is about. A marker lapses
 * whenever its worker misses enough ticks: a long garbage collection pause, a
 * blocked event loop, a Redis blip that swallowed one sweep, a usleep on the
 * only thread the server has. With EXPIRE that marker is gone FOR GOOD, no
 * later heartbeat can bring it back, because there is nothing left to extend, 
 * and the very next reap finds a live, connected, actively heartbeating member
 * with no marker and removes it from the channel. The member is still there and
 * their socket is still open; they simply vanish from everyone's presence list
 * and cannot return without re-subscribing.
 *
 * So the marker is deleted outright here, which is exactly the state a lapse
 * leaves behind, and the heartbeat has to put it back.
 */
test('a heartbeat recreates a marker that has already lapsed, rather than failing to extend it', function () {
    $channel = leakChannel();
    $store = app(PresenceStore::class);

    $store->join($channel, 'conn-1', ['user_id' => 'ada', 'user_info' => []]);

    // The lapse, reached in one step: nothing refreshed it in time and Redis
    // expired it.
    leakRedis()->del(leakMarkerKey($channel, 'conn-1'));

    expect(leakRedis()->exists(leakMarkerKey($channel, 'conn-1')))->toBe(0);

    $store->heartbeatConnections([$channel => ['conn-1']]);

    expect(leakRedis()->exists(leakMarkerKey($channel, 'conn-1')))
        ->toBe(1, 'the heartbeat could not recreate a lapsed marker, so this live member is reaped on the next sweep')
        ->and(leakRedis()->ttl(leakMarkerKey($channel, 'conn-1')))->toBeGreaterThan(0);

    // And the member survives the reap that follows, which is the damage the
    // marker exists to prevent.
    expect($store->reapAbandoned($channel))->toBe([])
        ->and($store->snapshot($channel)['ids'])->toBe(['ada']);
});

test('the marker TTL cannot be configured shorter than the sweep that refreshes it', function () {
    // A marker that could lapse between two ticks of a HEALTHY worker would
    // have that worker reap its own live members, so the relationship is
    // enforced rather than left to whoever edits the config.
    config()->set('lightspeed.presence.sweep_interval_ms', 30000);
    config()->set('lightspeed.presence.connection_ttl_seconds', 5);

    $channel = leakChannel();
    app(PresenceStore::class)->join($channel, 'conn-1', ['user_id' => 'ada', 'user_info' => []]);

    expect(leakRedis()->ttl(leakMarkerKey($channel, 'conn-1')))->toBeGreaterThan(30);
});
