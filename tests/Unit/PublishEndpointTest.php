<?php

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Http\PublishEndpoint;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Logging\RuntimeLogger;

/**
 * The publish endpoint is the one door into every channel that needs no
 * websocket, so what matters is not only that a bad request is refused but that
 * it is refused *before anything is delivered*. Protocol\PublishRequestVerifier
 * proves the signature maths; these tests prove this endpoint actually consults
 * it, in the right order, and that a request rejected at any stage fans nothing
 * out.
 *
 * They also pin the status codes, because they are a public contract: a Pusher
 * SDK retries a 5xx and gives up on a 4xx, so a validation failure reported as
 * the wrong class of error turns a permanent mistake into a retry storm.
 *
 * The bridge is stubbed rather than mocked so a delivery attempt is recorded as
 * data, an assertion of "nothing was delivered" is then a real observation,
 * not the absence of an expectation.
 */

/** Records fan-out instead of performing it: no relay, no Redis, no sockets. */
final class RecordingPublishBridge extends BroadcastBridge
{
    /** @var array<int, array{channels: array, event: string, payload: mixed, exceptSocketId: ?string}> */
    public array $fanOuts = [];

    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        $this->fanOuts[] = [
            'channels' => $channels,
            'event' => $event,
            'payload' => $payload,
            'exceptSocketId' => $exceptSocketId,
        ];

        return 3;
    }
}

function publishEndpointBridge(): RecordingPublishBridge
{
    return new RecordingPublishBridge(app(RedisRelay::class));
}

function publishEndpointFor(RecordingPublishBridge $bridge): PublishEndpoint
{
    return new PublishEndpoint($bridge, app(RuntimeLogger::class));
}

