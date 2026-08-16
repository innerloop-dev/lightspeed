<?php

use Illuminate\Http\Request;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\GrantManager;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Broadcasting\LightspeedBroadcaster;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\ClientEvents\ClientEvent;
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
 * The claim this file exists to defend: THE SERVER SERVES.
 *
 * Ten rounds of review went at authorization, and authorization is genuinely
 * well tested, grants, revocation, expiry, signatures, per-message re-checks.
 * Nothing tested the other half: that a connection which is allowed to be here
 * actually receives the frames the Pusher protocol says it receives.
 *
 * The gap was measured rather than suspected. Eight features were deleted from
 * the shipping source one at a time, and the whole 308-test suite stayed green
 * for every one of them:
 *
 *   pusher:connection_established never sent          308 pass
 *   local broadcast fan-out removed                   308 pass
 *   pusher:pong never answered                        308 pass
 *   pusher_internal:member_added never broadcast      308 pass
 *   member_removed on unsubscribe/revoke/expiry       308 pass
 *   member_removed on close                           308 pass
 *   client events allowed on PUBLIC channels          308 pass
 *   channel-auth HMAC compared with ==                308 pass
 *
 * The first two are total failures of the product. A client that never gets
 * `pusher:connection_established` sits in "connecting" forever and never
 * subscribes to anything; and with `worker_num` defaulting to 1, no local
 * fan-out means no broadcast reaches anyone at all. The seventh is a security
 * hole: client events on a public channel are unauthenticated writes fanned
 * out to every listener.
 *
 * Protocol\FramesTest already pins the SHAPE of every envelope, and that is why
 * the hole was invisible: a builder that is correct and never called is
 * indistinguishable, from a unit test's point of view, from a working server.
 * So nothing below asserts a frame's shape. Every test here asserts that a
 * frame ARRIVED, at a particular file descriptor, because a client did
 * something a client does.
 *
 * WHAT IS REAL HERE. The channel manager, the presence store (real Redis), the
 * relay's local delivery path, the real broadcaster signing real auth strings,
 * the real signature check, the real grant lifecycle. Two things are
 * substituted, both for the same reason, they are the only two that need a
 * listening socket:
 *
 *   the Swoole server   a recorder, which is what makes "arrived at fd 7"
 *                       observable at all. It records PER FD, unlike the
 *                       recorder in PerMessageAuthorizationTest, because every
 *                       question below is about which connection got told.
 *   the Octane worker   runs the client-event handler in the current container
 *                       instead of marshalling it into a booted application.
 *
 * The relay is pointed at the recorder and its Redis half switched off, so
 * `deliverLocal()`. The path every single-worker deployment depends on
 * entirely, is exercised for real rather than stood in for.
 */

/**
 * Records what was pushed, and to whom.
 *
 * `pushed` is a flat list in wire order so that "who was told first" stays
 * answerable, and delivered() filters it. Everything else is the minimum a
 * live Swoole websocket server has to answer for these paths to run.
 */
class DeliveryRecordingServer extends SwooleServer
{
    /** @var list<array{fd: int, frame: array}> decoded frames, in push order */
    public array $pushed = [];

    /** @var list<array{fd: int, code: int, reason: string}> */
    public array $disconnected = [];

    /** @var list<int> */
    public array $closed = [];

    /** Built without Swoole\Server's constructor, which would bind a port. */
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
        $this->pushed[] = ['fd' => $fd, 'frame' => json_decode((string) $data, true)];

        return true;
    }

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        $this->disconnected[] = ['fd' => $fd, 'code' => $code, 'reason' => $reason];

        return true;
    }

    public function close(int $fd, bool $reset = false): bool
    {
        $this->closed[] = $fd;

        return true;
    }
}

/** Runs the client-event task inline, in the container the test is already in. */
class DeliveryInlineWorker extends OctaneWorker
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

