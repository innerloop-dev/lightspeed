<?php

use Lightspeed\Channels\ChannelManager;
use Lightspeed\Connections\ConnectionClosedDispatcher;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceSweeper;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The presence sweeper is the only thing in the system that removes a row left
 * behind by a worker that was killed, and a presence row is a REFERENCE COUNT:
 * a survivor is a permanent phantom member with no decrement left anywhere.
 *
 * So every dial here is a correctness dial. A sweep that starts one channel
 * late, gives up on the rest of its slice after one bad channel, or arms its
 * timer at a period nobody asked for goes on running, logging nothing, while
 * some subset of channels is never reconciled. This file pins the ones the
 * rotation tests do not reach: the boot sequence, the two ceilings, the slice
 * offset, and what a failed heartbeat costs.
 */

/** Records what the sweep vouched for and reaps whatever it is told to. */
class RelayMutSweepStore extends PresenceStore
{
    /** @var list<string> channels whose own TTL was refreshed, in order */
    public array $heartbeats = [];

    /**
     * Every marker pipeline the sweep ASKED for, failed ones included, in
     * order. A pass that goes on issuing writes into an unreachable Redis is
     * invisible in a record that only keeps the ones that worked.
     *
     * @var list<array<string, list<string>>>
     */
    public array $markerAttempts = [];

    /** @var list<array<string, list<string>>> the marker chunks that were written */
    public array $markerChunks = [];

    /**
     * The sweep's calls in the order it made them: ['markers', channels] for a
     * pipeline and ['channel', name] for a channel-TTL refresh. What separates
     * the early pass over every channel from the write each budgeted channel
     * gets next to its own reap is only their ORDER.
     *
     * @var list<array{0: string, 1: mixed}>
     */
    public array $calls = [];

    /** @var array<string, list<string>> user ids reapAbandoned() reports per channel */
    public array $ghosts = [];

    /** Channel whose channel-TTL refresh throws, as an unreachable Redis would. */
    public ?string $unreachableChannel = null;

    /** Channel whose marker chunk throws, as an unreachable Redis would. */
    public ?string $unvouchableChannel = null;

    /** Every marker pipeline throws, as a Redis that is simply gone would. */
    public bool $markersUnreachable = false;

    public function __construct()
    {
    }

    public function heartbeatConnections(array $connectionIdsByChannel): void
    {
        $this->markerAttempts[] = $connectionIdsByChannel;
        $this->calls[] = ['markers', array_keys($connectionIdsByChannel)];

        if ($this->markersUnreachable
            || ($this->unvouchableChannel !== null && array_key_exists($this->unvouchableChannel, $connectionIdsByChannel))) {
            throw new \RuntimeException('presence markers could not reach Redis');
        }

        $this->markerChunks[] = $connectionIdsByChannel;
    }

    public function heartbeatChannel(string $channel): void
    {
        $this->calls[] = ['channel', $channel];

        if ($channel === $this->unreachableChannel) {
            throw new \RuntimeException('presence heartbeat could not reach Redis');
        }

        $this->heartbeats[] = $channel;
    }

    public function reapAbandoned(string $channel): array
    {
        return $this->ghosts[$channel] ?? [];
    }
}

/** Records the pusher events a sweep announced, instead of fanning them out. */
class RelayMutSweepDelivery extends Delivery
{
    /** @var list<array{0: string, 1: string, 2: mixed}> channel, event, data */
    public array $events = [];

    public function __construct()
    {
    }

    public function broadcastPusherEvent(string $channel, string $event, mixed $data, ?int $exceptFd = null): int
    {
        $this->events[] = [$channel, $event, $data];

        return 1;
    }
}

/** Records the runtime log lines instead of writing them to stdout. */
class RelayMutSweepLogger extends RuntimeLogger
{
    /** @var list<array{0: string, 1: array}> action and fields, in order */
    public array $lines = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->lines[] = [$action, $fields];
    }
}

class RelayMutSweepSwooleServer extends SwooleServer
{
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
        return true;
    }
}

/**
 * A sweeper wired to doubles, plus the pieces a test needs to steer it.
 *
 * @return array{0: PresenceSweeper, 1: RelayMutSweepStore, 2: RelayMutSweepDelivery, 3: RelayMutSweepLogger, 4: AttachedServer}
 */
