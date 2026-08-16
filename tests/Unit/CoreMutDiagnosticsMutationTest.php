<?php

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticProtocol;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\WorkerContext;

/**
 * The operator's own channel: who is allowed to speak it, and what each verb
 * refuses.
 *
 * This protocol performs NO authorization of its own and reaches subscribe,
 * whisper and broadcast without an app key or a channel authorization round
 * trip, which is why the gate in front of it and the input guards inside it are
 * the whole of its containment. A verb that accepted an empty channel or an
 * empty action would be answering a malformed frame with real work.
 */

/** Records fan-out instead of performing it: no relay, no Redis, no sockets. */
final class CoreMutDiagnosticBridge extends BroadcastBridge
{
    /** @var list<array{channels: array, message: array, except: ?string}> */
    public array $messages = [];

    public function fanOutMessage(array $channels, array $baseMessage, ?string $exceptSocketId = null): int
    {
        $this->messages[] = [
            'channels' => $channels,
            'message' => $baseMessage,
            'except' => $exceptSocketId,
        ];

        return count($channels);
    }
}

function coreMutDiagnosticBridge(): CoreMutDiagnosticBridge
{
    return new CoreMutDiagnosticBridge(app(RedisRelay::class));
}

function coreMutDiagnostics(ChannelManager $channels, ?CoreMutDiagnosticBridge $bridge = null): DiagnosticProtocol
{
    return new DiagnosticProtocol($channels, $bridge ?? coreMutDiagnosticBridge(), app(WorkerContext::class));
}

// ---------------------------------------------------------------------------
// The gate. Three refusals, deliberately distinguishable.
// ---------------------------------------------------------------------------

test('the diagnostic protocol is closed on a configuration nobody edited', function () {
    // The safe default doing its job. This surface performs no channel
    // authorization at all, so the default has to be off and the fallback the
    // gate reads through has to agree with it: a default of on is a package
    // that ships an unauthenticated inspection channel.
    config()->set('lightspeed.diagnostics', []);

    expect((new DiagnosticSockets())->refusalReason('127.0.0.1'))->toBe('diagnostics-disabled');
});

test('with no allow list configured, both loopback addresses are still allowed and nothing else is', function () {
    // The default list is the whole allow list on an install that turned
    // diagnostics on without naming addresses, and it has to carry BOTH
    // loopback forms: a v6-only stack reaches this as ::1, and dropping either
    // one turns "enabled" into "enabled and unreachable" on half the hosts.
    config()->set('lightspeed.diagnostics', ['enabled' => true]);

    $sockets = new DiagnosticSockets();

    expect($sockets->refusalReason('127.0.0.1'))->toBeNull()
        ->and($sockets->refusalReason('::1'))->toBeNull()
        ->and($sockets->refusalReason('10.0.0.9'))->toBe('address-not-allowed');
});

test('an allow list written as a single address is read as a list of one', function () {
    // `LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM=127.0.0.1` reaching config as a bare
    // string is the ordinary way this arrives. Without the cast the membership
    // test is handed a string, which is a type error in front of the check
    // that decides who may speak an unauthorized protocol.
    config()->set('lightspeed.diagnostics.enabled', true);
    config()->set('lightspeed.diagnostics.allow_from', '10.0.0.9');

    $sockets = new DiagnosticSockets();

    expect($sockets->refusalReason('10.0.0.9'))->toBeNull()
        ->and($sockets->refusalReason('127.0.0.1'))->toBe('address-not-allowed');
});

test('a loopback peer that arrived through a proxy is refused, and refused by name', function () {
    // A loopback address is only evidence of a local caller when nothing is
    // forwarding on someone else's behalf. The three refusals are different
    // situations for whoever reads the log, and this is the one that means a
    // routing mistake is exposing an unauthorized protocol to the internet.
    config()->set('lightspeed.diagnostics.enabled', true);

    expect((new DiagnosticSockets())->refusalReason('127.0.0.1', ['x-forwarded-for' => '8.8.8.8']))
        ->toBe('proxied-request');
});