/**
 * Counts client events that reached the application.
 *
 * Returns null so the event goes on to be broadcast, which is the default
 * client-event behaviour and the thing a public-channel leak would fan out.
 */
class DeliverySpyHandler implements ClientEventHandler
{
    /** @var list<array{channel: string, event: string}> */
    public static array $seen = [];

    public function handle(ClientEvent $event): ?ClientEventResult
    {
        static::$seen[] = ['channel' => $event->channel, 'event' => $event->event];

        return null;
    }
}

/**
 * Assemble a Server whose local delivery path really delivers.
 *
 * The relay is the piece that matters. Attaching the recorder to it (and
 * leaving its Redis half disabled) means `BroadcastBridge::fanOut()` runs
 * `RedisRelay::deliverLocal()` runs `ChannelManager::broadcast()` runs
 * `$server->push()`. The whole chain a single-worker server has, with nothing
 * stubbed in the middle of it.
 */
function deliveryServer(DeliveryRecordingServer $swoole, array $overrides = []): Server
{
    config()->set('lightspeed.relay.enabled', false);

    $relay = app(RedisRelay::class);
    $attachRelay = new ReflectionProperty(RedisRelay::class, 'server');
    $attachRelay->setAccessible(true);
    $attachRelay->setValue($relay, $swoole);

    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = $overrides + [
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => $relay,
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new DeliveryInlineWorker(),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    // Builds the websocket surfaces out of the collaborators just injected,
    // exactly as the constructor does after building the Octane worker.
    driveDelivery($server, 'compose', []);

    // These tests drive the callbacks directly, so nothing has run the
    // workerStart that a real process would. Say so, or every handshake here is
    // refused by the readiness gate rather than judged on its merits.
    markLightspeedWorkerReady($server);

    // Registers the revocation listeners AND records the attached server, which
    // is what the revocation and expiry sweeps push their `lightspeed:stale`
    // frames through.
    driveDelivery($server, 'bootRevocationListeners', [$swoole]);

    return $server;
}

/** Call one of the server's private methods, wherever it lives. */
function driveDelivery(Server $server, string $method, array $arguments): mixed
{
    return driveLightspeed($server, $method, $arguments);
}

/** A Swoole HTTP upgrade request, as the `open` handler receives one. */
function deliveryRequest(int $fd, ?string $path = null, string $remoteAddress = '127.0.0.1'): \Swoole\Http\Request
{
    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = $fd;
    $request->header = ['host' => 'localhost'];
    $request->cookie = [];
    $request->server = [
        'request_uri' => $path ?? '/app/'.config('lightspeed.reverb_compat.app_key'),
        'remote_addr' => $remoteAddress,
    ];

    return $request;
}

/** Open a real Pusher connection through the `open` handler. Returns the fd. */
function deliveryOpen(Server $server, DeliveryRecordingServer $swoole, ?int $fd = null): int
{
    $fd = $fd ?? random_int(1000, 999999);

    driveDelivery($server, 'handleOpen', [$swoole, deliveryRequest($fd)]);

    return $fd;
}

/** Every frame pushed to one fd, in order. */
function deliveredTo(DeliveryRecordingServer $swoole, int $fd): array
{
    return array_values(array_map(
        static fn (array $entry) => $entry['frame'],
        array_filter($swoole->pushed, static fn (array $entry) => $entry['fd'] === $fd),
    ));
}

/** Every event name pushed to one fd, in order. */
function deliveredEventsTo(DeliveryRecordingServer $swoole, int $fd): array
{
    return array_map(static fn (array $frame) => $frame['event'] ?? $frame['type'] ?? null, deliveredTo($swoole, $fd));
}

/** The decoded `data` member of the first frame of the given event at one fd. */
function deliveredData(DeliveryRecordingServer $swoole, int $fd, string $event): ?array
{
    foreach (deliveredTo($swoole, $fd) as $frame) {
        if (($frame['event'] ?? null) === $event) {
            return json_decode($frame['data'] ?? '{}', true);
        }
    }

    return null;
}

/**
 * A channel-auth response signed by the REAL broadcaster.
 *
 * Returns the whole response, because a presence subscribe needs
 * `channel_data` as well as `auth` and the two have to be the pair the
 * signature was computed over.
 *
 * A presence member is supplied the way a real request supplies one: the
 * `user_id` comes from the AUTHENTICATED USER, because that is where
 * PusherBroadcaster reads it from, and only the `user_info` is the value the
 * application's channel callback returned. Passing the whole member array as
 * the callback result would sign a channel_data this package would never issue.
 *
 * @return array{auth: string, channel_data?: string}
 */
function deliveryAuth(string $socketId, string $channel, ?array $presenceMember = null, ?array $tags = null): array
{
    $broadcaster = new LightspeedBroadcaster(
        app(BroadcastBridge::class),
        app(PendingGrants::class),
        app(RevocationLog::class),
        new \Pusher\Pusher(
            config('lightspeed.reverb_compat.app_key'),
            config('lightspeed.reverb_compat.app_secret'),
            config('lightspeed.reverb_compat.app_id'),
        ),
    );

    $request = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => $socketId,
        'channel_name' => $channel,
    ]);

    if ($presenceMember !== null) {
        $request->setUserResolver(static fn () => new \Illuminate\Auth\GenericUser([
            'id' => $presenceMember['user_id'],
        ]));
    }

    app(PendingGrants::class)->begin();

    if ($tags !== null) {
        app(GrantManager::class)->tag($tags)->with([]);
    }

    return $broadcaster->validAuthenticationResponse(
        $request,
        $presenceMember === null ? true : ($presenceMember['user_info'] ?? []),
    );
}