function relayMutSweeper(bool $attach = true): array
{
    $store = new RelayMutSweepStore();
    $delivery = new RelayMutSweepDelivery();
    $logger = new RelayMutSweepLogger();
    $attached = new AttachedServer();

    if ($attach) {
        $attached->attach(RelayMutSweepSwooleServer::make());
    }

    $sweeper = new PresenceSweeper(
        app(ChannelManager::class),
        $store,
        app(ConnectionId::class),
        $attached,
        $delivery,
        $logger,
        app(ConnectionClosedDispatcher::class),
    );

    return [$sweeper, $store, $delivery, $logger, $attached];
}

/** Put `$count` presence channels on this worker, one member each, in order. */
function relayMutPresenceChannels(int $count, string $tag = 'a'): array
{
    $channels = [];

    for ($i = 0; $i < $count; $i++) {
        $fd = 10_000 + ($i * 7);
        $channel = sprintf('presence-relaymut-%s-%03d', $tag, $i);

        app(ChannelManager::class)->connect($fd, "socket-{$fd}", []);
        app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => "u{$fd}", 'user_info' => []]);

        $channels[] = $channel;
    }

    return $channels;
}

afterEach(function () {
    foreach (Timer::list() as $timerId) {
        Timer::clear($timerId);
    }
});

// ---------------------------------------------------------------------------
// ConnectionId: which connection a presence row is counted under
// ---------------------------------------------------------------------------

test('a presence connection id prefers the socket id its caller already had', function () {
    // The close path holds the socket id from the disconnect result, and by
    // then the channel manager no longer does. Resolving through the manager
    // anyway yields the fd instead, so the leave() would be issued for a
    // connection id that no join ever wrote, and the real row would stay: a
    // permanent phantom member for every orderly close.
    $channels = app(ChannelManager::class);
    $identity = app(WorkerContext::class)->currentProcessKey();
    $connectionId = app(ConnectionId::class);

    $channels->connect(31, 'still-registered');

    expect($connectionId->presenceConnectionId(31, 'handed-in'))->toBe("{$identity}:socket:handed-in")
        // With nothing handed in, the manager is the source...
        ->and($connectionId->presenceConnectionId(31))->toBe("{$identity}:socket:still-registered")
        // ...and the fd is the last resort, for a connection that never
        // finished its handshake and so never got a socket id at all.
        ->and($connectionId->presenceConnectionId(99))->toBe("{$identity}:socket:99");
});

// ---------------------------------------------------------------------------
// Boot: attaching the server, and never arming two timers
// ---------------------------------------------------------------------------

test('booting the sweeper attaches the server it will sweep against, and replaces any timer', function () {
    // The sweep returns 0 without a server, silently, so a boot that failed to
    // attach is a worker that never reconciles anything. And a worker restart
    // runs this same path again in the same process: a second timer would
    // sweep every channel twice a tick forever, while the first one's id is
    // gone and can never be cleared.
    [$sweeper, $store, , , $attached] = relayMutSweeper(attach: false);
    $swoole = RelayMutSweepSwooleServer::make();

    relayMutPresenceChannels(1);

    $sweeper->bootPresenceSweeper($swoole);

    expect($attached->get())->toBe($swoole);

    $first = readLightspeedProperty($sweeper, 'presenceSweepTimerId');

    $sweeper->bootPresenceSweeper($swoole);
    $second = readLightspeedProperty($sweeper, 'presenceSweepTimerId');

    expect($second)->not->toBe($first)
        ->and(Timer::exists($first))->toBeFalse()
        ->and(Timer::exists($second))->toBeTrue();

    // The attachment is what the timer body needs, so prove the sweep can now
    // actually reach a channel rather than returning early.
    $sweeper->sweepPresence();

    expect($store->heartbeats)->toHaveCount(1);

    $sweeper->shutdownPresenceSweeper();

    expect(Timer::exists($second))->toBeFalse();
});

