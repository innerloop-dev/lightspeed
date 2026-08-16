<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Presence\PresenceStore;

/**
 * The evidence a presence row is still real, and how long it is believed.
 *
 * Redis cannot tell a row belonging to a live connection from one left by a
 * worker that was SIGKILLed, and it cannot expire a single hash field, so
 * membership is paired with a per-connection marker that a live worker keeps
 * rewriting. Every number in this file is therefore a correctness boundary
 * rather than a tuning knob:
 *
 *   a marker TTL shorter than several sweeps  a HEALTHY worker reaps its own
 *                                             live members
 *   a marker TTL that is never refreshed      the same, one tick later
 *   a channel TTL shorter than the marker     the rows vanish under a channel
 *                                             that still has members
 *
 * All of it is observed through Redis itself, because the TTL Redis is holding
 * is the only thing that decides any of this.
 */

function relayMutLiveChannel(): string
{
    return 'presence-relaymut-live-'.bin2hex(random_bytes(6));
}

function relayMutLiveStore(): PresenceStore
{
    return app(PresenceStore::class);
}

function relayMutLivenessKey(string $channel, string $connectionId): string
{
    return "lightspeed:presence:channel:{$channel}:live:{$connectionId}";
}

/** The five per-channel keys that share one TTL, in the order the store lists them. */
function relayMutChannelKeys(string $channel): array
{
    return [
        "lightspeed:presence:channel:{$channel}:connections",
        "lightspeed:presence:channel:{$channel}:user-counts",
        "lightspeed:presence:channel:{$channel}:users",
        "lightspeed:presence:channel:{$channel}:colors",
        "lightspeed:presence:channel:{$channel}:actors",
    ];
}

/**
 * Set the presence dials, with every TTL-related key removed first.
 *
 * The published config file sets all of them, so a test about a DEFAULT has to
 * take the key away rather than merely not set it.
 */
function relayMutPresenceDial(array $overrides): void
{
    $presence = config('lightspeed.presence');

    unset(
        $presence['sweep_interval_ms'],
        $presence['connection_ttl_seconds'],
        $presence['channel_ttl_seconds'],
        $presence['color_slots'],
    );

    config()->set('lightspeed.presence', array_merge($presence, $overrides));
}

/** How long Redis will believe one heartbeat for, under the current dials. */
function relayMutMarkerTtl(string $channel): int
{
    relayMutLiveStore()->heartbeatConnections([$channel => ['conn-ttl']]);

    return (int) Redis::connection()->ttl(relayMutLivenessKey($channel, 'conn-ttl'));
}

