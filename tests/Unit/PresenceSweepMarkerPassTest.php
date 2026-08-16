<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Server;
use Lightspeed\Workers\WorkerContext;

/**
 * A LIVE MEMBER ON A CHANNEL OUTSIDE THIS TICK'S BUDGET IS STILL VOUCHED FOR.
 *
 * The per-tick budget bounds CHANNELS, and it used to bound the liveness
 * markers with them: a channel got its markers rewritten only on the tick that
 * swept it, once every `ceil(channels / budget)` ticks. The marker TTL does not
 * rotate. It is `max(3 x interval, 60s)` from the moment it was last written,
 * so past roughly `budget x TTL / interval` channels on one worker (600 at the
 * shipped dials) the revisit period overtakes the TTL and the markers of
 * CONNECTED members lapse between visits. Any worker sweeping that channel then
 * finds rows nobody is vouching for, and does exactly what it is supposed to do
 * to a ghost: decrements the refcount, broadcasts `member_removed` to every
 * browser on the channel, and emits a `'swept'` ConnectionClosed telling the
 * application to release state a connected user is still holding.
 *
 * So the marker pass is not the budgeted half. Every presence channel this
 * worker holds gets its markers rewritten on every tick, in one chunked
 * pipeline; the five per-channel EXPIREs (which guard 3600s keys) and the reap
 * (the expensive half) stay on the rotation.
 *
 * The numbers below are the shipped relationship shrunk to run in seconds: 7
 * channels at 2 a tick is a four-tick revisit period, against a marker TTL of
 * three seconds and ticks a second apart. Real Redis, real TTLs, real waiting,
 * because the TTL Redis is holding is the only thing that decides any of this.
 */

function markerPassChannel(int $index): string
{
    return sprintf('presence-lightspeed-marker-%02d-%s', $index, bin2hex(random_bytes(4)));
}

function markerPassRedis()
{
    return Redis::connection();
}

function markerPassLivenessKey(string $channel, string $connectionId): string
{
    return "lightspeed:presence:channel:{$channel}:live:{$connectionId}";
}

/** Records the fan-out instead of publishing it. */
class MarkerPassBroadcastBridge extends BroadcastBridge
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

