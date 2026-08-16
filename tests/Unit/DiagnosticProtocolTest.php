<?php

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticProtocol;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\WorkerContext;

/**
 * The diagnostic protocol performs no authorization of its own, Server's
 * `open` handler decides once per connection who may speak it. So the property
 * that keeps it contained is the shape of what it returns: one frame, always in
 * the `{"type": ...}` envelope, never a Pusher one. If a verb ever answered with
 * a `{"event": ...}` frame it would be indistinguishable from a real Pusher
 * event to anything downstream, and if a verb answered with nothing the caller
 * would be left waiting on a probe that silently did nothing.
 *
 * These tests pin that: every verb, valid or not, produces exactly one
 * diagnostic-envelope frame, and the malformed ones produce it without touching
 * subscription state or the relay.
 *
 * The bridge is stubbed so whisper and broadcast-test can be exercised with no
 * Redis and no listening socket.
 */

/** Records fan-out instead of performing it: no relay, no Redis, no sockets. */
final class RecordingDiagnosticBridge extends BroadcastBridge
{
    /** @var array<int, array{channels: array, message: array, exceptSocketId: ?string}> */
    public array $messages = [];

    public function fanOutMessage(array $channels, array $baseMessage, ?string $exceptSocketId = null): int
    {
        $this->messages[] = [
            'channels' => $channels,
            'message' => $baseMessage,
            'exceptSocketId' => $exceptSocketId,
        ];

        return 2;
    }
}

function diagnosticProtocolFor(ChannelManager $channels, RecordingDiagnosticBridge $bridge): DiagnosticProtocol
{
    return new DiagnosticProtocol($channels, $bridge, app(WorkerContext::class));
}

function diagnosticBridge(): RecordingDiagnosticBridge
{
    return new RecordingDiagnosticBridge(app(RedisRelay::class));
}

test('it answers a frame that is not an object with a diagnostic error', function () {
    $frame = diagnosticProtocolFor(new ChannelManager(), diagnosticBridge())->handle(1, 'just a string');

    expect($frame)->toBe([
        'type' => 'error',
        'code' => 'invalid-message',
        'message' => 'Expected a JSON object payload.',
    ]);
});

test('it requires a message type and rejects one it does not know', function () {
    $protocol = diagnosticProtocolFor(new ChannelManager(), diagnosticBridge());

    expect($protocol->handle(1, ['channel' => 'room']))
        ->toBe(['type' => 'error', 'code' => 'invalid-message', 'message' => 'Missing message type.']);

    expect($protocol->handle(1, ['type' => 'evict']))
        ->toBe(['type' => 'error', 'code' => 'unsupported-type', 'message' => 'Unsupported message type [evict].']);
});

test('subscribe joins the channel and reports its members back', function () {
    $channels = new ChannelManager();
    $channels->connect(1, '1.100');

    $frame = diagnosticProtocolFor($channels, diagnosticBridge())->handle(1, [
        'type' => 'subscribe',
        'channel' => 'room',
    ]);

    expect($frame['type'])->toBe('subscribed')
        ->and($frame['channel'])->toBe('room')
        ->and($channels->isSubscribed(1, 'room'))->toBeTrue();
});

test('unsubscribe leaves the channel', function () {
    $channels = new ChannelManager();
    $channels->connect(1, '1.100');
    $channels->subscribe(1, 'room');

    $frame = diagnosticProtocolFor($channels, diagnosticBridge())->handle(1, [
        'type' => 'unsubscribe',
        'channel' => 'room',
    ]);

    expect($frame)->toBe(['type' => 'unsubscribed', 'channel' => 'room'])
        ->and($channels->isSubscribed(1, 'room'))->toBeFalse();
});

test('every verb refuses a missing channel without touching subscription state', function () {
    $channels = new ChannelManager();
    $bridge = diagnosticBridge();
    $protocol = diagnosticProtocolFor($channels, $bridge);

    foreach (['subscribe' => 'Subscribe', 'unsubscribe' => 'Unsubscribe', 'whisper' => 'Whisper'] as $type => $label) {
        expect($protocol->handle(1, ['type' => $type]))->toBe([
            'type' => 'error',
            'code' => 'invalid-channel',
            'message' => "{$label} requires a channel string.",
        ]);
    }

    expect($channels->channelsFor(1))->toBe([])
        ->and($bridge->messages)->toBe([]);
});

test('whisper only reaches a channel this connection is subscribed to', function () {
    $channels = new ChannelManager();
    $channels->connect(1, '1.100');
    $bridge = diagnosticBridge();
    $protocol = diagnosticProtocolFor($channels, $bridge);

    expect($protocol->handle(1, ['type' => 'whisper', 'channel' => 'room']))->toBe([
        'type' => 'error',
        'code' => 'not-subscribed',
        'message' => 'Connection is not subscribed to [room].',
    ]);
    expect($bridge->messages)->toBe([]);

    $channels->subscribe(1, 'room');

    expect($protocol->handle(1, ['type' => 'whisper', 'channel' => 'room', 'data' => ['hi' => true]]))->toBe([
        'type' => 'whispered',
        'channel' => 'room',
        'delivered' => 2,
    ]);

    // The whisperer is excluded by its own socket id, so a probe does not hear
    // its own frame come back.
    expect($bridge->messages)->toHaveCount(1)
        ->and($bridge->messages[0]['exceptSocketId'])->toBe('1.100')
        ->and($bridge->messages[0]['message'])->toBe([
            'type' => 'whisper',
            'from' => ['id' => '1'],
            'data' => ['hi' => true],
        ]);
});

