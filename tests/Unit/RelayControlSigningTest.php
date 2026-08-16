<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Relay\RedisStreams;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * F6. A relay control entry tells every worker to unsubscribe the connections
 * carrying a tag. Nothing about the entry was authenticated: anything holding
 * the Redis credentials could XADD one and force-drop connections at will.
 *
 * It can only ever DENY, a control entry cannot grant anything, and a dropped
 * connection re-subscribes through the application's own authorization. so
 * this is a targeted denial of service rather than a bypass. It is still a
 * command from an unauthenticated source being obeyed, and the app secret is
 * already shared by everything entitled to issue one.
 */

/** A stream nobody else is writing to, so the drain reads only what a test put there. */
function relayTestStream(): string
{
    return 'lightspeed:test:control:'.bin2hex(random_bytes(6));
}

/** Records what the relay pushed to a socket, in place of a real websocket. */
class RelaySigningSwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push(int $fd, \Swoole\WebSocket\Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }
}

function relayFor(string $stream, ?RelaySigningSwooleServer $swoole = null): RedisRelay
{
    config()->set('lightspeed.relay.enabled', true);
    config()->set('lightspeed.relay.broadcast_stream', $stream);

    $relay = app(RedisRelay::class);

    $server = new ReflectionProperty(RedisRelay::class, 'server');
    $server->setAccessible(true);
    $server->setValue($relay, $swoole ?? RelaySigningSwooleServer::make());

    return $relay;
}

/** Put one connection on a channel so a delivered broadcast has somewhere to land. */
function relaySigningSubscriber(string $channel): int
{
    $fd = random_int(1000, 999999);

    $channels = app(ChannelManager::class);
    $channels->connect($fd, "{$fd}.".random_int(1000, 999999), []);
    $channels->subscribe($fd, $channel, null);

    return $fd;
}

/** Write one control entry straight onto the stream, exactly as a peer would. */
function putControlEntry(string $stream, array $fields): void
{
    RedisStreams::add(
        Redis::connection(config('lightspeed.relay.redis_connection', 'default'))->client(),
        $stream,
        $fields + ['origin_process_key' => 'some-other-worker'],
    );
}

function drainRelay(RedisRelay $relay): void
{
    $drain = new ReflectionMethod(RedisRelay::class, 'drainBroadcasts');
    $drain->setAccessible(true);
    $drain->invoke($relay);
}

afterEach(function () {
    if (isset($this->stream)) {
        Redis::connection('default')->del($this->stream);
    }
});

test('a control entry with no signature is refused', function () {
    $this->stream = $stream = relayTestStream();
    $relay = relayFor($stream);

    $heard = [];
    $relay->onControl(RevocationLog::CONTROL_TYPE, function (array $payload) use (&$heard) {
        $heard[] = $payload;
    });

    putControlEntry($stream, [
        'control' => RevocationLog::CONTROL_TYPE,
        'payload' => json_encode(['tag' => 'project:7', 'revoked_at' => 1]),
    ]);

    drainRelay($relay);

    expect($heard)->toBe([]);
});

test('a control entry signed with the wrong secret is refused', function () {
    $this->stream = $stream = relayTestStream();
    $relay = relayFor($stream);

    $heard = [];
    $relay->onControl(RevocationLog::CONTROL_TYPE, function (array $payload) use (&$heard) {
        $heard[] = $payload;
    });

    $payload = json_encode(['tag' => 'project:7', 'revoked_at' => 1]);

    putControlEntry($stream, [
        'control' => RevocationLog::CONTROL_TYPE,
        'payload' => $payload,
        'signature' => hash_hmac('sha256', $payload, 'not-the-app-secret'),
    ]);

    drainRelay($relay);

    expect($heard)->toBe([]);
});

test('a control entry whose payload was edited after signing is refused', function () {
    $this->stream = $stream = relayTestStream();
    $relay = relayFor($stream);

    $heard = [];
    $relay->onControl(RevocationLog::CONTROL_TYPE, function (array $payload) use (&$heard) {
        $heard[] = $payload;
    });

    // The signature the package itself would write, over a DIFFERENT tag.
    $signed = ['tag' => 'project:7', 'revoked_at' => 1];
    $relay->publishControl(RevocationLog::CONTROL_TYPE, $signed);

    $entries = RedisStreams::read(
        Redis::connection('default')->client(),
        $stream,
        '0-0',
        10,
    );

    $fields = RedisStreams::normalizeFields($entries[0][1]);

    expect($fields['signature'] ?? null)->toBeString();

    putControlEntry($stream, [
        'control' => RevocationLog::CONTROL_TYPE,
        'payload' => json_encode(['tag' => 'project:everything', 'revoked_at' => 1]),
        'signature' => $fields['signature'],
    ]);

    drainRelay($relay);

    expect($heard)->toBe([]);
});

test('a control entry the package published itself is delivered to a peer', function () {
    $this->stream = $stream = relayTestStream();

    // Published by "another worker": publishControl never replays a process's
    // own entries back to it, so the origin key is rewritten to a peer's before
    // the drain reads it back.
    $relay = relayFor($stream);
    $relay->publishControl(RevocationLog::CONTROL_TYPE, ['tag' => 'project:7', 'revoked_at' => 99]);

    $entries = RedisStreams::read(Redis::connection('default')->client(), $stream, '0-0', 10);
    $fields = RedisStreams::normalizeFields($entries[0][1]);

    Redis::connection('default')->del($stream);

    putControlEntry($stream, [
        'control' => RevocationLog::CONTROL_TYPE,
        'payload' => $fields['payload'],
        'signature' => $fields['signature'],
    ]);

    $heard = [];
    $relay->onControl(RevocationLog::CONTROL_TYPE, function (array $payload) use (&$heard) {
        $heard[] = $payload;
    });

    drainRelay($relay);

    expect($heard)->toBe([['tag' => 'project:7', 'revoked_at' => 99]]);
});