function markerPassServer(BroadcastBridge $bridge): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = [
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
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    // The sweep returns early without one.
    $swoole = (new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor();
    writeLightspeedProperty($server, 'swoole', $swoole);

    return $server;
}

afterEach(function () {
    foreach ((array) markerPassRedis()->keys('*lightspeed:presence:channel:presence-lightspeed-marker-*') as $key) {
        $prefix = (string) config('database.redis.options.prefix');
        markerPassRedis()->del($prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
    }
});

/** The channel the ghost sits on, and the tick that reaps it at a budget of one. */
const MARKER_PASS_GHOST_TICK = 3;

test('a live member on a channel outside the tick budget survives another worker sweeping it', function () {
    // THE MARGINS ARE THE POINT OF THESE NUMBERS, because this is a test about
    // a wall clock and a slow machine must not be able to fail it for the wrong
    // reason. The marker TTL is floored at three sweep intervals and cannot go
    // below three seconds, so:
    //
    //   live members    ticked every 0.5s against a 3s TTL, six times more
    //                   often than they need to be
    //   the ghost       reaped because its marker is DELETED before a tick that
    //                   is chosen, not because a one-second expiry beat a sweep
    //   the bug         one channel a tick over twenty channels is a ten-second
    //                   revisit period against that same 3s TTL, so the pre-fix
    //                   code has half the channels lapsed well before the end
    config()->set('lightspeed.presence.sweep_interval_ms', 1000);
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 1);
    config()->set('lightspeed.presence.connection_ttl_seconds', 1);

    $bridge = new MarkerPassBroadcastBridge();
    $server = markerPassServer($bridge);
    $store = app(PresenceStore::class);

    $channels = [];
    $connectionIds = [];

    for ($i = 0; $i < 20; $i++) {
        $channel = markerPassChannel($i);
        $fd = 20_000 + $i;

        app(ChannelManager::class)->connect($fd, "socket-{$fd}", []);
        app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => "u{$i}", 'user_info' => []]);

        $connectionId = driveLightspeed($server, 'presenceConnectionId', [$fd]);
        $store->join($channel, $connectionId, ['user_id' => "u{$i}", 'user_info' => []]);

        $channels[] = $channel;
        $connectionIds[$channel] = $connectionId;
    }

    // The dead worker: a member whose process is gone, so nothing anywhere
    // rewrites its marker. The inverse control, in the same run: the fix must
    // not buy the live members their survival by making the sweep stop reaping.
    $ghostChannel = $channels[MARKER_PASS_GHOST_TICK];
    $store->join($ghostChannel, 'dead-worker:socket:9.9', ['user_id' => 'ghost', 'user_info' => []]);

    // The peer worker: another process that also holds these channels and
    // sweeps them on its own schedule. It is the one that reaps what this
    // worker stopped vouching for. It leaves the ghost's channel alone, so the
    // only thing that can reap the ghost is this worker's own sweep.
    $peer = app(PresenceStore::class);
    $peerChannels = array_values(array_diff($channels, [$ghostChannel]));
    $reapedByPeer = [];

    // A budget of one sweeps channel N on tick N, so the tick that reaps the
    // ghost is known rather than raced: its marker is removed immediately
    // before that tick, which is what a lapse leaves behind, reached in one
    // step.
    for ($tick = 0; $tick < 12; $tick++) {
        if ($tick === MARKER_PASS_GHOST_TICK) {
            markerPassRedis()->del(markerPassLivenessKey($ghostChannel, 'dead-worker:socket:9.9'));
        }

        driveLightspeed($server, 'sweepPresence', []);

        usleep(500_000);

        foreach ($peerChannels as $channel) {
            foreach ($peer->reapAbandoned($channel) as $userId) {
                $reapedByPeer[] = [$channel, $userId];
            }
        }
    }

    $survivors = [];
    $lapsed = [];

    foreach ($channels as $index => $channel) {
        $survivors[$channel] = $store->snapshot($channel)['ids'];

        if (!(bool) markerPassRedis()->exists(markerPassLivenessKey($channel, $connectionIds[$channel]))) {
            $lapsed[] = $channel;
        }
    }

    // The damage first: nothing the peer reaped may be a live member. The ghost
    // is the only row in this fixture nobody is vouching for.
    $reapedLiveMembers = array_values(array_filter($reapedByPeer, fn (array $row): bool => $row[1] !== 'ghost'));

    expect($reapedLiveMembers)->toBe([], 'a live member was reaped by another worker');

    // Each channel's snapshot still holds its own live member, and the ghost's
    // channel holds nothing else. (toContain takes every argument as another
    // needle, so this is asserted as the whole list it is.)
    $expected = [];

    foreach ($channels as $index => $channel) {
        $expected[$channel] = ["u{$index}"];
    }

    expect($survivors)->toBe($expected);

    // And the cause, so a failure says which half broke: every one of these
    // members is connected, subscribed and held by a worker that ticked every
    // half second, so no marker of theirs may be missing.
    expect($lapsed)->toBe([], 'a connected member\'s liveness marker lapsed while its worker was sweeping');

    // And the inverse, which the snapshot above already carries: the genuinely
    // abandoned row IS gone, within one rotation of its marker lapsing, and the
    // channel was told about it.
    expect(array_values(array_filter(
        $bridge->fannedOut,
        fn (array $event): bool => $event['event'] === 'pusher_internal:member_removed',
    )))->toBe([[
        'channel' => $ghostChannel,
        'event' => 'pusher_internal:member_removed',
        'payload' => ['user_id' => 'ghost'],
    ]]);
});

/**
 * ONE MUTATION IN THE MARKER PASS IS NOT TESTED HERE, AND CANNOT BE.
 *
 * In PresenceStore::heartbeatConnections(),
 *
 *     $markerKeys[] = $this->livenessKey((string) $channel, $connectionId);
 *
 * with the `(string)` removed is EQUIVALENT under this file's typing. The keys
 * come from a PHP array, which casts a numeric string key to int, and
 * livenessKey() declares a `string` parameter in a file with no
 * `declare(strict_types=1)`, so PHP performs exactly the same conversion at the
 * call boundary. No channel name can distinguish them, and presence channels
 * carry the `presence-` prefix that makes a numeric one impossible in the first
 * place.
 *
 * The cast stays because it is the same one snapshot() makes and explains, and
 * because the equivalence rests on a file-level typing mode rather than on
 * anything this line says.
 */
