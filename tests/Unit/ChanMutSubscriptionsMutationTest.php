<?php

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\GrantManager;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Channels\ChannelName;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\SubscriptionAuthorizer;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * What a subscribe records, tells, and asks for, on the paths that produce no
 * subscription.
 *
 * Three of the four outcomes of a subscribe are refusals, and a refusal leaves
 * nothing behind except a frame and a log line. That log line is the entire
 * record: nothing else in the process writes one, the client is told only that
 * authorization failed, and an operator holding "subscriptions are failing"
 * has this and nothing else to work from. So which connection, which channel
 * and which of the reasons are asserted here field by field.
 *
 * The presence half is the other subject. A join answers with the member the
 * shared store holds, which is not the member the client sent (the store
 * assigns things, a colour among them), and a leave is only announced when the
 * user really left rather than when one of their tabs did. Both are decisions
 * about what other people on the channel are told, and neither is visible in
 * the frame the leaving connection receives.
 */

/** Records what the server pushed, in place of a real websocket. */
final class ChanMutSubSwoole extends SwooleServer
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

/** Keeps the websocket runtime lines instead of writing them out. */
final class ChanMutSubLogger extends RuntimeLogger
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

/** Records fan-out instead of performing it. */
final class ChanMutSubBridge extends BroadcastBridge
{
    /** @var list<array{channels: array, event: string, payload: mixed}> */
    public array $fanOuts = [];

    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        $this->fanOuts[] = compact('channels', 'event', 'payload');

        return 1;
    }

    /** @return list<array{channels: array, event: string, payload: mixed}> */
    public function eventsNamed(string $event): array
    {
        return array_values(array_filter($this->fanOuts, static fn (array $call) => $call['event'] === $event));
    }
}

/** Records the owner claims a subscribe asks for. */
final class ChanMutSubRouter extends ResourceRouter
{
    /** @var list<array{channel: string, reason: string}> */
    public array $claims = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function claimOwnerForChannel(string $channel, string $reason = 'channel-subscribe'): ?array
    {
        $this->claims[] = compact('channel', 'reason');

        return null;
    }
}

/**
 * The real presence store, with every call recorded.
 *
 * `$silent` makes join() and leave() answer without their broadcast flags,
 * which is the answer a store that could not decide would give. It is here
 * because "the store did not say to announce this" and "the store said not to"
 * have to reach the channel the same way: as nothing.
 */
final class ChanMutSubPresence extends PresenceStore
{
    /** @var list<string> */
    public array $calls = [];

    public bool $silent = false;

    public function join(string $channel, string $connectionId, array $member): array
    {
        $this->calls[] = 'join';
        $result = parent::join($channel, $connectionId, $member);

        return $this->silent ? ['snapshot' => $result['snapshot']] : $result;
    }

    public function leave(string $channel, string $connectionId): array
    {
        $this->calls[] = 'leave';
        $result = parent::leave($channel, $connectionId);

        return $this->silent ? [] : $result;
    }

    public function snapshot(string $channel): array
    {
        $this->calls[] = 'snapshot';

        return parent::snapshot($channel);
    }

    public function timesCalled(string $method): int
    {
        return count(array_filter($this->calls, static fn (string $call) => $call === $method));
    }
}

/** A Server assembled around the real runtime singletons plus the recorders above. */
function chanMutSubServer(): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = [
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => test()->bridge,
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => test()->router,
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => test()->presence,
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => test()->logger,
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => app(OctaneWorker::class),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    return $server;
}

/** An auth string signed the way the application's auth endpoint signs one. */
function chanMutSubAuth(string $socketId, string $channel, ?string $channelData = null, ?Grant $grant = null): string
{
    $encodedGrant = $grant?->encode();

    $signature = hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString($socketId, $channel, $channelData, $encodedGrant),
        (string) config('lightspeed.reverb_compat.app_secret'),
    );

    return (string) config('lightspeed.reverb_compat.app_key')
        .':'.$signature
        .($encodedGrant !== null ? ':'.$encodedGrant : '');
}