/**
 * Open a connection and subscribe it, through the real frame handlers.
 *
 * @return array{0: int, 1: string} the fd and the socket id the SERVER minted
 */
function deliverySubscribe(
    Server $server,
    DeliveryRecordingServer $swoole,
    string $channel,
    ?array $presenceMember = null,
    ?array $tags = null,
): array {
    $fd = deliveryOpen($server, $swoole);
    $socketId = app(ChannelManager::class)->socketIdFor($fd);

    $payload = ['channel' => $channel];

    if (\Lightspeed\Protocol\SubscriptionAuthorizer::isProtectedChannel($channel)) {
        $response = deliveryAuth($socketId, $channel, $presenceMember, $tags);
        $payload['auth'] = $response['auth'];

        if (isset($response['channel_data'])) {
            $payload['channel_data'] = $response['channel_data'];
        }
    }

    driveDelivery($server, 'handlePusherSubscribe', [$swoole, $fd, $payload]);

    return [$fd, $socketId];
}

function deliveryPublicChannel(): string
{
    return 'lightspeed-delivery.'.bin2hex(random_bytes(6));
}

function deliveryPrivateChannel(): string
{
    return 'private-lightspeed-delivery.'.bin2hex(random_bytes(6));
}

function deliveryPresenceChannel(): string
{
    return 'presence-lightspeed-delivery.'.bin2hex(random_bytes(6));
}

function deliveryTag(): string
{
    return 'delivery:'.bin2hex(random_bytes(8));
}

beforeEach(function () {
    DeliverySpyHandler::$seen = [];

    config()->set('lightspeed.client_event_handlers', [DeliverySpyHandler::class]);

    $this->swoole = DeliveryRecordingServer::make();
    $this->server = deliveryServer($this->swoole);
});