afterEach(function () {
    foreach ((array) Redis::connection()->keys('*lightspeed:presence:channel:presence-relaymut-*') as $key) {
        $prefix = (string) config('database.redis.options.prefix');
        Redis::connection()->del($prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
    }
});

test('a heartbeat vouches for real connection ids and for nothing else', function () {
    // The sweeper hands this whatever its channel manager holds. A marker
    // written under a blank or non-string id vouches for no connection at all,
    // and it is a key with a TTL that nothing will ever clean up; worse, it
    // makes the list of live markers disagree with the list of rows, which is
    // the comparison the whole reap is built on.
    $channel = relayMutLiveChannel();

    relayMutLiveStore()->heartbeatConnections([$channel => ['', 123, 'conn-real']]);

    expect(Redis::connection()->exists(relayMutLivenessKey($channel, 'conn-real')))->toBe(1)
        ->and(Redis::connection()->exists(relayMutLivenessKey($channel, '')))->toBe(0)
        ->and(Redis::connection()->exists(relayMutLivenessKey($channel, '123')))->toBe(0);
});

test('a heartbeat refreshes every one of the channel keys that share a TTL', function () {
    // This TTL is the backstop for the one case markers cannot cover: every
    // worker on a channel dying at once leaves rows nobody is left to walk.
    // A key left out of the refresh expires under a channel that is still
    // busy, and because these five are one logical record, losing any of them
    // is losing the channel: user-counts without users is a room of members
    // with no payloads, colors without actors leaks palette slots forever.
    relayMutPresenceDial(['color_slots' => 4]);

    $channel = relayMutLiveChannel();
    $store = relayMutLiveStore();

    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    // Wind every one of them down to something a heartbeat has to undo.
    foreach (relayMutChannelKeys($channel) as $key) {
        Redis::connection()->expire($key, 5);
    }

    $store->heartbeatChannel($channel);

    $ttls = [];

    foreach (relayMutChannelKeys($channel) as $key) {
        $ttls[$key] = (int) Redis::connection()->ttl($key);
    }

    expect(array_values($ttls))->toBe([3600, 3600, 3600, 3600, 3600]);
});

test('how long one heartbeat is believed for', function () {
    // The floor is the relationship, not the politeness: the marker must
    // outlive several sweeps, so it is derived from the sweep interval and
    // only then compared with what the operator asked for. Every combination
    // below is a different arm of that derivation, and each one is a way for a
    // healthy worker to reap its own live members if it moves.
    $cases = [
        // Stock configuration: three sweeps is 30s, the configured 60 wins.
        'stock' => [[], 60],
        // A sweep interval that does not divide evenly rounds UP, because
        // rounding down would put the marker inside three ticks.
        'uneven interval' => [['sweep_interval_ms' => 30400, 'connection_ttl_seconds' => 1], 93],
        // One millisecond over a second is two seconds of sweep, for the same
        // reason.
        'just over a second' => [['sweep_interval_ms' => 1001, 'connection_ttl_seconds' => 1], 6],
        // The derived floor wins whenever the operator asks for less than it.
        'derived floor wins' => [['connection_ttl_seconds' => 1], 30],
        // Dials that are not numbers fall back to the floor rather than
        // crashing the worker that reads them.
        'unreadable ttl' => [['connection_ttl_seconds' => 'later'], 30],
        'unreadable interval' => [['sweep_interval_ms' => 'often', 'connection_ttl_seconds' => 1], 3],
    ];

    $observed = [];

    foreach ($cases as $name => [$dials, $expected]) {
        relayMutPresenceDial($dials);
        $observed[$name] = [relayMutMarkerTtl(relayMutLiveChannel()), $expected];
    }

    foreach ($observed as $name => [$actual, $expected]) {
        expect($actual)->toBe($expected, "the marker TTL for [{$name}] moved");
    }
});

test('how long a channel outlives the last worker that could sweep it', function () {
    // Only reached when NO worker anywhere holds a subscriber, so it is what
    // reclaims a channel after a whole-fleet restart. It is kept at least
    // twice the marker TTL: an operator who raises the marker TTL past the
    // channel TTL would otherwise have the rows deleted out from under
    // connections that are demonstrably still there.
    $store = relayMutLiveStore();

    $cases = [
        // Stock: twice the 60s marker is 120, and the configured hour wins.
        'stock' => [[], 3600],
        // An operator who raises the marker past an hour raises this with it.
        'long marker' => [['connection_ttl_seconds' => 5000], 10000],
        // A dial that is not a number falls back to the derived floor.
        'unreadable' => [['channel_ttl_seconds' => 'forever'], 120],
    ];

    $observed = [];

    foreach ($cases as $name => [$dials, $expected]) {
        relayMutPresenceDial($dials);

        $channel = relayMutLiveChannel();

        // The refresh is an EXPIRE, which does nothing to a key that is not
        // there, so the channel needs a row before it can have a lifetime.
        Redis::connection()->hset(relayMutChannelKeys($channel)[0], 'conn-1', '7');
        $store->heartbeatChannel($channel);

        $observed[$name] = [(int) Redis::connection()->ttl(relayMutChannelKeys($channel)[0]), $expected];
    }

    foreach ($observed as $name => [$actual, $expected]) {
        expect($actual)->toBe($expected, "the channel TTL for [{$name}] moved");
    }
});

test('a connection nobody is vouching for is unwound through the ordinary leave', function () {
    // A ghost is not a row to delete. It is a reference count to decrement, a
    // colour slot to release and a member the channel has to be told about, so
    // the reap reports the user id and the snapshot loses the member.
    relayMutPresenceDial(['color_slots' => 4]);

    $channel = relayMutLiveChannel();
    $store = relayMutLiveStore();

    $store->join($channel, 'conn-live', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    $store->join($channel, 'conn-dead', ['user_id' => 'seven', 'user_info' => ['name' => 'Seven']]);

    // The worker holding conn-dead was killed: its marker lapses, and nothing
    // rewrites it.
    Redis::connection()->del(relayMutLivenessKey($channel, 'conn-dead'));

    expect($store->reapAbandoned($channel))->toBe(['seven'])
        ->and($store->snapshot($channel)['ids'])->toBe(['ada'])
        // Colours are reference counted too, so a reap that only deleted the
        // row would leak a palette slot on every killed worker.
        ->and(Redis::connection()->hlen("lightspeed:presence:channel:{$channel}:colors"))->toBe(1);

    // Idempotent, because several workers sweep the same channel: the second
    // one to reach a ghost finds no row and reports nothing.
    expect($store->reapAbandoned($channel))->toBe([]);
});

test('a row filed under a blank connection id is not something the reaper may unwind', function () {
    // A blank id is what a half-finished join or a foreign writer leaves
    // behind, and it names no connection, so no worker can ever vouch for it.
    // Reaping it would decrement a reference count on behalf of a connection
    // that never existed and evict a member who is still in the room.
    $channel = relayMutLiveChannel();
    $store = relayMutLiveStore();

    $store->join($channel, '', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    $store->join($channel, 'conn-live', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);

    Redis::connection()->del(relayMutLivenessKey($channel, ''));

    expect($store->reapAbandoned($channel))->toBe([])
        ->and($store->snapshot($channel)['ids'])->toBe(['ada'])
        ->and(Redis::connection()->hexists("lightspeed:presence:channel:{$channel}:connections", ''))->toBeTrue();
});

test('the colour palette is off unless it is turned on, and is read as a number', function () {
    // Zero disables colour assignment entirely, and the config promises that an
    // app which turns the feature off actually gets it turned off: a palette of
    // one is not "off", it is every member sharing colour 0 and a colorIndex
    // appearing in payloads that are not supposed to have one.
    $store = relayMutLiveStore();

    relayMutPresenceDial([]);
    $off = $store->join(relayMutLiveChannel(), 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    relayMutPresenceDial(['color_slots' => 'lots']);
    $unreadable = $store->join(relayMutLiveChannel(), 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    relayMutPresenceDial(['color_slots' => 4]);
    $on = $store->join(relayMutLiveChannel(), 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    expect($off['member']['user_info'])->toBe(['name' => 'Seven'])
        ->and($unreadable['member']['user_info'])->toBe(['name' => 'Seven'])
        ->and($on['member']['user_info'])->toBe(['name' => 'Seven', 'colorIndex' => 0]);
});