/** Open a connection on this worker. Returns [fd, socketId]. */
function chanMutSubConnect(): array
{
    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.".random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, $socketId, []);

    return [$fd, $socketId];
}

function chanMutSubSubscribe(Server $server, ChanMutSubSwoole $swoole, int $fd, array $data): void
{
    driveLightspeed($server, 'handlePusherSubscribe', [$swoole, $fd, $data]);
}

/** The decoded `data` member of the last frame pushed. */
function chanMutSubLastData(ChanMutSubSwoole $swoole): array
{
    $frame = end($swoole->pushed);

    return json_decode($frame['data'] ?? '{}', true);
}

function chanMutSubChannel(string $prefix = 'presence-'): string
{
    return $prefix.'room.'.bin2hex(random_bytes(6));
}

beforeEach(function () {
    $this->logger = new ChanMutSubLogger();
    $this->bridge = new ChanMutSubBridge(app(RedisRelay::class));
    $this->router = ChanMutSubRouter::make();
    $this->presence = new ChanMutSubPresence(app(ConfigRepository::class));

    $this->swoole = ChanMutSubSwoole::make();
    $this->server = chanMutSubServer();
});

test('a refused subscribe is logged with the connection, a shortened channel and the reason', function () {
    // The log line is the whole record of a refusal, and the channel on it is
    // deliberately not the channel that was sent: repeating a name that was
    // refused for being too long turns one rejected allocation into an accepted
    // one, on the server's disk, once per frame.
    [$fd] = chanMutSubConnect();
    $junk = str_repeat('a', 300);

    chanMutSubSubscribe($this->server, $this->swoole, $fd, ['channel' => $junk]);

    expect($this->logger->linesFor('subscription-error'))->toBe([[
        'action' => 'subscription-error',
        'fields' => [
            'fd' => $fd,
            'channel' => ChannelName::forDisplay($junk),
            'reason' => 'channel-name-too-long',
        ],
    ]])
        ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([]);
});

test('a subscribe whose signature does not verify is logged as an authorization failure', function () {
    // "Authorization failed" is all the client is told, on purpose. Which means
    // the reason, the channel and the connection exist in one place only, and
    // an operator who cannot see all three cannot tell a client sending a
    // stale auth string from a server with the wrong secret.
    [$fd] = chanMutSubConnect();
    $channel = chanMutSubChannel('private-');

    chanMutSubSubscribe($this->server, $this->swoole, $fd, [
        'channel' => $channel,
        'auth' => 'test-key:not-a-signature',
    ]);

    expect($this->logger->linesFor('subscription-error'))->toBe([[
        'action' => 'subscription-error',
        'fields' => [
            'fd' => $fd,
            'channel' => $channel,
            'reason' => 'auth-failed',
        ],
    ]])
        ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([]);
});