afterEach(function () {
    // Presence rows are reference counts with a TTL measured in minutes, and
    // every presence test here joins a channel nobody leaves. Named after the
    // channels this file mints so nothing else's state can be caught by it.
    $redis = \Illuminate\Support\Facades\Redis::connection();
    $prefix = (string) config('database.redis.options.prefix');

    foreach ((array) $redis->keys('*lightspeed:presence:channel:presence-lightspeed-delivery.*') as $key) {
        $redis->del($prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
    }
});

// ---------------------------------------------------------------------------
// The handshake
// ---------------------------------------------------------------------------

/**
 * D1. Without this frame there is no client.
 *
 * pusher-js stays in `connecting` until `pusher:connection_established`
 * arrives, and a channel subscription is not even attempted from that state. So
 * deleting one `push()` in the open handler turns the entire product off, for
 * every client, and the suite could not tell.
 */
test('a connection that opens is told it is established, and given its socket id', function () {
    $fd = deliveryOpen($this->server, $this->swoole);

    expect(deliveredEventsTo($this->swoole, $fd))->toBe(['pusher:connection_established']);

    $data = deliveredData($this->swoole, $fd, 'pusher:connection_established');

    expect($data['socket_id'] ?? null)->toBeString()
        ->and($data['socket_id'])->toMatch('/^'.$fd.'\.\d+$/')
        ->and($data['activity_timeout'] ?? null)->toBeInt()
        ->and($data['activity_timeout'])->toBeGreaterThan(0);
});

/**
 * D2. The id in that frame has to be the id the server will judge signatures
 * against, or every channel-auth response an application signs is refused.
 *
 * This is the half a shape test cannot reach: Frames::connectionEstablished()
 * is a pure function of whatever it is handed, so it is equally correct when
 * handed the wrong socket id.
 */
test('the socket id in the handshake frame is the one that authorizes a subscription', function () {
    $channel = deliveryPrivateChannel();
    $fd = deliveryOpen($this->server, $this->swoole);

    $announced = deliveredData($this->swoole, $fd, 'pusher:connection_established')['socket_id'];

    // Signed for the id the CLIENT was told, which is the only one it has.
    driveDelivery($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => deliveryAuth($announced, $channel)['auth'],
    ]]);

    expect(deliveredEventsTo($this->swoole, $fd))
        ->toBe(['pusher:connection_established', 'pusher_internal:subscription_succeeded'])
        ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
});

test('a connection opened with the wrong app key is told why and disconnected', function () {
    $fd = random_int(1000, 999999);

    driveDelivery($this->server, 'handleOpen', [
        $this->swoole,
        deliveryRequest($fd, '/app/not-the-configured-key'),
    ]);

    expect(deliveredEventsTo($this->swoole, $fd))->toBe(['pusher:error'])
        ->and(deliveredData($this->swoole, $fd, 'pusher:error')['code'])->toBe('invalid-app-key')
        ->and($this->swoole->disconnected)->toHaveCount(1)
        ->and($this->swoole->disconnected[0]['code'])->toBe(4001)
        ->and(app(ChannelManager::class)->socketIdFor($fd))->toBeNull();
});

/**
 * D3. The diagnostic protocol carries no channel authorization at all, so the
 * default answer to any non-Pusher path is a refusal. And the refusal has to
 * be a PUSHER-shaped error, because a `{"type": ...}` frame is dropped in
 * silence by every Pusher client.
 */
test('a websocket opened on an unknown path is refused, never greeted', function () {
    $fd = random_int(1000, 999999);

    driveDelivery($this->server, 'handleOpen', [$this->swoole, deliveryRequest($fd, '/diagnostics')]);

    expect(deliveredEventsTo($this->swoole, $fd))->toBe(['pusher:error'])
        ->and(deliveredData($this->swoole, $fd, 'pusher:error')['code'])->toBe('invalid-path')
        ->and($this->swoole->disconnected[0]['code'])->toBe(4004);

    // The greeting advertises the unauthorized verbs; it must never be on the
    // wire for a connection that was refused.
    foreach (deliveredTo($this->swoole, $fd) as $frame) {
        expect($frame['type'] ?? null)->not->toBe('hello');
    }
});

// ---------------------------------------------------------------------------
// Keeping the connection alive
// ---------------------------------------------------------------------------

