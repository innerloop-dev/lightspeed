<?php

use Lightspeed\Channels\Subscriptions;
use Lightspeed\ClientEvents\ClientEventGate;
use Lightspeed\Diagnostics\DiagnosticProtocol;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\FrameRouter;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * One frame in, one decode, one log line, one handler.
 *
 * The hot path, and the only place a frame is turned into a decision. Two
 * separate contracts run through it and neither is checked anywhere else.
 *
 * The first is the message log. It is what an operator reads when a client
 * reports that nothing arrived, and every member of it answers a different
 * question: which connection, what it called itself, which channel it named,
 * which request it correlates with. A member that quietly stops being written
 * makes the log read as if the frame simply had no channel.
 *
 * The second is the decode itself. `data` arrives as a JSON *string* in the
 * Pusher protocol and as an array in almost every test ever written against it,
 * so the branch that tells them apart has to hand the handler the same thing
 * either way. Downstream, a subscribe with a string where an array belongs is
 * refused as a malformed subscribe, which looks exactly like a bad auth string.
 */
class ProtoMutRouterLogger extends RuntimeLogger
{
    /** @var list<array{action: string, fields: array}> */
    public array $entries = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->entries[] = ['action' => $action, 'fields' => $fields];
    }

    /** Stands in for the config-gated snippet, so the field is unmistakable. */
    public function maybePayloadSnippet(mixed $payload): ?string
    {
        return '<snippet>';
    }
}

class ProtoMutRouterSubscriptions extends Subscriptions
{
    /** @var list<array{verb: string, fd: int, data: mixed}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function handlePusherSubscribe(SwooleServer $server, int $fd, mixed $data): void
    {
        $this->calls[] = ['verb' => 'subscribe', 'fd' => $fd, 'data' => $data];
    }

    public function handlePusherUnsubscribe(SwooleServer $server, int $fd, mixed $data): void
    {
        $this->calls[] = ['verb' => 'unsubscribe', 'fd' => $fd, 'data' => $data];
    }
}

class ProtoMutRouterClientEvents extends ClientEventGate
{
    /** @var list<array{channel: ?string, event: string, data: mixed, bytes: int}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function handlePusherClientEvent(SwooleServer $server, int $fd, ?string $channel, string $event, mixed $data, int $frameBytes): void
    {
        $this->calls[] = ['channel' => $channel, 'event' => $event, 'data' => $data, 'bytes' => $frameBytes];
    }
}

class ProtoMutRouterDiagnostics extends DiagnosticProtocol
{
    /** @var list<mixed> */
    public array $handled = [];

    public function __construct()
    {
    }

    public function handle(int $fd, mixed $payload): array
    {
        $this->handled[] = $payload;

        return ['type' => 'proto-mut-diagnostic'];
    }
}

class ProtoMutRouterDelivery extends Delivery
{
    /** @var list<array> */
    public array $pushed = [];

    /** @var list<array{code: string, message: string}> */
    public array $errors = [];

    /** @var list<array{event: string, channel: ?string}> */
    public array $events = [];

    public function __construct()
    {
    }

    public function push(SwooleServer $server, int $fd, array $payload): void
    {
        $this->pushed[] = $payload;
    }

    public function pushPusherError(SwooleServer $server, int $fd, string $code, string $message): void
    {
        $this->errors[] = ['code' => $code, 'message' => $message];
    }

    public function pushPusherEvent(SwooleServer $server, int $fd, string $event, ?string $channel, mixed $data): void
    {
        $this->events[] = ['event' => $event, 'channel' => $channel];
    }
}

/**
 * The router under test, with everything it talks to recording instead.
 *
 * Returns the recorders alongside it, under `router`, so a test can read what
 * the frame it sent turned into.
 */
function proto_mut_router(bool $diagnosticEligible = false): array
{
    $parts = [
        'logger' => ProtoMutRouterLogger::make(),
        'subscriptions' => new ProtoMutRouterSubscriptions(),
        'clientEvents' => new ProtoMutRouterClientEvents(),
        'diagnostics' => new ProtoMutRouterDiagnostics(),
        'delivery' => new ProtoMutRouterDelivery(),
        'sockets' => new DiagnosticSockets(),
    ];

    if ($diagnosticEligible) {
        $parts['sockets']->admit(7);
    }

    $parts['router'] = new FrameRouter(
        $parts['subscriptions'],
        $parts['clientEvents'],
        $parts['diagnostics'],
        $parts['sockets'],
        $parts['delivery'],
        $parts['logger'],
    );

    return $parts;
}

