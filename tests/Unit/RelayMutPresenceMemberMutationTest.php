<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Presence\PresenceStore;

/**
 * What a presence row says, and what the store says about it.
 *
 * A presence row is a REFERENCE COUNT shared by every worker, so the two
 * booleans this file is mostly about (was this member announced, did this
 * member really leave) are the difference between a room that shows who is in
 * it and one that shows a collaborator who went home an hour ago. The payload
 * matters just as much: Pusher clients index the snapshot hash by user id, and
 * an id that arrives as an int or a payload decoded as an object is a room that
 * renders empty in an ordinary Laravel app.
 *
 * Real Redis, real Lua. The scripts are the thing under test as much as the PHP
 * around them, and a fake would be free to agree with whatever this file
 * assumed.
 */

function relayMutMemberChannel(): string
{
    return 'presence-relaymut-member-'.bin2hex(random_bytes(6));
}

function relayMutMemberStore(): PresenceStore
{
    return app(PresenceStore::class);
}

/** The raw Redis key the store writes member payloads to, without the suite prefix. */
function relayMutUsersKey(string $channel): string
{
    return "lightspeed:presence:channel:{$channel}:users";
}

/** A JSON array nested exactly `$levels` deep, for probing the decoder's ceiling. */
function relayMutNestedJson(int $levels): string
{
    return str_repeat('[', $levels).str_repeat(']', $levels);
}

afterEach(function () {
    foreach ((array) Redis::connection()->keys('*lightspeed:presence:channel:presence-relaymut-*') as $key) {
        // keys() returns prefixed names; del() prefixes again, so strip it.
        $prefix = (string) config('database.redis.options.prefix');
        Redis::connection()->del($prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
    }
});

test('a join with no user id writes nothing and announces nothing', function () {
    // The user id is the identity the reference count is kept under, so a
    // member without one is not a member. Letting it through does not add an
    // anonymous row: it adds a row under whatever the missing value degraded
    // to, which no later leave() can ever name and therefore ever remove.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $result = $store->join($channel, 'conn-1', ['user_info' => ['name' => 'Nobody']]);

    expect($result['broadcast_member_added'])->toBeFalse()
        ->and($result['snapshot']['count'])->toBe(0)
        ->and($store->snapshot($channel)['ids'])->toBe([])
        ->and(Redis::connection()->exists("lightspeed:presence:channel:{$channel}:connections"))->toBe(0);
});

test('a numeric user id is a string everywhere it is reported', function () {
    // Laravel's default primary key is an integer, so this is the ordinary
    // case rather than the exotic one. A snapshot id that is an int and a
    // member payload that is not agree with each other nowhere, and the client
    // indexes presence.hash by the id it was given.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $result = $store->join($channel, 'conn-1', ['user_id' => 42, 'user_info' => ['name' => 'Ada']]);

    expect($result['member']['user_id'])->toBe('42')
        ->and($result['snapshot']['ids'])->toBe(['42'])
        ->and($store->leave($channel, 'conn-1')['user_id'])->toBe('42');
});

test('the join reports the snapshot and the member as it was actually stored', function () {
    // The caller broadcasts these two straight to the channel: the snapshot is
    // what a subscribing client is handed, and the member is what everyone
    // already there is told to add. The stored payload is not the one that was
    // passed in, because the join script assigns the colour, so returning the
    // caller's own argument shows one colour to the joiner and another to
    // every client that sees the member_added.
    config()->set('lightspeed.presence.color_slots', 4);

    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $result = $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    expect($result['snapshot']['count'])->toBe(1)
        ->and($result['snapshot']['ids'])->toBe(['7'])
        ->and($result['member']['user_id'])->toBe('7')
        ->and($result['member']['user_info'])->toBe(['name' => 'Seven', 'colorIndex' => 0]);
});

test('only the first connection for a user announces the member, and only the last announces the leave', function () {
    // Two browser tabs are one member. Announcing member_added twice puts a
    // duplicate in every client's list, and announcing member_removed when the
    // first tab closes removes a member who is still there, with nothing left
    // to put them back until they reload.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $first = $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);
    $second = $store->join($channel, 'conn-2', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    expect($first['broadcast_member_added'])->toBeTrue()
        ->and($second['broadcast_member_added'])->toBeFalse();

    $firstLeave = $store->leave($channel, 'conn-1');
    $secondLeave = $store->leave($channel, 'conn-2');

    expect($firstLeave['broadcast_member_removed'])->toBeFalse()
        ->and($firstLeave['user_id'])->toBe('7')
        ->and($secondLeave['broadcast_member_removed'])->toBeTrue()
        ->and($secondLeave['user_id'])->toBe('7');
});

test('member info that cannot be encoded refuses the join instead of storing a blank member', function () {
    // json_encode answers unencodable input with `false`, and the script would
    // store that as an empty payload: a member in every snapshot with no
    // user_info at all, which is indistinguishable from a member whose app
    // simply sends none. The throw is what turns it into the caller's problem
    // while the row does not yet exist.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    expect(fn () => $store->join($channel, 'conn-1', [
        'user_id' => '7',
        'user_info' => ['name' => "\xB1\x31 invalid utf8"],
    ]))->toThrow(JsonException::class);

    expect($store->snapshot($channel)['count'])->toBe(0);
});