/**
 * D4. pusher-js sends `pusher:ping` when the connection has been quiet for the
 * activity timeout, and drops the connection if no pong comes back within
 * `pong_timeout`. An unanswered ping is therefore not a missing nicety: it is a
 * client that disconnects and reconnects on a loop forever, on an idle app.
 */
test('a client ping is answered with a pong', function () {
    $fd = deliveryOpen($this->server, $this->swoole);

    driveDelivery($this->server, 'handlePusherMessage', [$this->swoole, $fd, ['event' => 'pusher:ping'], 32]);

    expect(deliveredEventsTo($this->swoole, $fd))
        ->toBe(['pusher:connection_established', 'pusher:pong']);
});

/**
 * D5. The other direction. The server may ping a quiet client, and the client
 * answers with `pusher:pong`. Which must be ACCEPTED and not answered.
 *
 * Deleting the arm that swallows it does not produce silence, it produces
 * `unsupported-event`, and a client that gets a connection-level error for
 * obeying the protocol is a client that reports a broken connection.
 */
test('a client pong is accepted without an error and without a reply', function () {
    $fd = deliveryOpen($this->server, $this->swoole);

    driveDelivery($this->server, 'handlePusherMessage', [$this->swoole, $fd, ['event' => 'pusher:pong'], 32]);

    expect(deliveredEventsTo($this->swoole, $fd))->toBe(['pusher:connection_established']);
});

test('an event the protocol does not define is refused by name', function () {
    $fd = deliveryOpen($this->server, $this->swoole);

    driveDelivery($this->server, 'handlePusherMessage', [$this->swoole, $fd, ['event' => 'pusher:teleport'], 36]);

    expect(deliveredData($this->swoole, $fd, 'pusher:error')['code'])->toBe('unsupported-event');
});

/**
 * D6. A Pusher connection must never be able to reach the diagnostic verbs by
 * changing the SHAPE of its frames mid-stream. The refusal is Pusher-shaped for
 * the same reason as D3.
 */
test('a Pusher connection sending a diagnostic frame gets a Pusher error, not the diagnostic protocol', function () {
    $fd = deliveryOpen($this->server, $this->swoole);

    $frame = (new ReflectionClass(\Swoole\WebSocket\Frame::class))->newInstanceWithoutConstructor();
    $frame->fd = $fd;
    $frame->opcode = WEBSOCKET_OPCODE_TEXT;
    $frame->data = json_encode(['type' => 'subscribe', 'channel' => 'private-anything']);

    driveDelivery($this->server, 'receiveFrame', [$this->swoole, $frame]);

    expect(deliveredEventsTo($this->swoole, $fd))->toBe(['pusher:connection_established', 'pusher:error'])
        ->and(deliveredData($this->swoole, $fd, 'pusher:error')['code'])->toBe('invalid-message');
});

// ---------------------------------------------------------------------------
// Subscribing
// ---------------------------------------------------------------------------

test('a public channel subscription succeeds with no auth string at all', function () {
    $channel = deliveryPublicChannel();
    $fd = deliveryOpen($this->server, $this->swoole);

    driveDelivery($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, ['channel' => $channel]]);

    expect(deliveredEventsTo($this->swoole, $fd))
        ->toBe(['pusher:connection_established', 'pusher_internal:subscription_succeeded'])
        ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
});

/**
 * D7. A refused subscription must arrive as `pusher_internal:subscription_error`
 * ON THE CHANNEL, not as a connection-level `pusher:error`. pusher-js routes the
 * latter to the connection, so the channel stays pending forever and an Echo
 * `.error()` callback never runs: the app sees a channel that is neither
 * subscribed nor failed.
 */
test('a refused subscription is reported on the channel, not on the connection', function () {
    $channel = deliveryPrivateChannel();
    $fd = deliveryOpen($this->server, $this->swoole);

    driveDelivery($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => 'test-key:'.str_repeat('a', 64),
    ]]);

    $frames = deliveredTo($this->swoole, $fd);
    $last = end($frames);

    expect($last['event'])->toBe('pusher_internal:subscription_error')
        ->and($last['channel'])->toBe($channel)
        ->and(json_decode($last['data'], true)['status'])->toBe(401)
        ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeFalse();
});

