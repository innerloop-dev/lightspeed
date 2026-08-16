<?php

use Lightspeed\Protocol\PublishRequestVerifier;

/**
 * The publish endpoint can push any event onto any channel, so a hole in this
 * check is a hole in every channel at once.
 *
 * The signature covers the verb, the path and the sorted query string, and it
 * reaches the body only through body_md5. Which is why the body has to be
 * hashed exactly as it arrived on the wire, and why the raw-body/form-post
 * reconstruction is tested here next to the signature it feeds. A publisher
 * whose body is re-encoded on the way in fails a signature it computed
 * correctly, which is how this was first found.
 */

function signedPublishQuery(string $body, string $path = '/apps/test-app/events', string $secret = 'test-secret', ?int $timestamp = null): array
{
    $query = [
        'auth_key' => 'test-key',
        // Now, because the signature is only half of what makes a request
        // valid: `auth_timestamp` is signed and is also checked for freshness.
        'auth_timestamp' => (string) ($timestamp ?? time()),
        'auth_version' => '1.0',
        'body_md5' => md5($body),
    ];

    ksort($query);

    $pairs = [];
    foreach ($query as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n".implode('&', $pairs), $secret);

    return $query;
}

function publishRequestVerifier(): PublishRequestVerifier
{
    return new PublishRequestVerifier('test-app', 'test-key', 'test-secret');
}

test('it accepts a request signed with the app secret', function () {
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', signedPublishQuery($body), $body))
        ->toBeTrue();
});

test('it rejects a body that changed after the signature was made', function () {
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';
    $query = signedPublishQuery($body);

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body.' '))
        ->toBeFalse();
});

test('it rejects a request signed with the wrong secret', function () {
    $body = '{"name":"resource.updated"}';
    $query = signedPublishQuery($body, secret: 'not-the-secret');

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeFalse();
});

test('it rejects a signature replayed onto a different path', function () {
    $body = '{"name":"resource.updated"}';
    $query = signedPublishQuery($body, path: '/apps/other-app/events');

    expect(publishRequestVerifier()->verify('POST', '/apps/other-app/events', $query, $body))->toBeFalse()
        ->and(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeFalse();
});

test('it rejects a request from an unknown app key', function () {
    $body = '{"name":"resource.updated"}';
    $query = signedPublishQuery($body);
    $query['auth_key'] = 'someone-elses-key';

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeFalse();
});

test('it rejects a request with a missing or wrong body_md5', function () {
    $body = '{"name":"resource.updated"}';

    $missing = signedPublishQuery($body);
    unset($missing['body_md5']);

    $wrong = signedPublishQuery($body);
    $wrong['body_md5'] = md5('something else');

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $missing, $body))->toBeFalse()
        ->and(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $wrong, $body))->toBeFalse();
});

test('it rejects a request with no signature at all', function () {
    $body = '{"name":"resource.updated"}';
    $query = signedPublishQuery($body);
    unset($query['auth_signature']);

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeFalse();
});

test('it hashes a raw JSON body exactly as it arrived', function () {
    $rawBody = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';

    expect(PublishRequestVerifier::decodeRequestBody($rawBody, []))->toBe([
        'signatureBody' => $rawBody,
        'payload' => [
            'name' => 'resource.updated',
            'data' => '{}',
            'channels' => ['private-resource.1'],
        ],
    ]);
});

test('it rebuilds the wire body when swoole already decoded a form post', function () {
    $post = [
        'name' => 'resource.updated',
        'data' => '{}',
        'channel' => 'private-resource.1',
    ];

    expect(PublishRequestVerifier::decodeRequestBody('', $post))->toBe([
        'signatureBody' => http_build_query($post, '', '&', PHP_QUERY_RFC3986),
        'payload' => $post,
    ]);
});

test('it still signs over an unparseable body rather than discarding it', function () {
    expect(PublishRequestVerifier::decodeRequestBody('not json at all', []))->toBe([
        'signatureBody' => 'not json at all',
        'payload' => null,
    ]);

    expect(PublishRequestVerifier::decodeRequestBody('', []))->toBe([
        'signatureBody' => '',
        'payload' => null,
    ]);
});

