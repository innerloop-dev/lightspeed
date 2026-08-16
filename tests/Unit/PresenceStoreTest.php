<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Presence\PresenceStore;

/**
 * The presence snapshot is what a client receives the moment it subscribes, so
 * a member missing from it is a member the app never shows.
 *
 * This exists because of a bug that survived three review passes: Redis hash
 * fields come back as a PHP array, PHP casts numeric keys to int, and the
 * snapshot skipped every non-string key. Laravel's default primary key is an
 * integer, so presence looked perfect in the probes (which use word ids) and
 * returned an empty room to every ordinary Laravel app.
 */

function presenceChannel(): string
{
    return 'presence-lightspeed-test-'.bin2hex(random_bytes(6));
}

function presenceStore(): PresenceStore
{
    return app(PresenceStore::class);
}

afterEach(function () {
    foreach ((array) Redis::connection()->keys('*lightspeed:presence:channel:presence-lightspeed-test-*') as $key) {
        // keys() returns prefixed names; del() prefixes again, so strip it.
        $prefix = (string) config('database.redis.options.prefix');
        Redis::connection()->del($prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
    }
});

test('a member whose user id is numeric appears in the snapshot', function () {
    $channel = presenceChannel();
    $store = presenceStore();

    $store->join($channel, 'conn-1', ['user_id' => '42', 'user_info' => ['name' => 'Ann']]);

    $snapshot = $store->snapshot($channel);

    // hash is an object so it always encodes as a JSON object; read it the way
    // a client does, through the encoded frame.
    $hash = json_decode(json_encode($snapshot['hash']), true);

    expect($snapshot['count'])->toBe(1)
        ->and($snapshot['ids'])->toBe(['42'])
        ->and($hash['42'])->toBe(['name' => 'Ann']);
});

test('numeric and word user ids live in the same snapshot', function () {
    $channel = presenceChannel();
    $store = presenceStore();

    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);
    $store->join($channel, 'conn-2', ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);

    $snapshot = $store->snapshot($channel);

    expect($snapshot['count'])->toBe(2)
        ->and($snapshot['ids'])->toContain('7')
        ->and($snapshot['ids'])->toContain('ada');
});

test('an empty snapshot encodes its hash as an object, not an array', function () {
    // Pusher clients index into presence.hash, so [] would be the wrong type.
    //
    // The channel is emptied rather than never filled. Snapshotting a channel
    // that was never touched cannot tell "correctly empty" from "always
    // empty", a snapshot() that returned an empty object unconditionally
    // would pass. So this joins a member, proves the store reports it, and
    // then takes it away.
    $channel = presenceChannel();
    $store = presenceStore();

    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    expect($store->snapshot($channel)['count'])->toBe(1);

    $store->leave($channel, 'conn-1');

    $snapshot = $store->snapshot($channel);

    expect($snapshot['count'])->toBe(0)
        ->and($snapshot['ids'])->toBe([])
        ->and(json_encode($snapshot['hash']))->toBe('{}');
});

test('a hash keyed from zero still encodes as an object', function () {
    // PHP re-casts "0" and "1" back to int keys, and a 0..n-1 key set encodes
    // as a JSON array, which is the wrong type for presence.hash.
    $channel = presenceChannel();
    $store = presenceStore();

    $store->join($channel, 'conn-1', ['user_id' => '0', 'user_info' => ['name' => 'Zero']]);
    $store->join($channel, 'conn-2', ['user_id' => '1', 'user_info' => ['name' => 'One']]);

    $encoded = json_encode($store->snapshot($channel)['hash']);

    expect($encoded)->toStartWith('{')
        ->and(json_decode($encoded, true))->toHaveKeys(['0', '1']);
});