/**
 * D8. `subscription_succeeded` on a presence channel carries the roster, and it
 * is the ONLY time a client is told who is already there, every later change
 * arrives as a member_added/member_removed delta. A snapshot that is empty or
 * absent leaves a client that renders nobody and never recovers.
 */
test('a presence subscription is answered with the current roster', function () {
    $channel = deliveryPresenceChannel();

    deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    [$second] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'grace', 'user_info' => ['name' => 'Grace']]);

    $snapshot = deliveredData($this->swoole, $second, 'pusher_internal:subscription_succeeded')['presence'];

    expect($snapshot['count'])->toBe(2)
        ->and($snapshot['ids'])->toContain('ada')
        ->and($snapshot['ids'])->toContain('grace');
});

// ---------------------------------------------------------------------------
// Fan-out: the reason the package exists
// ---------------------------------------------------------------------------

/**
 * D9. `worker_num` DEFAULTS TO 1. On a default install, local delivery is not
 * an optimization ahead of the Redis relay, it is the only delivery there is.
 * Removing it broadcasts nothing to anyone, and 308 tests passed.
 */
test('a broadcast reaches a subscribed socket', function () {
    $channel = deliveryPublicChannel();

    [$listener] = deliverySubscribe($this->server, $this->swoole, $channel);

    app(BroadcastBridge::class)->broadcast([$channel], 'OrderShipped', ['order' => 7]);

    $frames = deliveredTo($this->swoole, $listener);
    $last = end($frames);

    expect($last['event'])->toBe('OrderShipped')
        ->and($last['channel'])->toBe($channel)
        ->and(json_decode($last['data'], true))->toBe(['order' => 7]);
});

test('a broadcast reaches every subscriber of the channel and nobody else', function () {
    $channel = deliveryPublicChannel();
    $otherChannel = deliveryPublicChannel();

    [$first] = deliverySubscribe($this->server, $this->swoole, $channel);
    [$second] = deliverySubscribe($this->server, $this->swoole, $channel);
    [$bystander] = deliverySubscribe($this->server, $this->swoole, $otherChannel);

    $delivered = app(BroadcastBridge::class)->fanOut([$channel], 'OrderShipped', ['order' => 7]);

    expect($delivered)->toBe(2)
        ->and(deliveredEventsTo($this->swoole, $first))->toContain('OrderShipped')
        ->and(deliveredEventsTo($this->swoole, $second))->toContain('OrderShipped')
        ->and(deliveredEventsTo($this->swoole, $bystander))->not->toContain('OrderShipped');
});

/**
 * D10. `toOthers()` is expressed entirely as a socket id on the way out, so a
 * fan-out that ignored it would echo every optimistic UI update back to the
 * client that just made it.
 */
test('a broadcast excluding a socket id skips exactly that socket', function () {
    $channel = deliveryPublicChannel();

    [$sender, $senderSocketId] = deliverySubscribe($this->server, $this->swoole, $channel);
    [$other] = deliverySubscribe($this->server, $this->swoole, $channel);

    $delivered = app(BroadcastBridge::class)->fanOut([$channel], 'OrderShipped', ['order' => 7], $senderSocketId);

    expect($delivered)->toBe(1)
        ->and(deliveredEventsTo($this->swoole, $sender))->not->toContain('OrderShipped')
        ->and(deliveredEventsTo($this->swoole, $other))->toContain('OrderShipped');
});

// ---------------------------------------------------------------------------
// Presence membership, in every direction it can change
// ---------------------------------------------------------------------------

/**
 * D11. Joining. A member who arrives and is never announced is a collaborator
 * every other client cannot see until it reloads the page.
 */