test('a revoked grant refuses the subscribe it arrived on, and says which one', function () {
    // The subscribe gate asks the same authoritative question the message path
    // asks, because a correctly signed auth string is otherwise a permanent
    // ticket back into the fan-out of a channel the application has revoked.
    // The refusal is a 401 subscription_error, the same shape a failed
    // signature gets, because from the client's side it is the same answer:
    // authorize again.
    [$fd, $socketId] = chanMutSubConnect();
    $channel = chanMutSubChannel('private-');
    $tag = 'chanmut:'.bin2hex(random_bytes(6));

    app(GrantManager::class)->revoke($tag);

    // Issued before the revoke, so it is exactly the credential a revoke is
    // supposed to invalidate, and unexpired, so nothing else can refuse it.
    $issuedAt = (int) (microtime(true) * 1_000_000) - 5_000_000;
    $grant = new Grant([$tag], [], $issuedAt, $issuedAt + 600_000_000);

    chanMutSubSubscribe($this->server, $this->swoole, $fd, [
        'channel' => $channel,
        'auth' => chanMutSubAuth($socketId, $channel, null, $grant),
    ]);

    expect($this->logger->linesFor('subscription-error'))->toBe([[
        'action' => 'subscription-error',
        'fields' => [
            'fd' => $fd,
            'channel' => $channel,
            'reason' => 'revoked',
        ],
    ]])
        ->and(end($this->swoole->pushed)['event'])->toBe('pusher_internal:subscription_error')
        ->and(chanMutSubLastData($this->swoole)['status'])->toBe(401)
        ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([])
        ->and(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();
});

test('an accepted subscribe claims the owner of the resource the channel names', function () {
    // Owner routing is what makes a resource's writes serializable across
    // instances, and the claim is made when someone joins rather than when the
    // first write arrives: a channel with subscribers and no owner routes its
    // first command to nobody.
    [$fd] = chanMutSubConnect();
    $channel = chanMutSubChannel('public-');

    chanMutSubSubscribe($this->server, $this->swoole, $fd, ['channel' => $channel]);

    expect($this->router->claims)->toBe([[
        'channel' => $channel,
        'reason' => 'channel-subscribe',
    ]])
        ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([$channel]);
});

test('a presence join announces the member the store holds, not the one the client sent', function () {
    // The store is where a member becomes shared: it assigns the colour, and it
    // reconciles a user who is already present from another connection. The
    // frame everyone else on the channel receives has to be that member, or one
    // client's view of who is in the room differs from another's for the rest
    // of the connection.
    config()->set('lightspeed.presence.color_slots', 8);

    [$fd, $socketId] = chanMutSubConnect();
    $channel = chanMutSubChannel();
    $channelData = json_encode(['user_id' => '42', 'user_info' => ['name' => 'Ada']], JSON_THROW_ON_ERROR);

    chanMutSubSubscribe($this->server, $this->swoole, $fd, [
        'channel' => $channel,
        'auth' => chanMutSubAuth($socketId, $channel, $channelData),
        'channel_data' => $channelData,
    ]);

    $added = $this->bridge->eventsNamed('pusher_internal:member_added');

    expect($added)->toHaveCount(1)
        ->and($added[0]['payload'])->toBe([
            'user_id' => '42',
            'user_info' => ['name' => 'Ada', 'colorIndex' => 0],
        ]);

    // And the snapshot the joining client is given is the one the join already
    // returned: asking the store twice is a second round trip on the path an
    // anonymous client drives, for an answer it has just been handed.
    expect($this->presence->timesCalled('snapshot'))->toBe(1);
});

test('re-subscribing to a presence channel does not announce the member again', function () {
    // Clients re-subscribe: a `lightspeed:stale` frame asks them to, and a
    // reconnect does it for every channel at once. Announcing a member who was
    // already there tells every other client someone joined who never left.
    [$fd, $socketId] = chanMutSubConnect();
    $channel = chanMutSubChannel();
    $channelData = json_encode(['user_id' => '42', 'user_info' => ['name' => 'Ada']], JSON_THROW_ON_ERROR);

    $subscribe = [
        'channel' => $channel,
        'auth' => chanMutSubAuth($socketId, $channel, $channelData),
        'channel_data' => $channelData,
    ];

    chanMutSubSubscribe($this->server, $this->swoole, $fd, $subscribe);
    $afterFirst = count($this->bridge->eventsNamed('pusher_internal:member_added'));

    chanMutSubSubscribe($this->server, $this->swoole, $fd, $subscribe);

    expect($afterFirst)->toBe(1)
        ->and($this->bridge->eventsNamed('pusher_internal:member_added'))->toHaveCount(1);
});

test('a store answer that does not say to announce anything announces nothing', function () {
    // The flags are read with a default, and the default decides what happens
    // when the store answers without them. Defaulting to "announce" would put a
    // member_added on the channel for a join the store never confirmed, and a
    // member_removed for a member who is still there.
    $this->presence->silent = true;

    [$fd, $socketId] = chanMutSubConnect();
    $channel = chanMutSubChannel();
    $channelData = json_encode(['user_id' => '42', 'user_info' => ['name' => 'Ada']], JSON_THROW_ON_ERROR);

    chanMutSubSubscribe($this->server, $this->swoole, $fd, [
        'channel' => $channel,
        'auth' => chanMutSubAuth($socketId, $channel, $channelData),
        'channel_data' => $channelData,
    ]);

    driveLightspeed($this->server, 'dropSubscription', [$fd, $channel]);

    expect($this->bridge->eventsNamed('pusher_internal:member_added'))->toBe([])
        ->and($this->bridge->eventsNamed('pusher_internal:member_removed'))->toBe([]);
});

test('one connection of a user leaving is not the user leaving', function () {
    // Two tabs, one person. The store counts connections per user and only the
    // last one out is the member leaving, so announcing every dropped
    // connection would remove a member from every other client's roster while
    // they are still in the room, with nothing to put them back.
    $channel = chanMutSubChannel();
    $channelData = json_encode(['user_id' => '42', 'user_info' => ['name' => 'Ada']], JSON_THROW_ON_ERROR);

    $fds = [];
    foreach ([0, 1] as $ignored) {
        [$fd, $socketId] = chanMutSubConnect();
        $fds[] = $fd;

        chanMutSubSubscribe($this->server, $this->swoole, $fd, [
            'channel' => $channel,
            'auth' => chanMutSubAuth($socketId, $channel, $channelData),
            'channel_data' => $channelData,
        ]);
    }

    driveLightspeed($this->server, 'dropSubscription', [$fds[0], $channel]);

    expect($this->bridge->eventsNamed('pusher_internal:member_removed'))->toBe([]);

    driveLightspeed($this->server, 'dropSubscription', [$fds[1], $channel]);

    $removed = $this->bridge->eventsNamed('pusher_internal:member_removed');

    expect($removed)->toHaveCount(1)
        ->and($removed[0]['payload'])->toBe(['user_id' => '42']);
});

test('leaving a presence channel the connection was never on touches the shared store not at all', function () {
    // An unsubscribe frame is unauthenticated and free to send. Taken at its
    // word it is a Redis round trip per frame on the shared presence state of a
    // channel this connection has nothing to do with.
    [$fd] = chanMutSubConnect();

    driveLightspeed($this->server, 'handlePusherUnsubscribe', [
        $this->swoole,
        $fd,
        ['channel' => chanMutSubChannel()],
    ]);

    expect($this->presence->calls)->toBe([]);
});

test('the member_removed frame always carries a string user id, even when there is none', function () {
    // The id goes on the wire, where every client compares it against the ids
    // in its own roster, and those are strings. An id that is a number for one
    // member and a string for another is a comparison that fails for exactly
    // the members whose ids came from an integer primary key. A member with no
    // id at all is announced as the empty string rather than as a placeholder
    // no client could match.
    $channel = chanMutSubChannel();
    $connectionIds = app(ConnectionId::class);

    foreach ([['user_id' => 42], []] as $index => $member) {
        [$fd] = chanMutSubConnect();

        // Recorded locally as the member, and joined in the shared store under
        // an id of its own, which is what makes the leave announce anything.
        app(ChannelManager::class)->subscribe($fd, $channel, $member);
        $this->presence->join(
            $channel,
            $connectionIds->presenceConnectionId($fd),
            ['user_id' => "store-{$index}"],
        );

        driveLightspeed($this->server, 'dropSubscription', [$fd, $channel]);
    }

    expect(array_column(
        array_column($this->bridge->eventsNamed('pusher_internal:member_removed'), 'payload'),
        'user_id',
    ))->toBe(['42', '']);
});

test('a subscribe naming no usable channel is refused as one that names no channel', function () {
    // `channel` arrives from the client, so it is whatever JSON can hold. An
    // empty string and a number are both "no channel named", and both have to
    // be refused here: read as a channel, a number subscribes the connection to
    // the string it becomes, and an empty string reaches the length check and
    // is reported to the client as a name that is too long.
    foreach (['', 123, ['nested'], null] as $channel) {
        $swoole = ChanMutSubSwoole::make();
        [$fd] = chanMutSubConnect();

        chanMutSubSubscribe($this->server, $swoole, $fd, ['channel' => $channel]);

        expect(chanMutSubLastData($swoole)['code'])->toBe('invalid-channel')
            ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([]);
    }
});