function proto_mut_frame(string $data, int $fd = 7): Frame
{
    $frame = (new ReflectionClass(Frame::class))->newInstanceWithoutConstructor();
    $frame->fd = $fd;
    $frame->opcode = WEBSOCKET_OPCODE_TEXT;
    $frame->data = $data;

    return $frame;
}

/** The fields of the single `message` entry, or null if none was written. */
function proto_mut_message_fields(ProtoMutRouterLogger $logger): ?array
{
    foreach ($logger->entries as $entry) {
        if ($entry['action'] === 'message') {
            return $entry['fields'];
        }
    }

    return null;
}

function proto_mut_swoole(): SwooleServer
{
    return (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor();
}

test('a pusher frame is logged by its event name and routed with its data decoded', function () {
    $parts = proto_mut_router();

    $data = json_encode([
        'event' => 'pusher:subscribe',
        'channel' => 'private-orders.7',
        'requestId' => 'req-1',
        'data' => ['channel' => 'private-orders.7'],
    ]);

    $parts['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame($data));

    // The whole entry. Any member that stops being written leaves an operator
    // reading a frame that appears to have named no channel, or arrived on no
    // connection.
    expect(proto_mut_message_fields($parts['logger']))->toBe([
        'fd' => 7,
        'opcode' => WEBSOCKET_OPCODE_TEXT,
        'label' => 'pusher:subscribe',
        'bytes' => strlen($data),
        'channel' => 'private-orders.7',
        'request' => 'req-1',
        'payload' => '<snippet>',
    ]);

    // And the frame reached the subscribe handler carrying its `data` member,
    // not a null and not the whole envelope.
    expect($parts['subscriptions']->calls)->toBe([
        ['verb' => 'subscribe', 'fd' => 7, 'data' => ['channel' => 'private-orders.7']],
    ]);

    // A Pusher frame is answered by the Pusher handlers and then the router is
    // done with it. Falling on through would hand the same frame to the
    // protocol split as well, and tell a client that just subscribed that its
    // message shape was unsupported.
    expect($parts['delivery']->errors)->toBe([])
        ->and($parts['diagnostics']->handled)->toBe([]);
});

test('a diagnostic frame is logged by its type, since it carries no event name', function () {
    $parts = proto_mut_router(diagnosticEligible: true);

    $parts['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame(json_encode(['type' => 'whoami'])));

    expect(proto_mut_message_fields($parts['logger'])['label'])->toBe('whoami')
        ->and($parts['diagnostics']->handled)->toBe([['type' => 'whoami']])
        ->and($parts['delivery']->pushed)->toBe([['type' => 'proto-mut-diagnostic']]);
});

test('a frame that is not json is logged by its php type, with no channel fields invented for it', function () {
    $parts = proto_mut_router();

    $parts['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame('hello'));

    // The channel/request/payload members belong to a decoded envelope. A
    // frame that is not one has no channel to name, and reading one out of a
    // bare string is not a missing log field, it is a fatal on the hot path.
    expect(proto_mut_message_fields($parts['logger']))->toBe([
        'fd' => 7,
        'opcode' => WEBSOCKET_OPCODE_TEXT,
        'label' => 'string',
        'bytes' => 5,
    ]);
});

test('an empty frame is decoded as nothing at all, not as the empty string', function () {
    $parts = proto_mut_router();

    $parts['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame(''));

    // The shortcut exists because json_decode('') throws, and the thrown case
    // falls back to the RAW frame. So without it an empty frame logs as a
    // zero-byte string payload rather than as nothing arriving.
    expect(proto_mut_message_fields($parts['logger']))->toBe([
        'fd' => 7,
        'opcode' => WEBSOCKET_OPCODE_TEXT,
        'label' => 'NULL',
        'bytes' => 0,
    ]);
});

test('the gate is told how many bytes actually arrived, not how many the decoded payload weighs', function () {
    // The wire-to-gate half of the client-event size limit. The gate refuses on
    // this number, and the number has to be the bytes the client sent: the
    // decoded payload is a different size (whitespace, escapes and the `data`
    // string's own quoting all disappear in the decode), so a router that
    // measured what it had left after decoding would enforce a limit against a
    // quantity no client controls directly.
    $frame = json_encode([
        'event' => 'client-typing',
        'channel' => 'private-orders.7',
        // Deliberately padded: an implementation that re-derived the size from
        // the decoded payload would come out smaller than this.
        'data' => '{"typing":   true}',
    ]);

    $parts = proto_mut_router();
    $parts['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame($frame));

    expect($parts['clientEvents']->calls)->toHaveCount(1)
        ->and($parts['clientEvents']->calls[0]['bytes'])->toBe(strlen($frame));
});

test('the decode depth limit refuses a frame nested past it and accepts one at it', function () {
    // The bound on how much work one frame may cost before it is refused. Both
    // sides of it, because a limit only tested from the far side is satisfied by
    // a limit of anything smaller, including one that refuses ordinary traffic.
    $atTheLimit = str_repeat('[', 511).'1'.str_repeat(']', 511);
    $pastTheLimit = str_repeat('[', 512).'1'.str_repeat(']', 512);

    $parts = proto_mut_router();
    $parts['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame($atTheLimit));

    expect(proto_mut_message_fields($parts['logger'])['label'])->toBe('json');

    $other = proto_mut_router();
    $other['router']->receiveFrame(proto_mut_swoole(), proto_mut_frame($pastTheLimit));

    // Refused frames fall back to the raw text, which is what `string` means
    // here: nothing was decoded and no handler is reached.
    expect(proto_mut_message_fields($other['logger'])['label'])->toBe('string');
});

test('the same depth limit bounds the event data, which arrives as its own json string', function () {
    // `data` is a JSON string inside a JSON frame, so it is decoded a second
    // time and is a second place the same attacker-chosen nesting lands.
    $atTheLimit = str_repeat('[', 511).'1'.str_repeat(']', 511);
    $pastTheLimit = str_repeat('[', 512).'1'.str_repeat(']', 512);

    $parts = proto_mut_router();
    $parts['router']->handlePusherMessage(proto_mut_swoole(), 7, ['event' => 'pusher:subscribe', 'data' => $atTheLimit], strlen($atTheLimit));
    $parts['router']->handlePusherMessage(proto_mut_swoole(), 7, ['event' => 'pusher:subscribe', 'data' => $pastTheLimit], strlen($pastTheLimit));

    // Decoded at the limit, and handed on as the raw string past it, which is
    // what every handler downstream refuses as a malformed frame.
    expect($parts['subscriptions']->calls[0]['data'])->toBeArray()
        ->and($parts['subscriptions']->calls[1]['data'])->toBe($pastTheLimit);
});

test('an event with no name is refused as a missing event, not as an unsupported one', function () {
    $parts = proto_mut_router();

    $parts['router']->handlePusherMessage(proto_mut_swoole(), 7, ['event' => ''], 16);

    // The two refusals mean different things to whoever reads them: one says
    // the frame was not a Pusher frame at all, the other says this server does
    // not implement the event that was named. An empty name is the first.
    expect($parts['delivery']->errors)->toHaveCount(1)
        ->and($parts['delivery']->errors[0]['code'])->toBe('invalid-event');
});

test('a client event is routed to the client event gate with the channel it named', function () {
    $parts = proto_mut_router();

    $parts['router']->handlePusherMessage(proto_mut_swoole(), 7, [
        'event' => 'client-typing',
        'channel' => 'private-orders.7',
        'data' => '{"typing":true}',
    ], 84);

    // The channel is the whole authorization question for a client event, and
    // `data` arrives as a JSON string on the wire: a gate handed the raw string
    // cannot read a field out of it.
    expect($parts['clientEvents']->calls)->toBe([
        [
            'channel' => 'private-orders.7',
            'event' => 'client-typing',
            'data' => ['typing' => true],
            // The size of the frame as it arrived, carried through so the gate
            // can bound it. Measuring it here instead would mean re-encoding
            // the decoded payload, which is not the bytes that arrived.
            'bytes' => 84,
        ],
    ]);

    expect($parts['delivery']->errors)->toBe([]);
});

test('event data that is already decoded is passed through rather than decoded again', function () {
    $parts = proto_mut_router();

    $parts['router']->handlePusherMessage(proto_mut_swoole(), 7, [
        'event' => 'pusher:unsubscribe',
        'data' => ['channel' => 'private-orders.7'],
    ], 72);

    expect($parts['subscriptions']->calls)->toBe([
        ['verb' => 'unsubscribe', 'fd' => 7, 'data' => ['channel' => 'private-orders.7']],
    ]);
});