test('a member joining a presence channel is announced to the members already there', function () {
    $channel = deliveryPresenceChannel();

    [$first] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    [$joiner] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'grace', 'user_info' => ['name' => 'Grace']]);

    expect(deliveredEventsTo($this->swoole, $first))->toContain('pusher_internal:member_added');

    $added = deliveredData($this->swoole, $first, 'pusher_internal:member_added');

    expect($added['user_id'])->toBe('grace');

    // The joiner learns about itself from the roster in subscription_succeeded,
    // so a member_added for its own arrival would double-count it.
    expect(deliveredEventsTo($this->swoole, $joiner))->not->toContain('pusher_internal:member_added');
});

/**
 * D12. Leaving on purpose.
 */
test('a member that unsubscribes is announced as removed to the members that remain', function () {
    $channel = deliveryPresenceChannel();

    [$stayer] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'ada', 'user_info' => []]);
    [$leaver] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'grace', 'user_info' => []]);

    driveDelivery($this->server, 'handlePusherUnsubscribe', [$this->swoole, $leaver, ['channel' => $channel]]);

    expect(deliveredEventsTo($this->swoole, $stayer))->toContain('pusher_internal:member_removed')
        ->and(deliveredData($this->swoole, $stayer, 'pusher_internal:member_removed')['user_id'])->toBe('grace')
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada']);
});

/**
 * D13. Leaving because the socket died, which is how nearly every real
 * departure happens: a closed tab, a dropped network, a killed app.
 */
test('a member whose connection closes is announced as removed', function () {
    $channel = deliveryPresenceChannel();

    [$stayer] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'ada', 'user_info' => []]);
    [$goner] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'grace', 'user_info' => []]);

    driveDelivery($this->server, 'handleClose', [$this->swoole, $goner]);

    expect(deliveredEventsTo($this->swoole, $stayer))->toContain('pusher_internal:member_removed')
        ->and(deliveredData($this->swoole, $stayer, 'pusher_internal:member_removed')['user_id'])->toBe('grace')
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada']);
});

/**
 * D14. Leaving because access was taken away.
 *
 * Revocation is thoroughly tested as authorization. The revoked connection
 * stops sending and stops receiving. What was not tested is that the CHANNEL is
 * told, and it is the same omission every time: the revoked user vanishes from
 * the roster on the server while every other client keeps painting them.
 */
test('a member dropped by a revocation is announced as removed, and told to re-authorize', function () {
    $channel = deliveryPresenceChannel();
    $tag = deliveryTag();

    [$stayer] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'ada', 'user_info' => []]);
    [$revoked] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'grace', 'user_info' => []], [$tag]);

    expect(app(ConnectionGrants::class)->grantFor($revoked, $channel))->not->toBeNull();

    app(RevocationLog::class)->revoke($tag);

    expect(deliveredEventsTo($this->swoole, $stayer))->toContain('pusher_internal:member_removed')
        ->and(deliveredData($this->swoole, $stayer, 'pusher_internal:member_removed')['user_id'])->toBe('grace')
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada']);

    // And the dropped client is told, or it sits in a channel it is no longer
    // in and never re-subscribes.
    expect(deliveredEventsTo($this->swoole, $revoked))->toContain('lightspeed:stale')
        ->and(deliveredData($this->swoole, $revoked, 'lightspeed:stale')['reason'])->toBe('revoked');
});

/**
 * D15. Leaving because the grant simply ran out, which is the case a silent
 * client reaches without doing anything at all.
 */
