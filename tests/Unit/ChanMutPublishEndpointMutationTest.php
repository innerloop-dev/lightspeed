<?php

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Http\PublishEndpoint;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Logging\RuntimeLogger;

/**
 * What a publish is allowed to name, and what the endpoint says it did.
 *
 * The signature check has its own tests. These are about everything after it:
 * which of the channel fields wins, which channel names are skipped rather than
 * delivered to, and the report the endpoint hands back. That report is not
 * cosmetic. The body is what a Pusher SDK reads to learn whether its event
 * landed, and the log array is the only record that a publish happened at all,
 * so an endpoint that delivers correctly while reporting a channel it did not
 * touch, or reporting nothing, is a silent failure of exactly the kind the 200
 * is supposed to rule out.
 *
 * The credentials are the other half. They are read out of config on every
 * request, and config is an environment file: absent keys and null values are
 * the normal state of a half-configured deployment, and neither may turn into
 * an accepted publish or a crash.
 */

/** Records fan-out instead of performing it: no relay, no Redis, no sockets. */
final class ChanMutPublishBridge extends BroadcastBridge
{
    /** @var list<array{channels: array, event: string, payload: mixed, exceptSocketId: ?string}> */
    public array $fanOuts = [];

    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        $this->fanOuts[] = compact('channels', 'event', 'payload', 'exceptSocketId');

        return 3;
    }
}

function chanMutPublishBridge(): ChanMutPublishBridge
{
    return new ChanMutPublishBridge(app(RedisRelay::class));
}

function chanMutPublishEndpoint(ChanMutPublishBridge $bridge): PublishEndpoint
{
    return new PublishEndpoint($bridge, app(RuntimeLogger::class));
}

/** A query string signed the way a Pusher SDK signs one. */
function chanMutPublishQuery(
    string $body,
    string $path = '/apps/test-app/events',
    string $key = 'test-key',
    string $secret = 'test-secret',
): array {
    $query = [
        'auth_key' => $key,
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_md5' => md5($body),
    ];

    ksort($query);

    $pairs = [];
    foreach ($query as $name => $value) {
        $pairs[] = "{$name}={$value}";
    }

    $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n".implode('&', $pairs), $secret);

    return $query;
}

/** Sign and send one publish body. */
function chanMutPublish(ChanMutPublishBridge $bridge, array $payload, string $path = '/apps/test-app/events'): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return chanMutPublishEndpoint($bridge)->handle('POST', $path, $body, [], chanMutPublishQuery($body, $path));
}

test('an event named with the empty string is refused rather than published anonymously', function () {
    // An event with no name reaches every subscriber as an event they cannot
    // match a binding against, so it is delivered and invisible. The check that
    // stops it is an equality against the empty string.
    $bridge = chanMutPublishBridge();

    $result = chanMutPublish($bridge, [
        'name' => '',
        'data' => '{}',
        'channels' => ['private-resource.1'],
    ]);

    expect($result['status'])->toBe(422)
        ->and($result['body'])->toBe(['error' => 'Missing event name.'])
        ->and($bridge->fanOuts)->toBe([]);
});

test('an empty single channel does not override the channel list it arrived with', function () {
    // `channel` is the singular form and it wins over `channels` when it names
    // one. An empty string names nothing, and letting it win replaces a real
    // list with a list of nothing: a 200, and an event that reached no one.
    $bridge = chanMutPublishBridge();

    $result = chanMutPublish($bridge, [
        'name' => 'resource.updated',
        'data' => '{}',
        'channel' => '',
        'channels' => ['private-resource.1'],
    ]);

    expect($result['status'])->toBe(200)
        ->and($result['body']['channels'])->toBe(['private-resource.1' => 3])
        ->and($bridge->fanOuts)->toHaveCount(1)
        ->and($bridge->fanOuts[0]['channels'])->toBe(['private-resource.1']);
});

test('a channel entry that is not a usable name is skipped, and the rest are still delivered', function () {
    // The list comes from a caller, so it can hold anything JSON can express.
    // An empty name and a number are both channels nothing can subscribe to,
    // and both would be reported in the response as delivered channels if they
    // were not skipped here.
    $bridge = chanMutPublishBridge();

    $result = chanMutPublish($bridge, [
        'name' => 'resource.updated',
        'data' => '{}',
        'channels' => ['', 123, 'private-resource.1'],
    ]);

    expect($result['status'])->toBe(200)
        ->and($result['body']['channels'])->toBe(['private-resource.1' => 3])
        ->and($bridge->fanOuts)->toHaveCount(1)
        ->and($bridge->fanOuts[0]['channels'])->toBe(['private-resource.1']);
});