test('the presence sweep interval is 10 seconds, floors at 100ms, and is read as a number', function () {
    // The floor is the other half of the marker TTL relationship: the marker
    // must outlive several ticks or a healthy worker reaps its own live
    // members. An interval of 0 is also a Swoole timer with no period, which
    // is a busy loop on the event loop serving every socket this worker holds.
    [$sweeper] = relayMutSweeper();

    $intervals = [];

    foreach ([null, 50, 100, 'often', 30000] as $dial) {
        $presence = config('lightspeed.presence');

        if ($dial === null) {
            unset($presence['sweep_interval_ms']);
        } else {
            $presence['sweep_interval_ms'] = $dial;
        }

        config()->set('lightspeed.presence', $presence);

        $sweeper->bootPresenceSweeper(RelayMutSweepSwooleServer::make());
        $intervals[] = Timer::info(readLightspeedProperty($sweeper, 'presenceSweepTimerId'))['interval'];
    }

    $sweeper->shutdownPresenceSweeper();

    expect($intervals)->toBe([10000, 100, 100, 100, 30000]);
});

// ---------------------------------------------------------------------------
// The budget, and where a slice starts
// ---------------------------------------------------------------------------

test('the sweep budget is 100 channels a tick, floors at 1, and is read as a number', function () {
    // Two Redis round trips per channel on a loop with `enable_coroutine` off,
    // so the ceiling is real. But a budget of 0 does not slow the sweep down,
    // it stops it on every channel forever, and a budget read as text is a
    // ceiling a tick can never reach.
    [$sweeper] = relayMutSweeper();

    relayMutPresenceChannels(101);

    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 0);
    expect(driveLightspeed($sweeper, 'presenceSweepSlice', [app(ChannelManager::class)->presenceSubscriptions()]))->toHaveCount(1);

    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 'plenty');
    expect(driveLightspeed($sweeper, 'presenceSweepSlice', [app(ChannelManager::class)->presenceSubscriptions()]))->toHaveCount(1);

    // And the default, which is what almost every deployment runs.
    $presence = config('lightspeed.presence');
    unset($presence['sweep_max_channels_per_tick']);
    config()->set('lightspeed.presence', $presence);

    expect(driveLightspeed($sweeper, 'presenceSweepSlice', [app(ChannelManager::class)->presenceSubscriptions()]))->toHaveCount(100);
});

test('the first slice of a rotation starts at the first channel', function () {
    // Nothing else ever sweeps the channel a stalled rotation skips. Starting
    // at an offset of one because "not found" was read as a number leaves the
    // first channel unswept on the first tick of every worker, and permanently
    // on a worker whose channel set keeps changing under it.
    [$sweeper] = relayMutSweeper();

    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 1);

    $channels = relayMutPresenceChannels(3);

    expect(array_keys(driveLightspeed($sweeper, 'presenceSweepSlice', [app(ChannelManager::class)->presenceSubscriptions()])))->toBe([$channels[0]]);
});

test('a tick that exactly fills the budget leaves no resume point behind', function () {
    // The boundary, not a nearby value: at count == budget every channel was
    // swept, so there is nothing to resume from. A pointer left set here is a
    // pointer into a list that a later, larger set of channels no longer
    // describes, and the next rotation starts in the middle of it.
    [$sweeper] = relayMutSweeper();

    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    $channels = relayMutPresenceChannels(2);

    expect(array_keys(driveLightspeed($sweeper, 'presenceSweepSlice', [app(ChannelManager::class)->presenceSubscriptions()])))->toBe($channels)
        ->and(readLightspeedProperty($sweeper, 'presenceSweepResumeFrom'))->toBeNull();
});

// ---------------------------------------------------------------------------
// The marker pass, which the budget does not bound
// ---------------------------------------------------------------------------

