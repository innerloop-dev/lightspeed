<?php

use Lightspeed\Protocol\PublishRequestVerifier;

/**
 * The parts of the publish signature that are about BYTES rather than secrets.
 *
 * This endpoint can push any event onto any channel, so the comparison at the
 * end of it is the whole gate. But a comparison only means something if both
 * sides built the same string, and three of the things that decide that are not
 * secret at all: the order the parameters are joined in, the body as it arrived
 * on the wire, and how a timestamp is turned into a number.
 *
 * Each of them fails in the same direction if it drifts: a correctly signed
 * request from a real Pusher SDK stops verifying, and the failure looks exactly
 * like a wrong secret. Which is the hardest thing to debug from the far side of
 * an HTTP 403.
 */
function protoMutVerifier(): PublishRequestVerifier
{
    return new PublishRequestVerifier('proto-mut-app', 'proto-mut-key', 'proto-mut-secret');
}

/** The signature a Pusher SDK computes: sorted, joined raw, and unencoded. */
function protoMutPublishSignature(string $path, array $query, string $body): string
{
    $query['body_md5'] = md5($body);
    ksort($query);

    $pairs = [];

    foreach ($query as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    return hash_hmac('sha256', "POST\n{$path}\n".implode('&', $pairs), 'proto-mut-secret');
}

test('the parameters are sorted before they are signed, whatever order they arrived in', function () {
    $path = '/apps/proto-mut-app/events';
    $body = '{"name":"OrderShipped","channels":["orders"]}';
    $now = 1_700_000_000;

    $signed = [
        'auth_key' => 'proto-mut-key',
        'auth_timestamp' => $now,
        'auth_version' => '1.0',
    ];

    // The same parameters as a query string usually arrives in: whatever order
    // the SDK wrote them, which is not sorted. Every Pusher SDK signs the sorted
    // form, so a server that signed the arrival order would refuse every real
    // publish while still accepting the handful of requests a test happened to
    // build in alphabetical order.
    $query = [
        'body_md5' => md5($body),
        'auth_version' => '1.0',
        'auth_timestamp' => $now,
        'auth_key' => 'proto-mut-key',
        'auth_signature' => protoMutPublishSignature($path, $signed, $body),
    ];

    expect(protoMutVerifier()->verify('POST', $path, $query, $body, $now))->toBeTrue();
});

test('a publish signed with the right secret but the wrong app key is refused', function () {
    $path = '/apps/proto-mut-app/events';
    $body = '{"name":"OrderShipped"}';
    $now = 1_700_000_000;

    // The signature covers `auth_key`, so a caller that holds the secret can
    // sign whatever key it likes and the HMAC will agree with itself. Only the
    // comparison against THIS app's key says which app the caller is, and a
    // publish is fanned out to whoever is connected to this one.
    $signed = [
        'auth_key' => 'some-other-app-key',
        'auth_timestamp' => $now,
        'auth_version' => '1.0',
    ];

    $query = $signed + [
        'body_md5' => md5($body),
        'auth_signature' => protoMutPublishSignature($path, $signed, $body),
    ];

    expect(protoMutVerifier()->verify('POST', $path, $query, $body, $now))->toBeFalse();
});

test('a publish with no timestamp is refused, and not read as the number zero', function () {
    $path = '/apps/proto-mut-app/events';
    $body = '{"name":"OrderShipped"}';

    $signed = [
        'auth_key' => 'proto-mut-key',
        'auth_version' => '1.0',
    ];

    $query = $signed + [
        'body_md5' => md5($body),
        'auth_signature' => protoMutPublishSignature($path, $signed, $body),
    ];

    // Correctly signed, and missing the timestamp entirely. The clock is close
    // to zero on purpose: "no timestamp" must be refused BECAUSE it is missing,
    // not because zero happens to be far from now. A server whose clock ever
    // reads low would otherwise accept an unbounded credential.
    expect(protoMutVerifier()->verify('POST', $path, $query, $body, 300))->toBeFalse();
});

test('a fractional timestamp is judged on whole seconds', function () {
    $path = '/apps/proto-mut-app/events';
    $body = '{"name":"OrderShipped"}';

    // What a caller sending a millisecond clock divided by a thousand produces.
    // It is numeric, so it is judged rather than refused, and it is judged as
    // the whole second it names: the window is measured in seconds, and a
    // request must not fall outside it over a fraction of one.
    $signed = [
        'auth_key' => 'proto-mut-key',
        'auth_timestamp' => '1000000600.9',
        'auth_version' => '1.0',
    ];

    $query = $signed + [
        'body_md5' => md5($body),
        'auth_signature' => protoMutPublishSignature($path, $signed, $body),
    ];

    // Exactly ten minutes ahead once truncated, which is the last instant the
    // window still accepts.
    expect(protoMutVerifier()->verify('POST', $path, $query, $body, 1_000_000_000))->toBeTrue();
});

test('a body is decoded up to the nesting limit and refused past it', function () {
    $atTheLimit = '{"deep":'.str_repeat('[', 510).'1'.str_repeat(']', 510).'}';
    $pastTheLimit = '{"deep":'.str_repeat('[', 511).'1'.str_repeat(']', 511).'}';

    // The publish endpoint is reachable by anything holding the app secret, and
    // the body is decoded before anything else looks at it. Past the limit the
    // body is not decoded at all, and the raw bytes are still what the
    // signature is checked against.
    expect(PublishRequestVerifier::decodeRequestBody($atTheLimit, [])['payload'])->toBeArray()
        ->and(PublishRequestVerifier::decodeRequestBody($pastTheLimit, [])['payload'])->toBeNull()
        ->and(PublishRequestVerifier::decodeRequestBody($pastTheLimit, [])['signatureBody'])->toBe($pastTheLimit);
});

test('a form encoded body is rebuilt byte for byte, with nothing inserted into its keys', function () {
    // Swoole decodes form fields before this code sees them, so the bytes the
    // signature covers have to be rebuilt from the decoded fields. body_md5 is
    // a hash of those exact bytes: anything inserted, including a prefix in
    // front of a numeric key, changes the hash and refuses a request that was
    // signed correctly.
    $rebuilt = PublishRequestVerifier::decodeRequestBody('', ['name' => 'OrderShipped', '0' => 'orders']);

    expect($rebuilt['signatureBody'])->toBe('name=OrderShipped&0=orders')
        ->and($rebuilt['payload'])->toBe(['name' => 'OrderShipped', '0' => 'orders']);
});
