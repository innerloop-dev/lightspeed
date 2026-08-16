<?php

use Lightspeed\Auth\Grant;
use Lightspeed\Protocol\SubscriptionAuthorizer;

/**
 * Three edges of the subscribe check that nothing else reaches.
 *
 * The first is channel_data that is not a string at all. It arrives inside a
 * client's subscribe frame, so its type is the client's choice, and the guard
 * that refuses it stands directly in front of a json_decode() that would take
 * the whole frame handler down with a TypeError rather than refuse one
 * subscription.
 *
 * The second is how deep that channel_data may nest. It is attacker-chosen work
 * on the hot path, so the limit is a real limit: proven from both sides here,
 * because a bound only tested from the far side is equally satisfied by one so
 * tight it refuses ordinary members.
 *
 * The third is the clock a grant's expiry is judged against when the caller
 * does not supply one, which is the case on the live server: the per-message
 * check and the subscribe path both let it default. A grant is a credential
 * with a lifetime, so a clock that reads low is a credential that never
 * expires.
 */
function protoMutAuthorizer(): SubscriptionAuthorizer
{
    return new SubscriptionAuthorizer('proto-mut-key', 'proto-mut-secret');
}

/** An auth string carrying a grant, signed the way the broadcaster signs one. */
function protoMutGrantedAuth(string $socketId, string $channel, Grant $grant, ?string $channelData = null): string
{
    $encoded = $grant->encode();

    return 'proto-mut-key:'.hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString($socketId, $channel, $channelData, $encoded),
        'proto-mut-secret',
    ).':'.$encoded;
}

/** An untagged auth string, which is Pusher's own form. */
function protoMutPlainAuth(string $socketId, string $channel, ?string $channelData = null): string
{
    $payload = $channelData === null ? "{$socketId}:{$channel}" : "{$socketId}:{$channel}:{$channelData}";

    return 'proto-mut-key:'.hash_hmac('sha256', $payload, 'proto-mut-secret');
}

/** Run a probe with deprecations promoted, so a lossy conversion is visible. */
function protoMutAuthStrictly(callable $probe): mixed
{
    set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    }, E_DEPRECATED);

    try {
        return $probe();
    } finally {
        restore_error_handler();
    }
}

test('presence channel data that is not a string is refused, not decoded', function () {
    // A client sends `channel_data` itself, so it can send an array. json_decode
    // does not refuse one, it raises a TypeError, and a TypeError here is not a
    // refused subscription: it is the frame handler for that worker dying on a
    // frame anyone can send.
    $decision = protoMutAuthorizer()->authorize(
        '123.456789',
        'presence-orders',
        protoMutPlainAuth('123.456789', 'presence-orders'),
        ['user_id' => 'ada'],
    );

    expect($decision->denied())->toBeTrue();
});

test('presence channel data is decoded up to the nesting limit and refused past it', function () {
    $socketId = '123.456789';
    $channel = 'presence-orders';

    $atTheLimit = '{"user_id":"ada","deep":'.str_repeat('[', 510).'1'.str_repeat(']', 510).'}';
    $pastTheLimit = '{"user_id":"ada","deep":'.str_repeat('[', 511).'1'.str_repeat(']', 511).'}';

    // Both are correctly signed, so the ONLY thing that can separate them is
    // the decode. Without the signature the two would be refused alike and the
    // limit would be proving nothing.
    $accepted = protoMutAuthorizer()->authorize(
        $socketId,
        $channel,
        protoMutPlainAuth($socketId, $channel, $atTheLimit),
        $atTheLimit,
    );

    $refused = protoMutAuthorizer()->authorize(
        $socketId,
        $channel,
        protoMutPlainAuth($socketId, $channel, $pastTheLimit),
        $pastTheLimit,
    );

    expect($accepted->granted)->toBeTrue()
        ->and($accepted->presenceMember['user_id'])->toBe('ada')
        ->and($refused->denied())->toBeTrue();
});

test('a grant that has run out is refused against this process own clock, with no clock passed in', function () {
    $socketId = '123.456789';
    $channel = 'private-orders';
    $now = (int) (microtime(true) * 1_000_000);

    // Ran out a minute ago. Every caller on the live server lets the clock
    // default, so a default clock that reads low, by any margin at all, is a
    // grant that outlives its own expiry everywhere it matters.
    $expired = new Grant(['proto-mut'], [], $now - 120_000_000, $now - 60_000_000);
    $live = new Grant(['proto-mut'], [], $now, $now + 300_000_000);

    $refused = protoMutAuthorizer()->authorize(
        $socketId,
        $channel,
        protoMutGrantedAuth($socketId, $channel, $expired),
        null,
    );

    // The positive control, and the reason the clock cannot simply read high
    // either. Run with deprecations promoted, because the conversion that keeps
    // it an integer is the kind PHP only warns about.
    $granted = protoMutAuthStrictly(static fn () => protoMutAuthorizer()->authorize(
        $socketId,
        $channel,
        protoMutGrantedAuth($socketId, $channel, $live),
        null,
    ));

    expect($refused->denied())->toBeTrue()
        ->and($granted->granted)->toBeTrue()
        ->and($granted->grant)->not->toBeNull();
});
