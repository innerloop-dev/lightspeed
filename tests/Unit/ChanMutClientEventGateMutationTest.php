<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventDispatcher;
use Lightspeed\ClientEvents\ClientEventLimits;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The six refusals a `client-*` frame passes, and what the application is told
 * about the connection that sent it.
 *
 * Each refusal is one comparison, and each of them fails open if that
 * comparison is wrong: a channel test that matches the end of a name instead of
 * its start lets `room-private-` through as if it were private, and a channel
 * check that no longer recognises the empty string sends the wrong refusal for
 * a frame that names nothing. Neither shows up in the happy path, because the
 * happy path never sends either.
 *
 * The other half is what crosses into the application. The identity on a client
 * event is the one the application approved at subscribe, not one the client
 * asserted in this frame, and a handler reads it as a string. And the refusal
 * that is not a refusal, `lightspeed:stale`, is logged with the four fields
 * that say which connection lost which channel and why: the log line is the
 * only place a revocation that took effect is visible from.
 */

/** Records what the server pushed, in place of a real websocket. */
final class ChanMutGateSwoole extends SwooleServer
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

/** Runs the client-event task inline, in the container the test is already in. */
final class ChanMutGateWorker extends OctaneWorker
{
    public function __construct()
    {
    }

    public function isBooted(): bool
    {
        return true;
    }

    public function runTask(callable $task): mixed
    {
        return $task();
    }
}

/** Keeps the websocket runtime lines instead of writing them out. */
final class ChanMutGateLogger extends RuntimeLogger
{
    /** @var list<array{action: string, fields: array}> */
    public array $lines = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->lines[] = ['action' => $action, 'fields' => $fields];
    }

    /** @return list<array{action: string, fields: array}> */
    public function linesFor(string $action): array
    {
        return array_values(array_filter($this->lines, static fn (array $line) => $line['action'] === $action));
    }
}

/** The application handler: records the event and returns whatever it is told to. */
final class ChanMutGateHandler implements ClientEventHandler
{
    public static ?ClientEventResult $result = null;

    /** @var list<ClientEvent> */
    public static array $events = [];

    public function handle(ClientEvent $event): ?ClientEventResult
    {
        static::$events[] = $event;

        return static::$result;
    }
}

/** Configured as a handler while being no such thing. */
final class ChanMutNotAHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        return null;
    }
}

/** A Server assembled around the real runtime singletons, minus the two that need a socket. */
function chanMutGateServer(ChanMutGateLogger $logger): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = [
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => $logger,
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new ChanMutGateWorker(),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    return $server;
}

/** Put one connection on one channel locally, without going through the subscribe gate. */
function chanMutGateConnection(string $channel, ?array $presenceMember = null): int
{
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, "{$fd}.".random_int(1000, 999999), []);
    app(ChannelManager::class)->subscribe($fd, $channel, $presenceMember);

    return $fd;
}

function chanMutGateSend(Server $server, ChanMutGateSwoole $swoole, int $fd, ?string $channel, mixed $data = ['message' => 'hi'], int $frameBytes = 96): void
{
    driveLightspeed($server, 'handlePusherClientEvent', [$swoole, $fd, $channel, 'client-typing', $data, $frameBytes]);
}

/** The decoded `data` member of the last frame pushed. */
function chanMutGateLastData(ChanMutGateSwoole $swoole): array
{
    $frame = end($swoole->pushed);

    return json_decode($frame['data'] ?? '{}', true);
}

beforeEach(function () {
    ChanMutGateHandler::$result = null;
    ChanMutGateHandler::$events = [];

    config()->set('lightspeed.client_event_handlers', [ChanMutGateHandler::class]);

    $this->logger = new ChanMutGateLogger();
    $this->swoole = ChanMutGateSwoole::make();
    $this->server = chanMutGateServer($this->logger);
});

test('a client event that names no channel is refused as one that names no channel', function () {
    // Two shapes of "no channel": a frame without the member at all, and one
    // carrying an empty string. Both are the same mistake, and both have to be
    // refused here rather than reaching the subscription lookup, which would
    // answer a question about a channel nobody could be on and report the wrong
    // reason to the client trying to fix it.
    chanMutGateSend($this->server, $this->swoole, chanMutGateConnection('private-room'), null);

    expect($this->swoole->pushed[0]['event'])->toBe('pusher:error')
        ->and(chanMutGateLastData($this->swoole)['code'])->toBe('invalid-channel');

    $this->swoole->pushed = [];
    chanMutGateSend($this->server, $this->swoole, chanMutGateConnection('private-room'), '');

    expect(chanMutGateLastData($this->swoole)['code'])->toBe('invalid-channel')
        ->and(ChanMutGateHandler::$events)->toBe([]);
});