test('every channel this worker holds has its markers rewritten on every tick, whatever the budget', function () {
    // The bug this split exists for. Markers race a wall-clock TTL: a channel
    // whose markers are only rewritten on the tick that sweeps it waits
    // ceil(channels / budget) ticks, and past the point where that overtakes
    // connection_ttl_seconds, the markers of CONNECTED members lapse and
    // another worker reaps them. So the reap rotates and the markers do not.
    [$sweeper, $store] = relayMutSweeper();

    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    // Small enough that the pass is several full chunks and a partial one: a
    // marker pass that dropped either kind would still cover every channel at
    // the shipped chunk size, where this fixture is a single pipeline.
    config()->set('lightspeed.presence.sweep_chunk_size', 2);

    $channels = relayMutPresenceChannels(7, 'markers');

    $sweeper->sweepPresence();

    // OUT-OF-SLICE CHANNELS ARE THE CLAIM. The five channels this tick does not
    // reap can only have been written by the pass over everything, because the
    // write each budgeted channel gets next to its own reap covers that channel
    // alone. Asserting "every channel was written" without them would pass on a
    // sweep that had no pass at all.
    $written = [];

    foreach ($store->markerChunks as $chunk) {
        foreach ($chunk as $channel => $connectionIds) {
            $written[$channel] = $connectionIds;
        }
    }

    foreach (array_slice($channels, 2) as $outOfSlice) {
        expect($written)->toHaveKey($outOfSlice);
    }

    // And every channel is vouched for BEFORE the first reap of the tick, not
    // merely somewhere in it.
    $vouchedBeforeFirstReap = [];

    foreach ($store->calls as [$kind, $value]) {
        if ($kind !== 'markers') {
            break;
        }

        $vouchedBeforeFirstReap = array_merge($vouchedBeforeFirstReap, $value);
    }

    expect(array_values(array_unique($vouchedBeforeFirstReap)))->toBe($channels)
        // Vouched for by connection id rather than by fd, because the id is
        // what the marker key is built from and a marker under the wrong name
        // vouches for nothing.
        ->and($written[$channels[0]])->toBe([app(ConnectionId::class)->presenceConnectionId(10_000)])
        // ...while the rotation still bounds the expensive half.
        ->and($store->heartbeats)->toHaveCount(2);
});

test('a budgeted channel is vouched for again immediately before it is reaped', function () {
    // Adjacency, not merely order. Between the early pass and a given reap sit
    // the whole notification drain (application handlers, inline) and every
    // earlier channel's reap (an HKEYS plus an EXISTS per member, blocking). On
    // a slow tick that is seconds, and a vouch that is seconds old is a vouch
    // racing the same wall clock this whole fix is about. So each channel's
    // markers are rewritten one round trip before its own reap.
    [$sweeper, $store] = relayMutSweeper();

    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);
    config()->set('lightspeed.presence.sweep_chunk_size', 500);

    $channels = relayMutPresenceChannels(4, 'adjacent');

    $sweeper->sweepPresence();

    // The early pass is one pipeline for all four; then, per swept channel, its
    // own marker write and its own channel-TTL refresh, in that order.
    expect($store->calls)->toBe([
        ['markers', $channels],
        ['markers', [$channels[0]]],
        ['channel', $channels[0]],
        ['markers', [$channels[1]]],
        ['channel', $channels[1]],
    ]);
});

test('the marker pass is chunked, and the chunk size is the dial that says how much', function () {
    // Not a budget: nothing is left out of the pass, it is only split across
    // pipelines so a worker holding a pathological number of connections does
    // not hand Redis one write it has to buffer whole. A chunk size read as 0
    // or as text would be a pipeline per connection, which is the round trip
    // per connection this pass exists to avoid.
    [$sweeper, $store] = relayMutSweeper();

    // One channel a tick, so exactly one adjacency write follows the pass and
    // the pass itself is what the counts below are about.
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 1);

    $channels = relayMutPresenceChannels(5, 'chunks');

    config()->set('lightspeed.presence.sweep_chunk_size', 2);
    $sweeper->sweepPresence();

    expect(array_map('array_keys', $store->markerChunks))->toBe([
        [$channels[0], $channels[1]],
        [$channels[2], $channels[3]],
        [$channels[4]],
        // The swept channel's own write, next to its reap.
        [$channels[0]],
    ]);

    // The default is the connection sweep's, and the floor is 1: one pipeline
    // for five connections either way here, but a 0 would mean five.
    foreach ([null, 0, 'plenty'] as $dial) {
        $presence = config('lightspeed.presence');

        if ($dial === null) {
            unset($presence['sweep_chunk_size']);
        } else {
            $presence['sweep_chunk_size'] = $dial;
        }

        config()->set('lightspeed.presence', $presence);

        $store->markerChunks = [];
        $sweeper->sweepPresence();

        expect($store->markerChunks)->toHaveCount(
            $dial === null ? 2 : 6,
            'a chunk size of ['.var_export($dial, true).'] split the pass wrongly',
        );
    }
});