/** A query string signed the way a Pusher SDK signs one, for the test app. */
function signedEndpointQuery(string $body, string $path = '/apps/test-app/events', string $secret = 'test-secret'): array
{
    $query = [
        'auth_key' => 'test-key',
        // Now, not a fixed instant: `auth_timestamp` is checked for freshness
        // as well as signed, so a hardcoded one ages out of the window.
        'auth_timestamp' => (string) time(),
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

test('it refuses anything but POST and delivers nothing', function () {
    $bridge = publishEndpointBridge();
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';

    $result = publishEndpointFor($bridge)->handle(
        'GET',
        '/apps/test-app/events',
        $body,
        [],
        signedEndpointQuery($body),
    );

    expect($result['status'])->toBe(405)
        ->and($result['body'])->toBe(['error' => 'Method not allowed.'])
        ->and($bridge->fanOuts)->toBe([]);
});

test('it refuses an unsigned request before it looks at the body', function () {
    $bridge = publishEndpointBridge();

    $result = publishEndpointFor($bridge)->handle(
        'POST',
        '/apps/test-app/events',
        '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}',
        [],
        [],
    );

    expect($result['status'])->toBe(401)
        ->and($result['body'])->toBe(['error' => 'Invalid publish signature.'])
        ->and($bridge->fanOuts)->toBe([]);
});

test('it refuses a body that changed after it was signed', function () {
    $bridge = publishEndpointBridge();
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';
    $tampered = '{"name":"resource.deleted","data":"{}","channels":["private-resource.1"]}';

    $result = publishEndpointFor($bridge)->handle(
        'POST',
        '/apps/test-app/events',
        $tampered,
        [],
        signedEndpointQuery($body),
    );

    expect($result['status'])->toBe(401)
        ->and($bridge->fanOuts)->toBe([]);
});

test('it reports an unparseable body as a bad request, not a server error', function () {
    $bridge = publishEndpointBridge();
    $body = 'not json at all';

    $result = publishEndpointFor($bridge)->handle(
        'POST',
        '/apps/test-app/events',
        $body,
        [],
        signedEndpointQuery($body),
    );

    expect($result['status'])->toBe(400)
        ->and($result['body'])->toBe(['error' => 'Invalid JSON body.'])
        ->and($bridge->fanOuts)->toBe([]);
});

test('it requires an event name, a channel list, and data already encoded as a string', function () {
    $bridge = publishEndpointBridge();

    $missingEvent = '{"data":"{}","channels":["private-resource.1"]}';
    expect(publishEndpointFor($bridge)->handle('POST', '/apps/test-app/events', $missingEvent, [], signedEndpointQuery($missingEvent)))
        ->toMatchArray([
            'status' => 422,
            'body' => ['error' => 'Missing event name.'],
            'log' => [],
        ]);

    $missingChannels = '{"name":"resource.updated","data":"{}"}';
    expect(publishEndpointFor($bridge)->handle('POST', '/apps/test-app/events', $missingChannels, [], signedEndpointQuery($missingChannels)))
        ->toMatchArray([
            'status' => 422,
            'body' => ['error' => 'Missing channels.'],
            'log' => ['event' => 'resource.updated'],
        ]);

    // A nested object here is the mistake a hand-rolled publisher makes, and it
    // has to fail loudly: pusher-js reads `data` as a string.
    $objectData = '{"name":"resource.updated","data":{"id":1},"channels":["private-resource.1"]}';
    expect(publishEndpointFor($bridge)->handle('POST', '/apps/test-app/events', $objectData, [], signedEndpointQuery($objectData)))
        ->toMatchArray([
            'status' => 422,
            'body' => ['error' => 'Expected event data to be a JSON string.'],
            'log' => ['event' => 'resource.updated', 'channels' => 1],
        ]);

    expect($bridge->fanOuts)->toBe([]);
});

test('a signed publish fans out once per channel and reports what each one delivered', function () {
    $bridge = publishEndpointBridge();
    $body = '{"name":"resource.updated","data":"{\"id\":1}","channels":["private-a","private-b"]}';

    $result = publishEndpointFor($bridge)->handle('POST', '/apps/test-app/events', $body, [], signedEndpointQuery($body));

    expect($result['status'])->toBe(200)
        ->and($result['body'])->toBe([
            'ok' => true,
            'event' => 'resource.updated',
            'channels' => ['private-a' => 3, 'private-b' => 3],
        ])
        ->and($bridge->fanOuts)->toHaveCount(2)
        ->and($bridge->fanOuts[0]['channels'])->toBe(['private-a'])
        ->and($bridge->fanOuts[1]['channels'])->toBe(['private-b'])
        ->and($bridge->fanOuts[0]['payload'])->toBe('{"id":1}');
});

test('it accepts the singular channel key and excludes the publishing socket', function () {
    $bridge = publishEndpointBridge();
    $body = '{"name":"resource.updated","data":"{}","channel":"private-a","socket_id":"123.456"}';

    $result = publishEndpointFor($bridge)->handle('POST', '/apps/test-app/events', $body, [], signedEndpointQuery($body));

    expect($result['status'])->toBe(200)
        ->and($result['body']['channels'])->toBe(['private-a' => 3])
        ->and($bridge->fanOuts)->toHaveCount(1)
        ->and($bridge->fanOuts[0]['exceptSocketId'])->toBe('123.456');
});

/**
 * The subscribe side refuses encrypted channels rather than serve plaintext
 * under a name that promises encryption. If the publish side did not, a caller
 * would get a cheerful 200 for a message that reached nobody and could reach
 * nobody: the same silent failure, one layer up.
 */
test('it refuses to publish to a channel nothing is allowed to subscribe to', function () {
    $bridge = publishEndpointBridge();
    $body = '{"name":"resource.updated","data":"{}","channels":["private-encrypted-resource.1"]}';

    $result = publishEndpointFor($bridge)->handle(
        'POST',
        '/apps/test-app/events',
        $body,
        [],
        signedEndpointQuery($body),
    );

    expect($result['status'])->toBe(422)
        ->and($result['body']['error'])->toContain('end-to-end encrypted')
        ->and($result['body']['error'])->toContain('private-')
        ->and($bridge->fanOuts)->toBe([]);
});

test('an ordinary private channel still publishes', function () {
    $bridge = publishEndpointBridge();
    $body = '{"name":"resource.updated","data":"{}","channels":["private-resource.1"]}';

    $result = publishEndpointFor($bridge)->handle(
        'POST',
        '/apps/test-app/events',
        $body,
        [],
        signedEndpointQuery($body),
    );

    expect($result['status'])->toBe(200)
        ->and($bridge->fanOuts)->not->toBe([]);
});
