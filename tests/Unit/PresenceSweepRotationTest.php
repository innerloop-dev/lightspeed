<?php

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
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The presence sweep's bound, and the rotation that makes a bound safe.
 *
 * A presence row is a REFERENCE COUNT, so a member left behind by a worker that
 * was SIGKILLed is not a stale entry the next join overwrites: it is a
 * permanent phantom with no decrement left anywhere. This sweep is the only
 * thing that removes one, which is what makes "which channels does a tick
 * reach" a correctness question rather than a performance one.
 *
 * The bound and the rotation were tested at one level: a tick visits no more
 * than the budget, and three ticks at two channels each cover five channels.
 * FIVE MUTATIONS INSIDE THAT SAME METHOD SURVIVED THE WHOLE SUITE, and each is
 * a way for a channel to stop being swept without anything reporting it:
 *
 *   1. the resume point stops wrapping         a slice that runs off the end
 *                                              resets to the beginning, so the
 *                                              rotation stalls in a cycle that
 *                                              never reaches the tail
 *   2. the resume point is not cleared when     a stale pointer survives into a
 *      every channel fits in one tick          later, larger set of channels
 *   3. the budget loses its `max(1, ...)`      a configured 0 sweeps NOTHING,
 *                                              ever, on any channel
 *   4. the interval loses its `max(100, ...)`  a configured 0 is a timer with
 *                                              no period
 *   5. `$start === false ? 0 : $start`         EQUIVALENT, see the note at the
 *      becomes `(int) $start`                  end of this file
 *
 * Every one of them is silent. The sweep goes on running, the log says nothing,
 * the counters move, and some subset of channels is simply never reconciled.
 */

/** Records which channels a sweep reached on its rotation, and reaps nothing. */
class RotationPresenceStore extends PresenceStore
{
    /** @var list<string> channels this store was asked to refresh, in order */
    public array $heartbeats = [];

    /** @var list<string> channels whose markers were rewritten, every tick */
    public array $markers = [];

    public function __construct()
    {
    }

    /**
     * The every-tick half, which the rotation does not bound: recorded
     * separately so the assertions below stay about the rotation.
     */
    public function heartbeatConnections(array $connectionIdsByChannel): void
    {
        foreach (array_keys($connectionIdsByChannel) as $channel) {
            $this->markers[] = (string) $channel;
        }
    }

    public function heartbeatChannel(string $channel): void
    {
        $this->heartbeats[] = $channel;
    }

    public function reapAbandoned(string $channel): array
    {
        return [];
    }
}

class RotationSwooleServer extends SwooleServer
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

class RotationOctaneWorker extends Lightspeed\Http\OctaneWorker
{
    public function __construct()
    {
    }

    public function isBooted(): bool
    {
        return true;
    }
}

/** A composed Server whose presence store counts, with a socket server attached. */
function rotationServer(RotationPresenceStore $store): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = [
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => $store,
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new RotationOctaneWorker(),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    // The sweep returns early without one, so every assertion below would be
    // about an empty list rather than about the rotation.
    lightspeedSurface($server, 'attach')->attach(RotationSwooleServer::make());

    return $server;
}

/** Put `$count` presence channels on this worker, one member each. */
function rotationChannels(int $count): array
{
    $channels = [];

    for ($i = 0; $i < $count; $i++) {
        $fd = random_int(1000, 999999);
        $channel = sprintf('presence-rotation-%02d-%s', $i, bin2hex(random_bytes(4)));

        app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
        app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => "u{$fd}", 'user_info' => []]);

        $channels[] = $channel;
    }

    return $channels;
}

test('the rotation stays even over many ticks, because a slice that runs off the end wraps', function () {
    // Five channels, two per tick, five ticks: ten visits, so every channel is
    // owed exactly two.
    //
    // The mutant that drops the modulo on the RESUME POINT (rather than on the
    // slice) leaves the third tick with nowhere to resume from, so it restarts
    // at the beginning and the rotation settles into a cycle over the first
    // four channels. The existing three-tick test does not see it, because
    // three ticks is exactly the point at which the damage begins.
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    $store = new RotationPresenceStore();
    $server = rotationServer($store);
    rotationChannels(5);

    for ($tick = 0; $tick < 5; $tick++) {
        driveLightspeed($server, 'sweepPresence', []);
    }

    $visits = array_count_values($store->heartbeats);

    expect($store->heartbeats)->toHaveCount(10)
        ->and($visits)->toHaveCount(5)
        ->and(array_unique(array_values($visits)))->toBe([2]);
});