/**
 * The poll loop runs inside a Swoole timer callback, where an escaping
 * exception is fatal to the worker rather than merely logged. And with
 * enable_coroutine defaulting to false there is no coroutine to contain it
 * either. One entry that cannot be delivered must therefore cost that entry and
 * nothing else, or a single bad frame takes every later broadcast, presence
 * event and revocation with it.
 */
test('an entry that cannot be delivered does not stop the poll', function () {
    $this->stream = $stream = relayTestStream();
    $relay = relayFor($stream);

    $heard = [];
    $relay->onControl('lightspeed-test-control', function (array $payload) use (&$heard) {
        $heard[] = $payload;
    });

    // A broadcast whose fan-out throws, sitting in front of a control entry.
    $subscribers = new ReflectionProperty(\Lightspeed\Channels\ChannelManager::class, 'channelSubscribers');
    $subscribers->setAccessible(true);
    $subscribers->setValue(app(\Lightspeed\Channels\ChannelManager::class), ['private-doomed' => [4242 => true]]);

    $server = new ReflectionProperty(RedisRelay::class, 'server');
    $server->setAccessible(true);
    $server->setValue($relay, new class extends SwooleServer
    {
        public function __construct()
        {
        }

        public function isEstablished(int $fd): bool
        {
            throw new \RuntimeException('this socket is gone');
        }
    });

    putControlEntry($stream, [
        'channels' => json_encode(['private-doomed']),
        'message' => json_encode(['event' => 'x']),
        'except_socket_id' => '',
    ]);

    $payload = json_encode(['tag' => 'project:7']);
    putControlEntry($stream, [
        'control' => 'lightspeed-test-control',
        'payload' => $payload,
        'signature' => hash_hmac('sha256', 'lightspeed-test-control'."\0".$payload, config('lightspeed.reverb_compat.app_secret')),
    ]);

    drainRelay($relay);

    // The entry behind the broken one still arrived.
    expect($heard)->toBe([['tag' => 'project:7']]);
});

test('an ordinary broadcast entry is untouched by control signing', function () {
    $this->stream = $stream = relayTestStream();
    $swoole = RelaySigningSwooleServer::make();
    $relay = relayFor($stream, $swoole);

    // Broadcasts carry no authorization decision. They are the payloads the
    // relay has always moved, so nothing here may start refusing them.
    //
    // This used to assert only that draining threw nothing, which a relay that
    // DISCARDED every unsigned broadcast passed just as happily. That is the
    // exact regression the test is named for, so the assertion is now that the
    // payload reached the socket.
    $fd = relaySigningSubscriber('private-doc.1');

    putControlEntry($stream, [
        'channels' => json_encode(['private-doc.1']),
        'message' => json_encode(['event' => 'x']),
        'except_socket_id' => '',
    ]);

    drainRelay($relay);

    expect($swoole->pushed)->toHaveCount(1)
        ->and($swoole->pushed[0]['event'])->toBe('x')
        ->and($swoole->pushed[0]['channel'])->toBe('private-doc.1');
});

/**
 * S4. The claim in 221f2d6, "holding the Redis credentials is no longer enough
 * to force connections off a channel", is true, and is narrower than it reads.
 *
 * Ordinary broadcast entries remain unsigned by design, so Redis write access
 * still injects any payload into any private channel, which is strictly worse
 * than forcing an unsubscribe. Signing broadcasts was considered and not done:
 * it puts an HMAC on the fan-out path, which is the hot path this package is
 * measured on, and it makes a rolling deploy discard the entries written by
 * whichever half of the fleet has not restarted. The decision and its
 * consequence are written down in SECURITY.md; this pins the behaviour those
 * words describe, so that the two cannot drift apart silently.
 */
test('an unsigned broadcast entry from any origin still reaches a private channel', function () {
    $this->stream = $stream = relayTestStream();
    $relay = relayFor($stream);

    $channel = 'private-orders.'.bin2hex(random_bytes(4));
    $fd = random_int(1000, 999999);

    $subscribers = new ReflectionProperty(\Lightspeed\Channels\ChannelManager::class, 'channelSubscribers');
    $subscribers->setAccessible(true);
    $subscribers->setValue(app(\Lightspeed\Channels\ChannelManager::class), [$channel => [$fd => true]]);

    $delivered = [];

    $server = new ReflectionProperty(RedisRelay::class, 'server');
    $server->setAccessible(true);
    $server->setValue($relay, new class($delivered) extends SwooleServer
    {
        public function __construct(public array &$delivered)
        {
        }

        public function isEstablished(int $fd): bool
        {
            return true;
        }

        public function push(int $fd, \Swoole\WebSocket\Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
        {
            $this->delivered[] = (string) $data;

            return true;
        }
    });

    // No signature, and an origin_process_key of the writer's choosing.
    putControlEntry($stream, [
        'channels' => json_encode([$channel]),
        'message' => json_encode(['event' => 'anything', 'channel' => $channel]),
        'except_socket_id' => '',
    ]);

    drainRelay($relay);

    expect($delivered)->toHaveCount(1)
        ->and($delivered[0])->toContain('anything');
});