test('a channel that merely ends in private- or presence- is not a private channel', function () {
    // Client events are limited to channels the application authorized, and the
    // whole of that limit is where the prefix is. `room-private-` is a public
    // channel name: anyone may subscribe to it without proving anything, so
    // matching the end of the name instead of the start hands every anonymous
    // client the one surface that is supposed to require authorization.
    foreach (['room-private-', 'room-presence-'] as $channel) {
        $swoole = ChanMutGateSwoole::make();

        chanMutGateSend($this->server, $swoole, chanMutGateConnection($channel), $channel);

        expect(chanMutGateLastData($swoole)['code'])->toBe('invalid-channel')
            ->and(chanMutGateLastData($swoole)['message'])
            ->toBe('Client events are limited to private and presence channels.')
            ->and(ChanMutGateHandler::$events)->toBe([]);
    }
});

test('a refused grant is logged with the connection, the channel, the event and the reason', function () {
    // This line is the only record that a connection was told to re-authorize.
    // Without it a revocation that took effect and one that never arrived look
    // identical from the server, and the four fields are what make it possible
    // to say which socket lost which channel, on which frame, and why.
    $channel = 'private-doc.'.bin2hex(random_bytes(4));
    $fd = chanMutGateConnection($channel);

    // An expired grant, minted directly: the expiry check is local and runs
    // before anything reaches Redis, so this needs no revocation and no signing.
    $issuedAt = (int) (microtime(true) * 1_000_000);
    app(ConnectionGrants::class)->mint($fd, $channel, new Grant(['doc:1'], [], $issuedAt, $issuedAt - 1));

    chanMutGateSend($this->server, $this->swoole, $fd, $channel);

    expect($this->logger->linesFor('stale'))->toBe([[
        'action' => 'stale',
        'fields' => [
            'fd' => $fd,
            'channel' => $channel,
            'event' => 'client-typing',
            'reason' => 'expired',
        ],
    ]])
        ->and(end($this->swoole->pushed)['event'])->toBe('lightspeed:stale')
        ->and(ChanMutGateHandler::$events)->toBe([]);
});

test('the identity handed to the application is the approved one, and its id is a string', function () {
    // The presence member was recorded when the subscription passed the
    // application's own authorization, so this is an approved identity rather
    // than something the client asserted in this frame. A user id that arrives
    // from the auth response as a number reaches the handler as a string,
    // because that is what every other user id in this package is: an id that
    // changes type between connections is a comparison that silently fails.
    $channel = 'presence-room';
    $fd = chanMutGateConnection($channel, ['user_id' => 42, 'user_info' => ['name' => 'Ada']]);

    chanMutGateSend($this->server, $this->swoole, $fd, $channel);

    expect(ChanMutGateHandler::$events)->toHaveCount(1)
        ->and(ChanMutGateHandler::$events[0]->userId)->toBe('42')
        ->and(ChanMutGateHandler::$events[0]->userInfo)->toBe(['name' => 'Ada'])
        ->and(ChanMutGateHandler::$events[0]->channel)->toBe($channel);
});

test('a handler error is pushed back to the sender and the event is not broadcast', function () {
    // A handler that refuses an event has said the event may not happen. If the
    // refusal is not recognised here, the frame falls through to the fan-out
    // below and every subscriber receives the event the application declined.
    ChanMutGateHandler::$result = ClientEventResult::error('rate-limited', 'Slow down.');

    $channel = 'private-room';
    $fd = chanMutGateConnection($channel);

    chanMutGateSend($this->server, $this->swoole, $fd, $channel);

    expect($this->swoole->pushed)->toHaveCount(1)
        ->and($this->swoole->pushed[0]['event'])->toBe('pusher:error')
        ->and(chanMutGateLastData($this->swoole))->toBe([
            'code' => 'rate-limited',
            'message' => 'Slow down.',
        ]);
});

test('a handler response is returned to the sender alone', function () {
    ChanMutGateHandler::$result = ClientEventResult::response(requestId: 'req-1', response: ['ok' => true]);

    $channel = 'private-room';
    $fd = chanMutGateConnection($channel);

    chanMutGateSend($this->server, $this->swoole, $fd, $channel);

    expect($this->swoole->pushed)->toHaveCount(1)
        ->and($this->swoole->pushed[0]['event'])->toBe('lightspeed:response');
});

test('a class configured as a handler that is not one is refused rather than called', function () {
    // The list is a list of class names in a config file, so the thing it names
    // is whatever the application put there. Resolved and used without the
    // check, the first client event on the server dies inside the dispatcher
    // with an undefined method, on the frame rather than at boot, and the
    // client is told only that its event could not be handled.
    config()->set('lightspeed.client_event_handlers', [ChanMutNotAHandler::class]);

    $event = new ClientEvent(
        fd: 1,
        channel: 'private-room',
        event: 'client-typing',
        data: [],
        socketId: '1.1',
    );

    expect(fn () => app(ClientEventDispatcher::class)->dispatch($event))
        ->toThrow(RuntimeException::class, 'is invalid');
});

/**
 * The size limit, both sides of it.
 *
 * A client event is the only frame that carries an application payload UP this
 * socket, and it is the payload the server does the most with: it crosses into
 * the booted application, and an event no handler answers is fanned back out to
 * every other subscriber on the channel. Nothing bounded it, so one authorized
 * connection could hand every peer on a channel a megabyte, once per frame.
 *
 * Both sides are asserted deliberately. A limit tested only from above is
 * satisfied by a limit of anything smaller, including one that refuses the
 * ordinary traffic this package exists to carry.
 */