test('no channel waits longer than one full rotation for its turn', function () {
    // The property the budget is only safe under. Seven channels at two a tick
    // is four ticks to come all the way round, so by the fourth every channel
    // must have been visited at least once. A rotation that stalls leaves one
    // with permanent ghost members.
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    $store = new RotationPresenceStore();
    $server = rotationServer($store);
    $channels = rotationChannels(7);

    for ($tick = 0; $tick < 4; $tick++) {
        driveLightspeed($server, 'sweepPresence', []);
    }

    foreach ($channels as $channel) {
        expect($store->heartbeats)->toContain($channel);
    }
});

test('a tick that covers every channel clears the resume point', function () {
    // Otherwise a pointer left over from a busier moment survives into the next
    // set of channels, and the rotation restarts from the middle of a list it
    // no longer describes.
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    $store = new RotationPresenceStore();
    $server = rotationServer($store);
    rotationChannels(5);

    // Enough channels to need slicing: a resume point is now set.
    driveLightspeed($server, 'sweepPresence', []);
    expect(readLightspeedProperty($server, 'presenceSweepResumeFrom'))->not->toBeNull();

    // Now few enough to fit in one tick.
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 100);
    driveLightspeed($server, 'sweepPresence', []);

    expect(readLightspeedProperty($server, 'presenceSweepResumeFrom'))->toBeNull();
});

test('a budget configured to zero still sweeps, because a sweep of nothing is no sweep', function () {
    // `max(1, ...)` and not a bare read. A budget of 0 does not slow the sweep
    // down: it stops it, permanently, on every channel, while the timer goes on
    // ticking and the log goes on saying nothing. Every abandoned presence row
    // on the worker then stays for good.
    foreach ([0, -1, -1000] as $configured) {
        config()->set('lightspeed.presence.sweep_max_channels_per_tick', $configured);

        $store = new RotationPresenceStore();
        $server = rotationServer($store);
        rotationChannels(3);

        driveLightspeed($server, 'sweepPresence', []);

        expect($store->heartbeats)->not->toBeEmpty("a budget of {$configured} must still sweep something");
    }
});

test('the sweep interval keeps its floor, whatever it is configured to', function () {
    // The floor is what keeps the relationship with the presence marker TTL
    // sane: the marker has to outlive several ticks or a healthy worker reaps
    // its own live members. A configured 0 is also a Swoole timer with no
    // period, which is a busy loop on the event loop that serves every
    // connection this worker holds.
    $server = rotationServer(new RotationPresenceStore());

    foreach ([0, -1, 1, 99] as $configured) {
        config()->set('lightspeed.presence.sweep_interval_ms', $configured);

        expect(driveLightspeed($server, 'presenceSweepIntervalMs', []))
            ->toBeGreaterThanOrEqual(100, "an interval of {$configured} must not go below the floor");
    }

    // And a configured value above the floor is honoured, so the floor is a
    // floor rather than a constant.
    config()->set('lightspeed.presence.sweep_interval_ms', 30000);

    expect(driveLightspeed($server, 'presenceSweepIntervalMs', []))->toBe(30000);
});

test('a budget above the channel count is honoured rather than clamped', function () {
    // The positive control for the budget: the `max(1, ...)` guards the floor
    // only, and a sweep that visited one channel per tick regardless would pass
    // every assertion above while starving a busy worker just as badly.
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 100);

    $store = new RotationPresenceStore();
    $server = rotationServer($store);
    rotationChannels(6);

    driveLightspeed($server, 'sweepPresence', []);

    expect($store->heartbeats)->toHaveCount(6);
});

/**
 * ONE MUTATION IN THIS METHOD IS NOT TESTED HERE, AND CANNOT BE.
 *
 *     $offset = $start === false ? 0 : $start;
 *
 * rewritten as `$offset = (int) $start;` is EQUIVALENT. `$channels` is
 * `array_keys()` of the subscription map, so it is a list and `array_search()`
 * returns either `false` or an int index; `(int) false` is 0, which is exactly
 * what the conditional produces. No input distinguishes them, so no test can,
 * and writing one that appeared to would only be testing something else.
 *
 * It is written the long way because the two values mean different things (a
 * resume point that was not found, versus the first channel) and because the
 * equivalence rests on `$channels` being a list, which is a property of the
 * line above it rather than of this one.
 */