test('admission is remembered for the connection and forgotten when it closes', function () {
    // Eligibility is decided ONCE, at open, and never re-derived per frame: a
    // client that completed the Pusher handshake must not reach these verbs by
    // changing its frame shape mid-stream.
    $sockets = new DiagnosticSockets();

    expect($sockets->allows(7))->toBeFalse();

    $sockets->admit(7);
    expect($sockets->allows(7))->toBeTrue();

    $sockets->forget(7);
    expect($sockets->allows(7))->toBeFalse();
});

// ---------------------------------------------------------------------------
// What each verb refuses.
// ---------------------------------------------------------------------------

test('a frame whose type is present but empty is not a type', function () {
    expect(coreMutDiagnostics(new ChannelManager())->handle(1, ['type' => '']))
        ->toBe(['type' => 'error', 'code' => 'invalid-message', 'message' => 'Missing message type.']);
});

test('a channel that is empty, or not a string, is not a channel on any verb that takes one', function () {
    // One guard serves subscribe, unsubscribe and whisper. An empty channel
    // reaching subscribe would allocate membership under a name no unsubscribe
    // could ever be sent for, and a non-string one reaches the channel-name
    // bound as a type error.
    $protocol = coreMutDiagnostics(new ChannelManager());

    foreach (['subscribe', 'unsubscribe', 'whisper'] as $verb) {
        foreach (['', 42, null, ['room']] as $channel) {
            expect($protocol->handle(1, ['type' => $verb, 'channel' => $channel])['code'])
                ->toBe('invalid-channel');
        }
    }
});

test('a subscribe answers with the channel and who is already in it', function () {
    // The members list is the only thing that makes this verb an inspection
    // rather than a state change: a probe subscribes precisely to find out what
    // the node thinks the membership is.
    $channels = new ChannelManager();
    $channels->connect(1, '1.100');
    $channels->connect(2, '2.200');
    $channels->subscribe(2, 'presence-room');

    $frame = coreMutDiagnostics($channels)->handle(1, ['type' => 'subscribe', 'channel' => 'presence-room']);

    expect($frame['type'])->toBe('subscribed')
        ->and($frame['channel'])->toBe('presence-room')
        ->and($frame)->toHaveKey('members');
});

// ---------------------------------------------------------------------------
// Whisper, and the exclusion id that keeps a whisperer from hearing itself.
// ---------------------------------------------------------------------------

test('a whisper goes out on the channel it names', function () {
    // The channel list is what the fan-out routes on. An empty one delivers
    // the whisper nowhere while still answering `whispered`, which reports
    // success for a probe that did nothing.
    $channels = new ChannelManager();
    $channels->subscribe(1, 'room');

    $bridge = coreMutDiagnosticBridge();
    $frame = coreMutDiagnostics($channels, $bridge)->handle(1, ['type' => 'whisper', 'channel' => 'room']);

    expect($bridge->messages[0]['channels'])->toBe(['room'])
        ->and($frame['type'])->toBe('whispered')
        ->and($frame['channel'])->toBe('room')
        ->and($frame['delivered'])->toBe(1);
});

test('a whisper is excluded by an id built from the process key and the fd', function () {
    // A diagnostic connection has no socket id, and fan-out exclusion is keyed
    // by socket id the whole way down, so a null exclusion is no exclusion and
    // the whisperer hears its own whisper come back. The process key is what
    // makes the id mean nothing off-process: the exclusion travels on the Redis
    // stream verbatim, and a bare fd is unique in one server but not across a
    // cluster, so two instances each holding a connection numbered 3 would
    // silently exclude each other's.
    $channels = new ChannelManager();
    $channels->subscribe(3, 'room');

    $bridge = coreMutDiagnosticBridge();
    coreMutDiagnostics($channels, $bridge)->handle(3, ['type' => 'whisper', 'channel' => 'room']);

    $expected = app(WorkerContext::class)->currentProcessKey().':fd:3';

    expect($bridge->messages[0]['except'])->toBe($expected)
        // Registered on the connection, so the next whisper resolves it
        // without minting a second one.
        ->and($channels->socketIdFor(3))->toBe($expected);
});