/**
 * F7. `auth_timestamp` was signed and never compared to anything.
 *
 * A captured `POST /apps/{id}/events` therefore replayed forever: the signature
 * covers the timestamp, so it stays valid no matter how old the request is, and
 * this endpoint can push any event onto any channel. Anyone who ever saw one
 * publish request, a proxy log, a mirrored packet, a bug report with a URL in
 * it, held a permanent credential for that exact event on that exact channel.
 *
 * 600 seconds is Pusher's own window, which is what every Pusher server SDK
 * already signs against, so nothing that worked before stops working.
 *
 * The window is symmetric because clock skew is: a publisher whose clock runs
 * ten minutes fast is as broken as one running ten minutes slow, and silently
 * accepting the future half would leave a replay window that opens later.
 */
test('it rejects a captured request replayed after the signature window', function () {
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';
    $query = signedPublishQuery($body, timestamp: time() - 601);

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeFalse();
});

test('it accepts a request inside the signature window', function () {
    // The positive half. A check that refused everything old would also refuse
    // every publisher whose clock is a few seconds out, which is all of them.
    //
    // A minute inside the 600s window, not one second inside it. Signing at
    // `time() - 599` left a single second between building the query and
    // verifying it, so any pause in between (a slow autoload, a loaded CI box,
    // a second ticking over) failed a test about clock tolerance on the clock.
    //
    // The boundary itself is asserted separately, by the test below, which names
    // the instant instead of racing the clock. What is covered here is that
    // something comfortably inside passes and something outside does not, which
    // is the behaviour the window is for.
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';
    $query = signedPublishQuery($body, timestamp: time() - 540);

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeTrue();
});

/**
 * THE EDGE ITSELF, at exactly 600 seconds, in both directions.
 *
 * `abs(...) <= 600` and `abs(...) < 600` differ on precisely one input, so every
 * test above passes under either. It is one second of a ten-minute window and no
 * attack turns on it; it is pinned because the edge is the only part of a
 * boundary a test can be wrong about, and because pinning it is free.
 *
 * An earlier comment here said this could not be done, because "the verifier
 * reads the clock directly, so there is no instant a test can name and hold". It
 * does not: `$nowSeconds` has been a parameter of verify() all along, described
 * in its own docblock as "the instant to judge `auth_timestamp` against". So the
 * test names T, and nothing here depends on how long the test takes to run.
 */
test('the signature window includes its own edge, and stops one second past it', function () {
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';
    $now = 1_700_000_000;

    $at = fn (int $timestamp): bool => publishRequestVerifier()->verify(
        'POST',
        '/apps/test-app/events',
        signedPublishQuery($body, timestamp: $timestamp),
        $body,
        $now,
    );

    expect($at($now - 600))->toBeTrue('exactly 600s old is inside the window')
        ->and($at($now - 601))->toBeFalse('601s old is outside it')
        ->and($at($now + 600))->toBeTrue('the window is symmetric, so 600s ahead is inside it too')
        ->and($at($now + 601))->toBeFalse('601s ahead is outside it');
});

test('it rejects a request timestamped far in the future', function () {
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';
    $query = signedPublishQuery($body, timestamp: time() + 601);

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $query, $body))->toBeFalse();
});

test('it rejects a request with a missing or unparseable timestamp', function () {
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';

    // Signed CORRECTLY over a query that has no timestamp, so what refuses it
    // is the freshness check and not the signature. Dropping the field from an
    // already-signed query would break the signature and prove nothing.
    $missing = ['auth_key' => 'test-key', 'auth_version' => '1.0', 'body_md5' => md5($body)];
    ksort($missing);
    $missing['auth_signature'] = hash_hmac(
        'sha256',
        "POST\n/apps/test-app/events\nauth_key=test-key&auth_version=1.0&body_md5=".md5($body),
        'test-secret',
    );

    $unparseable = signedPublishQuery($body);
    $unparseable['auth_timestamp'] = 'yesterday';

    expect(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $missing, $body))->toBeFalse()
        ->and(publishRequestVerifier()->verify('POST', '/apps/test-app/events', $unparseable, $body))->toBeFalse();
});