test('a blank user id in the users hash never reaches a snapshot or a member lookup', function () {
    // Redis hashes outlive the code that wrote them: a half-finished join from
    // an older build, or anything else in the deployment writing to these keys,
    // can leave a field with no name. A blank id in `ids` is a member every
    // client tries to render and none can index.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    // Written FIRST, so it is the field the snapshot walk meets first: a walk
    // that gave up at the blank field rather than stepping over it would empty
    // the room for every member stored after it.
    Redis::connection()->hset(relayMutUsersKey($channel), '', '{"name":"Nameless"}');
    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    expect($store->snapshot($channel)['ids'])->toBe(['7'])
        ->and($store->snapshot($channel)['count'])->toBe(1)
        ->and($store->member($channel, ''))->toBeNull();
});

test('member payloads are decoded as data, not as objects', function () {
    // The snapshot hash is handed to json_encode on its way to the client, so
    // an object survives that trip looking identical. What does not survive is
    // every caller in between: member() feeds the payload back into the join
    // result, and the sweeper and the bridge treat it as an array.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    expect($store->member($channel, '7'))->toBe(['name' => 'Seven'])
        ->and($store->snapshot($channel)['hash']->{'7'})->toBe(['name' => 'Seven']);
});

test('a member payload that is not a JSON object is not a member', function () {
    // A truncated or half-written value is an ordinary outcome of a client that
    // died mid-write. Returning the scalar it decoded to hands a string to
    // callers that index it as an array.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);
    Redis::connection()->hset(relayMutUsersKey($channel), '7', '"just-a-string"');

    expect($store->member($channel, '7'))->toBeNull();
});

test('a member payload nested deeper than the decoder allows is refused rather than half read', function () {
    // The ceiling is the decoder's own default, and it is a boundary rather
    // than a dial: one level under it decodes, one level over it is refused.
    // Both outcomes are safe here, which is the point. What must not happen is
    // the boundary moving, because a payload that decodes on the worker that
    // wrote it and not on its peers is a member who exists on half the fleet.
    $channel = relayMutMemberChannel();
    $store = relayMutMemberStore();

    $store->join($channel, 'conn-1', ['user_id' => '7', 'user_info' => ['name' => 'Seven']]);

    Redis::connection()->hset(relayMutUsersKey($channel), '7', relayMutNestedJson(511));

    expect($store->member($channel, '7'))->toBeArray()
        ->and($store->snapshot($channel)['hash']->{'7'})->toBeArray();

    Redis::connection()->hset(relayMutUsersKey($channel), '7', relayMutNestedJson(512));

    expect($store->member($channel, '7'))->toBeNull()
        // The snapshot degrades to an empty object rather than dropping the
        // member: they are still in the room, their payload is unreadable.
        ->and(json_encode($store->snapshot($channel)['hash']->{'7'}))->toBe('{}');
});