test('send requires an id before anything else, because a response without one is unreadable', function () {
    $protocol = diagnosticProtocolFor(new ChannelManager(), diagnosticBridge());

    expect($protocol->handle(1, ['type' => 'send', 'action' => 'ping']))
        ->toBe(['type' => 'error', 'code' => 'invalid-id', 'message' => 'Send requires a string id.']);

    expect($protocol->handle(1, ['type' => 'send', 'id' => 'r1']))
        ->toBe(['type' => 'response', 'id' => 'r1', 'ok' => false, 'error' => 'Send requires an action.']);

    expect($protocol->handle(1, ['type' => 'send', 'id' => 'r1', 'action' => 'shutdown']))
        ->toBe(['type' => 'response', 'id' => 'r1', 'ok' => false, 'error' => 'Unsupported action [shutdown].']);
});

test('ping echoes back under the id it was asked with', function () {
    $frame = diagnosticProtocolFor(new ChannelManager(), diagnosticBridge())->handle(1, [
        'type' => 'send',
        'id' => 'r1',
        'action' => 'ping',
        'data' => ['n' => 1],
    ]);

    expect($frame)->toBe([
        'type' => 'response',
        'id' => 'r1',
        'ok' => true,
        'data' => ['pong' => true, 'echo' => ['n' => 1]],
    ]);
});

test('whoami reports this connection and the worker identity it landed on', function () {
    $channels = new ChannelManager();
    $channels->connect(7, '7.100');
    $channels->subscribe(7, 'room');

    $frame = diagnosticProtocolFor($channels, diagnosticBridge())->handle(7, [
        'type' => 'send',
        'id' => 'r1',
        'action' => 'whoami',
    ]);

    expect($frame['ok'])->toBeTrue()
        ->and($frame['data']['fd'])->toBe(7)
        ->and($frame['data']['channels'])->toBe(['room'])
        ->and($frame['data']['processKey'])->toBe(app(WorkerContext::class)->currentProcessKey());
});

test('broadcast-test validates before it broadcasts', function () {
    $bridge = diagnosticBridge();
    $protocol = diagnosticProtocolFor(new ChannelManager(), $bridge);

    expect($protocol->handle(1, ['type' => 'send', 'id' => 'r1', 'action' => 'broadcast-test', 'data' => ['event' => 'e']]))
        ->toBe(['type' => 'response', 'id' => 'r1', 'ok' => false, 'error' => 'broadcast-test requires a channel.']);

    expect($protocol->handle(1, ['type' => 'send', 'id' => 'r1', 'action' => 'broadcast-test', 'data' => ['channel' => 'room']]))
        ->toBe(['type' => 'response', 'id' => 'r1', 'ok' => false, 'error' => 'broadcast-test requires an event.']);

    expect($bridge->messages)->toBe([]);

    $frame = $protocol->handle(1, [
        'type' => 'send',
        'id' => 'r1',
        'action' => 'broadcast-test',
        'data' => ['channel' => 'room', 'event' => 'e', 'payload' => ['id' => 1]],
    ]);

    expect($frame)->toBe([
        'type' => 'response',
        'id' => 'r1',
        'ok' => true,
        'data' => ['channel' => 'room', 'event' => 'e', 'delivered' => 2],
    ]);

    // A broadcast test is deliberately not excluded from its own channel: the
    // point of it is to see the frame arrive.
    expect($bridge->messages)->toHaveCount(1)
        ->and($bridge->messages[0]['exceptSocketId'])->toBeNull()
        ->and($bridge->messages[0]['message'])->toBe([
            'type' => 'broadcast',
            'channel' => 'room',
            'event' => 'e',
            'data' => ['id' => 1],
        ]);
});

test('a diagnostic whisper never echoes back to the connection that sent it', function () {
    // A diagnostic connection is opened by Server without a handshake, so it
    // has no socket id at all, this is the exact state Server leaves an fd in.
    $channels = new ChannelManager();
    $channels->subscribe(9, 'room');
    $bridge = diagnosticBridge();

    expect($channels->socketIdFor(9))->toBeNull();

    $frame = diagnosticProtocolFor($channels, $bridge)->handle(9, [
        'type' => 'whisper',
        'channel' => 'room',
        'data' => ['hi' => true],
    ]);

    expect($frame['type'])->toBe('whispered');

    // The relay resolves the exclusion the same way: socket id back to a local
    // fd. If that lookup does not land on the whisperer, the whisperer is a
    // subscriber like any other and receives its own frame.
    $exceptSocketId = $bridge->messages[0]['exceptSocketId'];

    expect($exceptSocketId)->not->toBeNull()
        ->and($channels->fdForSocketId((string) $exceptSocketId))->toBe(9);

    // The wire shape is unchanged: the sender is still named by fd, and the
    // exclusion id is deliberately not a Pusher socket id ("{fd}.{random}").
    expect($bridge->messages[0]['message'])->toBe([
        'type' => 'whisper',
        'from' => ['id' => '9'],
        'data' => ['hi' => true],
    ])->and($exceptSocketId)->not->toContain('.');
});

test('a whisper from a real pusher socket still excludes by its own socket id', function () {
    $channels = new ChannelManager();
    $channels->connect(9, '9.424242', ['ip' => '127.0.0.1']);
    $channels->subscribe(9, 'room');
    $bridge = diagnosticBridge();

    diagnosticProtocolFor($channels, $bridge)->handle(9, ['type' => 'whisper', 'channel' => 'room']);

    expect($bridge->messages[0]['exceptSocketId'])->toBe('9.424242')
        ->and($channels->connectionContext(9))->toBe(['ip' => '127.0.0.1']);
});