test('an encrypted channel is refused with the name it refused and why', function () {
    // The refusal exists because accepting it would be a cheerful 200 for a
    // message that reached nobody. Which makes the two things it has to carry
    // the name of the channel (so the publisher can find it) and the reason (so
    // the operator can tell this apart from a signature failure in the log).
    $bridge = chanMutPublishBridge();

    $result = chanMutPublish($bridge, [
        'name' => 'resource.updated',
        'data' => '{}',
        'channels' => ['private-encrypted-resource.1'],
    ]);

    expect($result['status'])->toBe(422)
        ->and($result['body']['error'])->toBe(
            'Lightspeed does not implement end-to-end encrypted channels, '
            ."so nothing can subscribe to 'private-encrypted-resource.1' and this message would reach nobody. "
            .'Use a private- channel if payloads visible to the server are acceptable.'
        )
        ->and($result['log'])->toBe([
            'reason' => 'encrypted-channel',
            'channel' => 'private-encrypted-resource.1',
        ])
        ->and($bridge->fanOuts)->toBe([]);
});

test('an accepted publish reports the event, the channel count and the publishing socket', function () {
    // The log line is the only record that this publish happened: nothing else
    // writes one, because the request never reaches the application. The socket
    // id is on it because "who published this" is the first question asked of
    // an event that should not have gone out.
    $bridge = chanMutPublishBridge();

    $result = chanMutPublish($bridge, [
        'name' => 'resource.updated',
        'data' => '{"id":1}',
        'channels' => ['private-resource.1', 'presence-resource.1'],
        'socket_id' => '123.456',
    ]);

    expect($result['status'])->toBe(200)
        ->and($result['body'])->toBe([
            'ok' => true,
            'event' => 'resource.updated',
            'channels' => ['private-resource.1' => 3, 'presence-resource.1' => 3],
        ])
        ->and($result['log'])->toBe([
            'event' => 'resource.updated',
            'channels' => 2,
            'socket' => '123.456',
            'payload' => null,
        ])
        ->and($bridge->fanOuts[0]['exceptSocketId'])->toBe('123.456');
});

test('credentials that are configured as null refuse the publish instead of raising', function () {
    // `LIGHTSPEED_APP_KEY` unset makes every one of these null, which is the
    // normal state of a deployment someone has only half finished. Handed to
    // the verifier as null rather than as the empty string, a publish request
    // dies with a TypeError: a 500 on an unauthenticated endpoint, in place of
    // the 401 that says what is actually wrong.
    config()->set('lightspeed.reverb_compat.app_id', null);
    config()->set('lightspeed.reverb_compat.app_key', null);
    config()->set('lightspeed.reverb_compat.app_secret', null);

    $bridge = chanMutPublishBridge();
    $result = chanMutPublish($bridge, [
        'name' => 'resource.updated',
        'data' => '{}',
        'channels' => ['private-resource.1'],
    ]);

    expect($result['status'])->toBe(401)
        ->and($result['body'])->toBe(['error' => 'Invalid publish signature.'])
        ->and($bridge->fanOuts)->toBe([]);
});

test('a credential missing from config is empty, not a value anything could sign against', function () {
    // The fallback for an absent config key is what an unconfigured server
    // authenticates against. Any other fallback is a credential that exists, is
    // the same on every installation of this package, and is readable in the
    // source, so the publish endpoint of an app that never set these variables
    // would accept whoever signed with it. The three are read off the verifier
    // rather than probed with a request, because a probe can only ask about
    // credentials it can already guess, which is the property in question.
    config()->set('lightspeed.reverb_compat', []);

    $verifier = (new ReflectionMethod(PublishEndpoint::class, 'verifier'))
        ->invoke(chanMutPublishEndpoint(chanMutPublishBridge()));

    $credentials = [];
    foreach (['appId', 'appKey', 'appSecret'] as $name) {
        $property = new ReflectionProperty($verifier::class, $name);
        $property->setAccessible(true);
        $credentials[$name] = $property->getValue($verifier);
    }

    expect($credentials)->toBe(['appId' => '', 'appKey' => '', 'appSecret' => '']);

    // And an empty app id can never match the one in a publish path, so an
    // unconfigured endpoint refuses every request rather than serving one.
    $bridge = chanMutPublishBridge();
    $body = json_encode([
        'name' => 'resource.updated',
        'data' => '{}',
        'channels' => ['private-resource.1'],
    ], JSON_THROW_ON_ERROR);

    $result = chanMutPublishEndpoint($bridge)->handle(
        'POST',
        '/apps/test-app/events',
        $body,
        [],
        chanMutPublishQuery($body, key: '', secret: ''),
    );

    expect($result['status'])->toBe(401)
        ->and($bridge->fanOuts)->toBe([]);
});