test('a connection that already has a socket id keeps it rather than being renamed', function () {
    // Registering is idempotent and only ever happens for a connection with no
    // socket id, so a Pusher socket that reached this code would keep its real
    // one. A socket id that is present but empty is not a socket id: excluding
    // by it excludes nobody.
    $channels = new ChannelManager();
    $channels->connect(4, '4.900');
    $channels->subscribe(4, 'room');

    $withId = coreMutDiagnosticBridge();
    coreMutDiagnostics($channels, $withId)->handle(4, ['type' => 'whisper', 'channel' => 'room']);

    $blank = new ChannelManager();
    $blank->connect(5, '');
    $blank->subscribe(5, 'room');

    $withoutId = coreMutDiagnosticBridge();
    coreMutDiagnostics($blank, $withoutId)->handle(5, ['type' => 'whisper', 'channel' => 'room']);

    expect($withId->messages[0]['except'])->toBe('4.900')
        ->and($withoutId->messages[0]['except'])->toBe(app(WorkerContext::class)->currentProcessKey().':fd:5');
});

// ---------------------------------------------------------------------------
// send: ping, whoami, broadcast-test.
// ---------------------------------------------------------------------------

test('a send with no usable id or action is refused, and the two refusals differ', function () {
    // The id is what correlates a response with the request that asked for it,
    // so a send without one cannot be answered as a response at all and gets
    // the error envelope instead. A missing action can be answered, and is.
    $protocol = coreMutDiagnostics(new ChannelManager());

    expect($protocol->handle(1, ['type' => 'send', 'id' => '', 'action' => 'ping'])['code'])
        ->toBe('invalid-id')
        ->and($protocol->handle(1, ['type' => 'send', 'id' => 'r1', 'action' => ''])['error'])
        ->toBe('Send requires an action.');
});

test('whoami reports the identity a probe came to ask for', function () {
    // The entire point of the verb: which worker, in which instance, under
    // which process key. That triple is what an operator correlates against
    // Redis coordination state, and a missing field is a question the probe
    // cannot answer with nothing to show it used to be answerable.
    config()->set('lightspeed.server.instance_id', 'core-mut-instance');

    $context = app(WorkerContext::class);
    $context->boot((new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor(), 2);

    $channels = new ChannelManager();
    $channels->subscribe(9, 'room');

    $frame = coreMutDiagnostics($channels)->handle(9, ['type' => 'send', 'id' => 'r1', 'action' => 'whoami']);

    expect($frame['data']['fd'])->toBe(9)
        ->and($frame['data']['channels'])->toBe(['room'])
        ->and($frame['data']['workerId'])->toBe(2)
        ->and($frame['data']['instanceId'])->toBe('core-mut-instance')
        ->and($frame['data']['processKey'])->toBe('core-mut-instance:worker:2');
});

test('broadcast-test refuses without both a channel and an event to send on it', function () {
    // Two separate refusals because they are two separate mistakes, and a
    // broadcast with an empty channel or an empty event name is a fan-out that
    // reaches nothing while reporting that it worked.
    $protocol = coreMutDiagnostics(new ChannelManager());

    $noChannel = $protocol->handle(1, [
        'type' => 'send', 'id' => 'r1', 'action' => 'broadcast-test',
        'data' => ['channel' => '', 'event' => 'ping'],
    ]);

    $noEvent = $protocol->handle(1, [
        'type' => 'send', 'id' => 'r1', 'action' => 'broadcast-test',
        'data' => ['channel' => 'room', 'event' => ''],
    ]);

    expect($noChannel['error'])->toBe('broadcast-test requires a channel.')
        ->and($noEvent['error'])->toBe('broadcast-test requires an event.');
});

test('broadcast-test sends on the channel it was given', function () {
    $bridge = coreMutDiagnosticBridge();

    $frame = coreMutDiagnostics(new ChannelManager(), $bridge)->handle(1, [
        'type' => 'send', 'id' => 'r1', 'action' => 'broadcast-test',
        'data' => ['channel' => 'room', 'event' => 'probe'],
    ]);

    expect($bridge->messages[0]['channels'])->toBe(['room'])
        ->and($frame['data']['delivered'])->toBe(1)
        ->and($frame['data']['channel'])->toBe('room')
        ->and($frame['data']['event'])->toBe('probe');
});