test('a client event larger than the limit is refused, and never reaches the application', function () {
    $channel = 'private-room';
    $fd = chanMutGateConnection($channel);

    chanMutGateSend(
        $this->server,
        $this->swoole,
        $fd,
        $channel,
        frameBytes: ClientEventLimits::maxFrameBytes() + 1,
    );

    expect($this->swoole->pushed)->toHaveCount(1)
        ->and($this->swoole->pushed[0]['event'])->toBe('pusher:error')
        ->and(chanMutGateLastData($this->swoole))->toBe([
            'code' => 'client-event-too-large',
            'message' => 'Client events are limited to 10240 bytes.',
        ])
        // The two ways an unbounded frame does damage: the application ran it,
        // or every other subscriber was handed it.
        ->and(ChanMutGateHandler::$events)->toBe([]);
});

test('a client event exactly at the limit is carried, because a refusal that catches ordinary traffic is a broken server', function () {
    $channel = 'private-room';
    $fd = chanMutGateConnection($channel);

    chanMutGateSend(
        $this->server,
        $this->swoole,
        $fd,
        $channel,
        frameBytes: ClientEventLimits::maxFrameBytes(),
    );

    expect(ChanMutGateHandler::$events)->toHaveCount(1)
        ->and($this->swoole->pushed)->toBe([]);
});

test('the refusal is logged with the connection, the size and the limit, and with neither string the frame carried', function () {
    // The line an operator reads to tell a client that is too chatty from a
    // limit that is set too low. The channel and the event name are absent on
    // purpose: both arrived in this frame, neither has been validated yet, and
    // an oversized frame is precisely the one whose unvalidated strings should
    // not be written to the server's disk once per attempt.
    $channel = 'private-room';
    $fd = chanMutGateConnection($channel);

    chanMutGateSend(
        $this->server,
        $this->swoole,
        $fd,
        $channel,
        frameBytes: 20000,
    );

    expect($this->logger->linesFor('too-large'))->toBe([[
        'action' => 'too-large',
        'fields' => [
            'fd' => $fd,
            'bytes' => 20000,
            'limit' => 10240,
        ],
    ]]);
});

test('the limit is the configured one, and a limit configured to nothing is the default rather than an outage', function () {
    // Two claims. The dial is real, so an application that needs bigger events
    // can have them; and a limit nobody can have meant does not silently turn
    // the package's headline feature off for every frame. `=0` or `=` in an env
    // file (the empty string, which casts to 0) used to be answered with a floor
    // of ONE BYTE, and the smallest real client-event frame is around forty, so
    // that floor refused every client event on the server. A typo gets you the
    // shipped default.
    config()->set('lightspeed.client_events.max_frame_bytes', 64);

    $channel = 'private-room';
    chanMutGateSend($this->server, $this->swoole, chanMutGateConnection($channel), $channel, frameBytes: 65);

    expect(chanMutGateLastData($this->swoole))->toBe([
        'code' => 'client-event-too-large',
        'message' => 'Client events are limited to 64 bytes.',
    ]);

    foreach ([0, -1, ''] as $unusable) {
        config()->set('lightspeed.client_events.max_frame_bytes', $unusable);

        expect(ClientEventLimits::maxFrameBytes())->toBe(10240);
    }

    // And the line between the two behaviours is "is this a usable size", not
    // "is this a sensible one". A literal 1 is absurd and is still obeyed,
    // because someone typed it; what is replaced is only the value nobody can
    // have meant. Substituting the default for small-but-explicit numbers would
    // make the dial silently stop working somewhere nothing documents.
    config()->set('lightspeed.client_events.max_frame_bytes', 1);

    expect(ClientEventLimits::maxFrameBytes())->toBe(1);
});

test('a limit that is not a number at all falls back to the default instead of throwing', function () {
    // Config values come from the environment, where everything is a string and
    // nothing is validated. Without the int cast the comparison is a string
    // comparison, which hands a non-numeric string back out of a method declared
    // to return int, and the client-event path dies with a TypeError on a frame
    // a client sent. What it falls back TO is the shipped default: 'unlimited'
    // is a misconfiguration, and the answer to one is the ordinary server.
    config()->set('lightspeed.client_events.max_frame_bytes', 'unlimited');

    expect(ClientEventLimits::maxFrameBytes())->toBe(10240);
});

test('with the config key absent entirely, the built-in default answers', function () {
    // The last line of defence, and not a hypothetical: an application that
    // published config/lightspeed.php before this key existed has exactly this
    // file, and its client events have to keep working at Pusher's limit rather
    // than at zero.
    config()->set('lightspeed.client_events', null);

    expect(ClientEventLimits::maxFrameBytes())->toBe(10240)
        ->and(ClientEventLimits::maxFrameBytes())->toBe(ClientEventLimits::MAX_FRAME_BYTES);
});