test('a member swept out by grant expiry is announced as removed, and told to re-authorize', function () {
    $channel = deliveryPresenceChannel();

    [$stayer] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'ada', 'user_info' => []]);
    [$expiring] = deliverySubscribe($this->server, $this->swoole, $channel, ['user_id' => 'grace', 'user_info' => []], [deliveryTag()]);

    // Replace the grant with one that has already run out. The sweeper's job is
    // to notice this for a connection that never sends another frame.
    $past = (int) (microtime(true) * 1_000_000) - 60_000_000;
    app(ConnectionGrants::class)->mint($expiring, $channel, new Grant([deliveryTag()], [], $past, $past + 1));

    expect(driveDelivery($this->server, 'sweepExpiredGrants', [null]))->toBe(1);

    expect(deliveredEventsTo($this->swoole, $stayer))->toContain('pusher_internal:member_removed')
        ->and(deliveredData($this->swoole, $stayer, 'pusher_internal:member_removed')['user_id'])->toBe('grace')
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada'])
        ->and(deliveredEventsTo($this->swoole, $expiring))->toContain('lightspeed:stale')
        ->and(deliveredData($this->swoole, $expiring, 'lightspeed:stale')['reason'])->toBe('expired');
});

// ---------------------------------------------------------------------------
// Client events
// ---------------------------------------------------------------------------

/**
 * D16. THE SECURITY ONE.
 *
 * A public channel needs no auth string, so anybody who can open a socket can
 * subscribe to one. Allowing client events there would let an anonymous
 * stranger publish arbitrary events to every listener of a channel the
 * application believes is read-only. The Pusher protocol restricts client
 * events to private and presence channels for exactly this reason.
 *
 * Deleting the check passed 308 tests.
 */
test('a client event on a public channel is refused, and never reaches the application', function () {
    $channel = deliveryPublicChannel();

    [$sender] = deliverySubscribe($this->server, $this->swoole, $channel);
    [$listener] = deliverySubscribe($this->server, $this->swoole, $channel);

    driveDelivery($this->server, 'handlePusherClientEvent', [
        $this->swoole, $sender, $channel, 'client-typing', ['message' => 'hello'], 96,
    ]);

    // The two ways the leak shows, asserted first because they are the damage:
    // the application ran an unauthenticated stranger's event, or it was fanned
    // out to every other listener of a channel anyone can join.
    expect(DeliverySpyHandler::$seen)->toBe([])
        ->and(deliveredEventsTo($this->swoole, $listener))->not->toContain('client-typing')
        ->and(deliveredEventsTo($this->swoole, $sender))->toContain('pusher:error')
        ->and(deliveredData($this->swoole, $sender, 'pusher:error')['code'] ?? null)->toBe('invalid-channel');
});

test('a client event on a channel the connection never joined is refused', function () {
    $channel = deliveryPrivateChannel();

    $fd = deliveryOpen($this->server, $this->swoole);

    driveDelivery($this->server, 'handlePusherClientEvent', [
        $this->swoole, $fd, $channel, 'client-typing', ['message' => 'hello'], 96,
    ]);

    expect(deliveredData($this->swoole, $fd, 'pusher:error')['code'])->toBe('not-subscribed')
        ->and(DeliverySpyHandler::$seen)->toBe([]);
});

/**
 * D17. And the permitted case, so that none of the above is satisfied by a
 * server that simply refuses everything. A client event on a private channel
 * reaches the application AND is fanned out to the channel's other
 * subscribers, without being echoed back to its sender.
 */
test('a client event on a private channel reaches the application and the other subscribers', function () {
    $channel = deliveryPrivateChannel();

    [$sender] = deliverySubscribe($this->server, $this->swoole, $channel);
    [$listener] = deliverySubscribe($this->server, $this->swoole, $channel);

    driveDelivery($this->server, 'handlePusherClientEvent', [
        $this->swoole, $sender, $channel, 'client-typing', ['message' => 'hello'], 96,
    ]);

    expect(DeliverySpyHandler::$seen)->toBe([['channel' => $channel, 'event' => 'client-typing']])
        ->and(deliveredEventsTo($this->swoole, $listener))->toContain('client-typing')
        ->and(deliveredData($this->swoole, $listener, 'client-typing'))->toBe(['message' => 'hello'])
        ->and(deliveredEventsTo($this->swoole, $sender))->not->toContain('client-typing');
});