test('a marker chunk that could not be written ends the pass, and is reported once', function () {
    // The chunk after a failed one is another blocking round trip into the same
    // outage, on the event loop serving every connection this worker has, which
    // is the rule ConnectionSweeper states for its own pass. A marker outlives
    // many ticks, so nothing is lost by waiting for the next one; what must not
    // happen is a reap of the channels nobody managed to vouch for.
    [$sweeper, $store, $delivery, $logger] = relayMutSweeper();

    config()->set('lightspeed.presence.sweep_chunk_size', 1);

    $channels = relayMutPresenceChannels(3, 'unvouchable');

    $store->unvouchableChannel = $channels[1];
    $store->ghosts = [
        $channels[0] => ['ghost-of-a'],
        $channels[1] => ['ghost-of-b'],
        $channels[2] => ['ghost-of-c'],
    ];

    expect($sweeper->sweepPresence())->toBe(1)
        // Only the channel written before the failure was reaped...
        ->and($delivery->events)->toBe([[$channels[0], 'pusher_internal:member_removed', ['user_id' => 'ghost-of-a']]])
        ->and($store->heartbeats)->toBe([$channels[0]])
        // ...the failing chunk was the LAST one attempted, so the third channel
        // was never written to a Redis that had just proved unreachable...
        ->and($store->markerAttempts)->toBe([
            [$channels[0] => [app(ConnectionId::class)->presenceConnectionId(10_000)]],
            [$channels[1] => [app(ConnectionId::class)->presenceConnectionId(10_007)]],
            // The survivor's adjacency write, next to its own reap.
            [$channels[0] => [app(ConnectionId::class)->presenceConnectionId(10_000)]],
        ]);

    // ...and one line, naming how many channels went unvouched-for rather than
    // one line per channel behind the same unreachable Redis.
    expect($logger->lines)->toBe([['error', [
        'channels' => 2,
        'reason' => 'presence-heartbeat-failed',
        'message' => 'presence markers could not reach Redis',
    ]]]);
});

test('a Redis that is simply gone costs one pipeline, not one per chunk', function () {
    // The whole-outage case: with every pipeline failing, the pass must stop at
    // the first, and nothing may be reaped, because nothing is vouched for.
    [$sweeper, $store, $delivery, $logger] = relayMutSweeper();

    config()->set('lightspeed.presence.sweep_chunk_size', 1);

    $channels = relayMutPresenceChannels(4, 'gone');

    $store->markersUnreachable = true;
    $store->ghosts = [$channels[0] => ['ghost-of-a'], $channels[1] => ['ghost-of-b']];

    expect($sweeper->sweepPresence())->toBe(0)
        ->and($store->markerAttempts)->toHaveCount(1)
        ->and($store->heartbeats)->toBe([])
        ->and($delivery->events)->toBe([])
        ->and($logger->lines)->toBe([['error', [
            'channels' => 4,
            'reason' => 'presence-heartbeat-failed',
            'message' => 'presence markers could not reach Redis',
        ]]]);
});

// ---------------------------------------------------------------------------
// A channel this worker could not vouch for
// ---------------------------------------------------------------------------

test('a channel whose heartbeat failed is reported and skipped, and the rest of the slice is still swept', function () {
    // The heartbeat is a PRECONDITION of the reap: this worker's own members
    // are unvouched-for until it succeeds, so reaping after a failed heartbeat
    // removes the live members of the worker doing the reaping. Hence skip,
    // not stop: the other channels in the slice have working heartbeats, and
    // giving up on them turns one unreachable channel into a sweep that never
    // reconciles anything after it.
    [$sweeper, $store, $delivery, $logger] = relayMutSweeper();

    $channels = relayMutPresenceChannels(2, 'fail');

    $store->unreachableChannel = $channels[0];
    $store->ghosts = [$channels[0] => ['ghost-of-a'], $channels[1] => ['ghost-of-b']];

    $reaped = $sweeper->sweepPresence();

    expect($reaped)->toBe(1)
        // The channel it could not vouch for was not reaped...
        ->and($delivery->events)->toBe([[$channels[1], 'pusher_internal:member_removed', ['user_id' => 'ghost-of-b']]])
        // ...and the one after it in the slice still was.
        ->and($store->heartbeats)->toBe([$channels[1]]);

    // Silence here is a worker that quietly stops reconciling one channel, so
    // the line has to name the channel, why, and what Redis said.
    expect($logger->lines)->toBe([['error', [
        'channel' => $channels[0],
        'reason' => 'presence-heartbeat-failed',
        'message' => 'presence heartbeat could not reach Redis',
    ]]]);
});
