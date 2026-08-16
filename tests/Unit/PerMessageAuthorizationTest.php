<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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
use Lightspeed\Protocol\SubscriptionAuthorizer;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: a connection's authorization is
 * re-checked on every message, not once at subscribe, and revoking it takes
 * effect immediately in BOTH directions.
 *
 * Before this feature the package authorized at pusher:subscribe and never
 * again. Revoking someone's access left them holding a socket they could keep
 * sending on, and keep receiving on, until they disconnected. And nothing in
 * the suite noticed, because "a revoked user's next frame still gets through"
 * was a passing state.
 *
 * A previous attempt at this feature was reverted (commit 637d331) after three
 * reviews and a live server run found eight paths on which it failed OPEN. Every
 * one of them came from the same root cause: the grant was stored separately
 * from the credential, so "the application never tagged this channel" and "the
 * grant is gone" were the same observation and the package guessed. The tests
 * below are written against the design that removes the guess. The grant is
 * folded into the auth string the client must already present. And the three
 * marked TRIPWIRE are the ones that must be watched failing before they are
 * believed.
 *
 * Why the server is assembled by hand rather than started: the behaviour under
 * test is the message path of a live connection, and the message path is
 * private to Server by design. Everything real is real. The signing, the
 * signature check, the Redis revocation log, the client-event dispatcher. and
 * only the two things that would need a listening socket are substituted: the
 * Swoole server (a recorder for what is pushed to the client) and the Octane
 * worker (which runs the handler in the current container instead of
 * marshalling it into a booted application).
 */

/** Records what the server pushed, in place of a real websocket. */
class RecordingSwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

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
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }
}

/** A server on which every socket operation fails, standing in for a dead one. */
class ExplodingSwooleServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        throw new \RuntimeException('socket is gone');
    }

    public function push(int $fd, \Swoole\WebSocket\Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        throw new \RuntimeException('socket is gone');
    }
}

/** Runs the client-event task inline, in the container the test is already in. */
class InlineOctaneWorker extends OctaneWorker
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

/** The application handler whose running (or not) is the thing being asserted. */
class GrantSpyHandler implements ClientEventHandler
{
    public static int $ran = 0;

    /** @var list<?array> the opaque payload seen on each event */
    public static array $sawAuth = [];

    public function handle(ClientEvent $event): ?ClientEventResult
    {
        static::$ran++;
        static::$sawAuth[] = $event->auth;

        return ClientEventResult::response(requestId: 'req-1', response: ['ok' => true]);
    }
}

/**
 * Assemble a Server around the real runtime singletons.
 *
 * Built without its constructor so the Octane worker it would otherwise build
 * for itself can be replaced; every other collaborator is the one the container
 * hands the rest of the package.
 */
function grantTestServer(RecordingSwooleServer $swoole, array $overrides = []): Server
{
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
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new InlineOctaneWorker(),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    // Builds the websocket surfaces out of the collaborators just injected,
    // exactly as the constructor does after building the Octane worker.
    driveServer($server, 'compose', []);

    // These tests drive the callbacks directly, so nothing has run the
    // workerStart that a real process would. Say so, or every handshake here is
    // refused by the readiness gate rather than judged on its merits.
    markLightspeedWorkerReady($server);

    // The real worker-boot step, which is what registers the listener that
    // drops revoked connections out of the fan-out.
    driveServer($server, 'bootRevocationListeners', [$swoole]);

    return $server;
}

/** Call one of the server's private methods, wherever it lives. */
function driveServer(Server $server, string $method, array $arguments): mixed
{
    return driveLightspeed($server, $method, $arguments);
}

/** Read one of the server's private properties, wherever it lives. */
function readServerProperty(Server $server, string $property): mixed
{
    return readLightspeedProperty($server, $property);
}

/**
 * The auth string the application's own auth endpoint signs, produced by the
 * REAL broadcaster so that what is tested is the thing that ships.
 *
 * Passing $tags runs `Lightspeed::tag(...)->with(...)` exactly as a
 * `Broadcast::channel()` callback would, immediately before the broadcaster
 * builds its response. Which is the sequence in a real request.
 */
function grantTestAuth(string $socketId, string $channel, ?array $tags = null, array $payload = []): string
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

    app(PendingGrants::class)->begin();

    if ($tags !== null) {
        app(GrantManager::class)->tag($tags)->with($payload);
    }

    return $broadcaster->validAuthenticationResponse($request, true)['auth'];
}

/** Open a connection and subscribe it. Returns [fd, socketId]. */
function connectAndSubscribe(Server $server, RecordingSwooleServer $swoole, string $channel, ?array $tags = null, array $payload = []): array
{
    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.".random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, $socketId, []);

    driveServer($server, 'handlePusherSubscribe', [$swoole, $fd, [
        'channel' => $channel,
        'auth' => grantTestAuth($socketId, $channel, $tags, $payload),
    ]]);

    return [$fd, $socketId];
}

/** Send one client event on an already-subscribed connection. */
function sendClientEvent(Server $server, RecordingSwooleServer $swoole, int $fd, string $channel): void
{
    driveServer($server, 'handlePusherClientEvent', [
        $swoole,
        $fd,
        $channel,
        'client-typing',
        ['message' => 'hello'],
        96,
    ]);
}

function frameEvents(RecordingSwooleServer $swoole): array
{
    return array_map(static fn (array $frame) => $frame['event'] ?? null, $swoole->pushed);
}

/** The decoded `data` member of the last frame pushed. */
function lastFrameData(RecordingSwooleServer $swoole): array
{
    $frame = end($swoole->pushed);

    return json_decode($frame['data'] ?? '{}', true);
}

/** An unexpired instant on the same scale the package uses: microseconds. */
function grantTestNow(): int
{
    return (int) (microtime(true) * 1_000_000);
}

/** Tags and channels are unique per test so a shared Redis cannot be collided with. */
function uniqueTag(string $prefix): string
{
    return "{$prefix}:".bin2hex(random_bytes(8));
}

function uniqueChannel(): string
{
    return 'private-doc.'.bin2hex(random_bytes(8));
}

/**
 * Turn Redis command events on, and hand back a list that fills with the name
 * of every command issued from here on.
 *
 * Laravel's RedisManager ships with `$events = false`, and a connection
 * resolved before events are enabled never gets a dispatcher at all. So a test
 * that merely listens for CommandExecuted records NOTHING, and "expect no Redis
 * calls" passes whatever the code does. Two tests in this file were doing
 * exactly that. Enabling events and purging the already-resolved connections is
 * what makes counting mean something.
 *
 * @return list<string> every Redis command the callable caused, in order
 */
function redisCommandsDuring(callable $work): array
{
    enableRedisCommandEvents();

    $commands = [];

    $listener = function ($event) use (&$commands) {
        $commands[] = $event->command;
    };

    app('events')->listen(\Illuminate\Redis\Events\CommandExecuted::class, $listener);

    try {
        $work();
    } finally {
        app('events')->forget(\Illuminate\Redis\Events\CommandExecuted::class);
    }

    return $commands;
}

/** @see redisCommandsDuring */
function enableRedisCommandEvents(): void
{
    app('redis')->enableEvents();

    foreach (array_unique(['default', (string) config('lightspeed.auth.redis_connection', 'default')]) as $connection) {
        Redis::purge($connection);
    }
}

/**
 * A loopback port that is verifiably closed at this instant.
 *
 * This was the literal 6399, commented as "a port with nothing behind it". It
 * is not a port anybody reserved, and anything that happens to be listening on
 * it turns the two Redis-outage tripwires green for the WRONG REASON: they
 * assert that a message is refused when the revocation log cannot be read, and
 * a connection that reaches a stranger's Redis instead, one where no tag was
 * ever revoked, refuses nothing while the test still passes on the strength of
 * some other assertion. A reviewer hit exactly that.
 *
 * So the port is probed. If nothing in the range is closed, this raises rather
 * than handing back a port it has not checked.
 */
function unreachableRedisPort(): int
{
    foreach (range(6390, 6489) as $candidate) {
        $socket = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.05);

        if ($socket === false) {
            return $candidate;
        }

        fclose($socket);
    }

    throw new RuntimeException(
        'Every port in 6390-6489 is accepting connections, so no Redis outage can be simulated.'
    );
}

/**
 * Point the revocation log at the closed port, and PROVE the outage first.
 *
 * The proof is the whole point. A tripwire that asserts fail-closed behaviour
 * is worthless if the dependency it thinks is down is actually up, so the
 * outage is established as a fact of this test run before anything is asserted
 * about it.
 */
function useUnreachableAuthRedis(): void
{
    config()->set('lightspeed.auth.redis_connection', 'lightspeed_unreachable');

    Redis::purge('lightspeed_unreachable');

    $reached = true;

    try {
        Redis::connection('lightspeed_unreachable')->ping();
    } catch (\Throwable) {
        $reached = false;
    }

    expect($reached)->toBeFalse('the "unreachable" Redis answered, so this test would prove nothing');
}

beforeEach(function () {
    GrantSpyHandler::$ran = 0;
    GrantSpyHandler::$sawAuth = [];

    config()->set('lightspeed.client_event_handlers', [GrantSpyHandler::class]);

    // A port with nothing behind it, used by the Redis-outage tripwires. and
    // probed rather than assumed, see unreachableRedisPort().
    config()->set('database.redis.lightspeed_unreachable', [
        'host' => '127.0.0.1',
        'port' => unreachableRedisPort(),
        'database' => 0,
        'timeout' => 0.2,
    ]);

    Redis::purge('lightspeed_unreachable');

    $this->swoole = RecordingSwooleServer::make();
    $this->server = grantTestServer($this->swoole);
});

// ---------------------------------------------------------------------------
// The credential itself
// ---------------------------------------------------------------------------

test('a tagged channel callback signs the grant into the auth string, and the server reads it back', function () {
    $socketId = '123.456';
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    $auth = grantTestAuth($socketId, $channel, [$tag], ['can_edit' => true]);

    // Three fields, not two: key, signature, grant.
    expect(substr_count($auth, ':'))->toBe(2);

    $decision = (new SubscriptionAuthorizer(
        config('lightspeed.reverb_compat.app_key'),
        config('lightspeed.reverb_compat.app_secret'),
    ))->authorize($socketId, $channel, $auth, null);

    expect($decision->granted)->toBeTrue()
        ->and($decision->grant)->not->toBeNull()
        ->and($decision->grant->tags)->toBe([$tag])
        ->and($decision->grant->payload)->toBe(['can_edit' => true]);
});

/**
 * TRIPWIRE 1. The grant is the credential.
 *
 * Every mutation below is a client trying to keep the subscription while losing
 * the thing that makes it revocable. In the reverted design the first of them
 * WORKED: the grant lived in Redis under a TTL, the auth string had no expiry
 * and no knowledge of the grant, so replaying it after the TTL lapsed produced
 * a subscription nobody could ever revoke. That was proven on a running server,
 * and a backgrounded browser tab did it by accident.
 */
test('TRIPWIRE: a grant that was snipped or edited is refused at subscribe', function () {
    $socketId = '123.456';
    $channel = uniqueChannel();

    $authorizer = new SubscriptionAuthorizer(
        config('lightspeed.reverb_compat.app_key'),
        config('lightspeed.reverb_compat.app_secret'),
    );

    $auth = grantTestAuth($socketId, $channel, ['project:7'], ['can_edit' => false]);
    [$key, $signature, $encodedGrant] = explode(':', $auth);

    // The unmodified string is accepted. Without this the rest proves nothing:
    // an authorizer that refused everything would pass every case below.
    expect($authorizer->authorize($socketId, $channel, $auth, null)->granted)->toBeTrue();

    // Snipped: the grant removed, leaving a perfectly well-formed Pusher auth
    // string. THIS is the case the whole design exists for. Nothing about the
    // string looks wrong; it just is not the string that was signed.
    expect($authorizer->authorize($socketId, $channel, "{$key}:{$signature}", null)->granted)
        ->toBeFalse();

    // Edited: a different permission in the payload, re-encoded, signature left
    // alone.
    $forged = new Grant(['project:7'], ['can_edit' => true], grantTestNow(), grantTestNow() + 300_000_000);
    expect($authorizer->authorize($socketId, $channel, "{$key}:{$signature}:{$forged->encode()}", null)->granted)
        ->toBeFalse();

    // Edited: a tag removed, so that revoking it could never reach this
    // connection.
    $untagged = new Grant(['project:someone-elses'], ['can_edit' => false], grantTestNow(), grantTestNow() + 300_000_000);
    expect($authorizer->authorize($socketId, $channel, "{$key}:{$signature}:{$untagged->encode()}", null)->granted)
        ->toBeFalse();

    // A grant lifted from a different socket's auth string for the same channel.
    $otherAuth = grantTestAuth('999.999', $channel, ['project:7'], ['can_edit' => true]);
    $otherGrant = explode(':', $otherAuth)[2];
    expect($authorizer->authorize($socketId, $channel, "{$key}:{$signature}:{$otherGrant}", null)->granted)
        ->toBeFalse();

    // And the whole of that other socket's string, replayed on this one.
    expect($authorizer->authorize($socketId, $channel, $otherAuth, null)->granted)
        ->toBeFalse();

    // Garbage in the third field.
    expect($authorizer->authorize($socketId, $channel, "{$key}:{$signature}:not-base64!!", null)->granted)
        ->toBeFalse();

    // A fourth field.
    expect($authorizer->authorize($socketId, $channel, "{$auth}:extra", null)->granted)
        ->toBeFalse();
});

test('an expired grant is refused at subscribe, so an old auth string cannot be replayed forever', function () {
    $socketId = '123.456';
    $channel = uniqueChannel();

    $authorizer = new SubscriptionAuthorizer(
        config('lightspeed.reverb_compat.app_key'),
        config('lightspeed.reverb_compat.app_secret'),
    );

    config()->set('lightspeed.auth.grant_lifetime_seconds', 60);
    $auth = grantTestAuth($socketId, $channel, ['project:7']);

    $issuedAt = Grant::decode(explode(':', $auth)[2])->issuedAt;

    expect($authorizer->authorize($socketId, $channel, $auth, null, $issuedAt)->granted)->toBeTrue()
        ->and($authorizer->authorize($socketId, $channel, $auth, null, $issuedAt + 59_000_000)->granted)->toBeTrue()
        // One second past the configured lifetime.
        ->and($authorizer->authorize($socketId, $channel, $auth, null, $issuedAt + 61_000_000)->granted)->toBeFalse();
});

test('the grant lifetime is configurable', function () {
    config()->set('lightspeed.auth.grant_lifetime_seconds', 7);

    $auth = grantTestAuth('1.1', uniqueChannel(), ['project:7']);
    $grant = Grant::decode(explode(':', $auth)[2]);

    expect($grant->expiresAt - $grant->issuedAt)->toBe(7_000_000);

    config()->set('lightspeed.auth.grant_lifetime_seconds', 900);

    $auth = grantTestAuth('1.1', uniqueChannel(), ['project:7']);
    $grant = Grant::decode(explode(':', $auth)[2]);

    expect($grant->expiresAt - $grant->issuedAt)->toBe(900_000_000);
});

test('a presence grant is signed over the channel data as well', function () {
    $socketId = '123.456';
    $channel = 'presence-'.bin2hex(random_bytes(8));
    $channelData = json_encode(['user_id' => '42', 'user_info' => ['name' => 'Ada']]);

    $encodedGrant = (new Grant(['user:42'], ['can_edit' => true], grantTestNow(), grantTestNow() + 300_000_000))->encode();

    $auth = config('lightspeed.reverb_compat.app_key').':'.hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString($socketId, $channel, $channelData, $encodedGrant),
        config('lightspeed.reverb_compat.app_secret'),
    ).':'.$encodedGrant;

    $authorizer = new SubscriptionAuthorizer(
        config('lightspeed.reverb_compat.app_key'),
        config('lightspeed.reverb_compat.app_secret'),
    );

    $decision = $authorizer->authorize($socketId, $channel, $auth, $channelData);

    expect($decision->granted)->toBeTrue()
        ->and($decision->presenceMember['user_id'])->toBe('42')
        ->and($decision->grant->tags)->toBe(['user:42']);

    // Rewriting the claimed identity breaks the same signature the grant is in.
    $tampered = json_encode(['user_id' => '1', 'user_info' => ['name' => 'Ada']]);
    expect($authorizer->authorize($socketId, $channel, $auth, $tampered)->granted)->toBeFalse();
});

// ---------------------------------------------------------------------------
// The message path
// ---------------------------------------------------------------------------

test('a granted connection has its message handled, and sees the payload the app attached', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')], ['can_edit' => true]);
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and(GrantSpyHandler::$sawAuth[0])->toBe(['can_edit' => true])
        ->and(frameEvents($this->swoole))->toContain('lightspeed:response');
});

/**
 * TRIPWIRE 2. Revocation is immediate in BOTH directions.
 *
 * The sending half is the authoritative per-message Redis read. The receiving
 * half is the connection being taken out of the fan-out on the spot, a
 * broadcast never consults a grant, so refusing messages alone would leave a
 * revoked user reading everything said on the channel.
 *
 * The cheap answer, which this rejects, was "they stop receiving when the grant
 * expires".
 */
test('TRIPWIRE: a revoked connection can no longer send, and no longer receives', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [$tag]);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(1);

    // Receiving, before: the connection is in the fan-out for this channel.
    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);

    $this->swoole->pushed = [];
    app(GrantManager::class)->revoke($tag);
    $staleFrames = $this->swoole->pushed;

    // SENDING. Same connection, same socket, next frame: the application's
    // handler must not run. WHICH refusal frame comes back is deliberately not
    // asserted here, this worker heard the revocation and has already taken
    // the connection off the channel, so it is `not-subscribed`, while a worker
    // that had not heard it would answer `lightspeed:stale` off the
    // authoritative read. Both are a refusal; pinning one would make this
    // tripwire fail for a reason that is not the one it is guarding.
    $this->swoole->pushed = [];
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and($this->swoole->pushed)->not->toBeEmpty();

    // RECEIVING. The connection is out of the fan-out entirely, so a broadcast
    // to the channel reaches nobody. This is the half the cheap answer skipped:
    // refusing messages alone would leave a revoked user reading everything
    // said on the channel until their grant ran out.
    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0);

    // And the stale frame that tells the client to re-authorize went out at the
    // moment of the revoke, not whenever it next happened to send something.
    expect(array_map(static fn (array $f) => $f['event'], $staleFrames))->toBe(['lightspeed:stale']);
});

/**
 * TRIPWIRE 3. Redis unreachable means REFUSE.
 *
 * The per-message check is one uncached Redis read and it is authoritative, so
 * the only safe answer when it cannot be made is no. The reverted design
 * answered yes here, its check read a local memory mirror, so a Redis outage
 * was invisible to it and revocation was simply, silently dead.
 */
test('TRIPWIRE: with Redis unreachable, a message on a granted channel is refused', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(1);

    // Same connection, same valid unexpired grant, same subscription. The only
    // thing that changed is that the revocation log cannot be reached.
    useUnreachableAuthRedis();

    $this->swoole->pushed = [];
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and(frameEvents($this->swoole))->toContain('lightspeed:stale')
        ->and(lastFrameData($this->swoole)['reason'])->toBe('unavailable');
});

test('an expired grant refuses the next message even while Redis says nothing was revoked', function () {
    $channel = uniqueChannel();

    config()->set('lightspeed.auth.grant_lifetime_seconds', 1);

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(1);

    // Rewrite the held grant's expiry to the past rather than sleeping: a
    // blocking sleep in a Swoole worker freezes the whole process, and the
    // package is tested the way it runs.
    $grants = app(ConnectionGrants::class);
    $held = $grants->grantFor($fd, $channel);
    $grants->mint($fd, $channel, new Grant($held->tags, $held->payload, $held->issuedAt, $held->issuedAt - 1));

    $this->swoole->pushed = [];
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and(lastFrameData($this->swoole)['reason'])->toBe('expired');
});

test('the per-message check is never cached: a second message re-reads Redis', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [$tag]);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(2);

    // Revoke by writing the Redis key directly, bypassing the relay and the
    // local listener entirely. Nothing tells this worker anything. If the check
    // were memoized, mirrored, or skipped for a connection that has already
    // passed once, this message would go through.
    Redis::connection('default')->setex(
        'lightspeed:revoked:'.$tag,
        60,
        (string) app(RevocationLog::class)->now(),
    );

    $this->swoole->pushed = [];
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(2)
        ->and(lastFrameData($this->swoole)['reason'])->toBe('revoked');
});

test('a numeric tag is enforced, and revoking it is not a silent no-op', function () {
    $channel = uniqueChannel();
    Redis::connection('default')->del('lightspeed:revoked:7');

    // The exact shape that was broken on a live server: JSON turns the object
    // key "7" into the integer 7, the old is_string() guard dropped it, and
    // revoke('7') did nothing forever, reporting nothing.
    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, ['7']);

    $grant = app(ConnectionGrants::class)->grantFor($fd, $channel);
    expect($grant->tags)->toBe(['7'])
        ->and($grant->tags[0])->toBeString();

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(1);

    app(GrantManager::class)->revoke('7');

    $this->swoole->pushed = [];
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    // Refused, and out of the fan-out. The same pair of effects the tripwire
    // asserts for a string tag. Under the reverted build BOTH of these were
    // silently unaffected: the numeric tag was dropped on the way through JSON
    // and revoke('7') did nothing, forever, reporting nothing.
    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and($this->swoole->pushed)->not->toBeEmpty()
        ->and(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0);

    // And the authoritative read agrees, independently of the local drop.
    expect(app(RevocationLog::class)->isRevoked($grant))->toBeTrue();

    Redis::connection('default')->del('lightspeed:revoked:7');
});

test('re-subscribing after a revoke mints a fresh grant and works again', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd, $socketId] = connectAndSubscribe($this->server, $this->swoole, $channel, [$tag]);
    app(GrantManager::class)->revoke($tag);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(0);

    // The application's own authorization runs again and still says yes.
    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => grantTestAuth($socketId, $channel, [$tag], ['can_edit' => true]),
    ]]);

    $this->swoole->pushed = [];
    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and(GrantSpyHandler::$sawAuth[0])->toBe(['can_edit' => true])
        ->and(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);
});

test('a revoke does not drop a connection that re-subscribed after it', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd, $socketId] = connectAndSubscribe($this->server, $this->swoole, $channel, [$tag]);

    // Fresh grant, minted now.
    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => grantTestAuth($socketId, $channel, [$tag]),
    ]]);

    // A revocation from BEFORE that grant was issued: a relay entry that
    // arrived late, or a peer worker catching up. It must not drop a connection
    // the application has since re-approved, or one revoke becomes an endless
    // subscribe-and-drop loop.
    $grant = app(ConnectionGrants::class)->grantFor($fd, $channel);
    driveServer($this->server, 'dropConnectionsCarrying', [$tag, $grant->issuedAt - 5_000]);

    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);
});

// ---------------------------------------------------------------------------
// Opt-in: an application that never calls tag()
// ---------------------------------------------------------------------------

test('an app that never calls tag() signs the same two-field auth string as before', function () {
    $socketId = '123.456';
    $channel = uniqueChannel();

    $auth = grantTestAuth($socketId, $channel);

    // Byte for byte what the Pusher protocol specifies, and what this package
    // signed before per-message authorization existed.
    expect($auth)->toBe(config('lightspeed.reverb_compat.app_key').':'.hash_hmac(
        'sha256',
        "{$socketId}:{$channel}",
        config('lightspeed.reverb_compat.app_secret'),
    ));
});

test('an app that never calls tag() makes NO Redis calls on subscribe', function () {
    $channel = uniqueChannel();
    $publicChannel = 'chat.'.bin2hex(random_bytes(4));

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    app(ChannelManager::class)->connect($fd, $socketId, []);

    $untaggedAuth = grantTestAuth($socketId, $channel);

    // Whatever the auth request itself did, the subscribe is what is being
    // measured. And the reverted build put an unconditional Redis GET here,
    // on public channels too, which let an unauthenticated client serialize the
    // whole server behind Redis round trips.
    $commands = redisCommandsDuring(function () use ($fd, $channel, $publicChannel, $untaggedAuth) {
        driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
            'channel' => $channel,
            'auth' => $untaggedAuth,
        ]]);

        driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
            'channel' => $publicChannel,
            'auth' => null,
        ]]);
    });

    expect($commands)->toBe([]);
});

test('an app that never calls tag() is untouched by any revoke', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();

    app(GrantManager::class)->revoke($tag);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and(GrantSpyHandler::$sawAuth[0])->toBeNull()
        ->and(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);
});

test('an untagged connection makes NO Redis calls per message', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel);

    $commands = redisCommandsDuring(function () use ($fd, $channel) {
        sendClientEvent($this->server, $this->swoole, $fd, $channel);
    });

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and($commands)->toBe([]);
});

// ---------------------------------------------------------------------------
// The documented API, exactly as published
// ---------------------------------------------------------------------------

/**
 * The sample from the README, run rather than read.
 *
 * Review once caught a documented handler sample that silently did nothing,
 * because the registration step it needed was never written down. This asserts
 * that the three published lines are the three lines that work: tag()->with()
 * inside a channel callback, revoke() from anywhere, and $event->auth in the
 * handler.
 */
test('the documented API works as published', function () {
    // This test uses the literal tags from the README, which are the only
    // fixed keys the suite writes. An earlier run that aborted between the
    // revoke and the cleanup below would otherwise leave them live for an
    // hour and fail every run in that window, so clear them on the way in as
    // well as on the way out.
    Redis::connection('default')->del('lightspeed:revoked:project:7');
    Redis::connection('default')->del('lightspeed:revoked:user:42');
    $projectId = 7;
    $userId = 42;
    $channel = 'private-doc.'.bin2hex(random_bytes(6));

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    app(ChannelManager::class)->connect($fd, $socketId, []);

    // ---- routes/channels.php --------------------------------------------
    // Broadcast::channel('doc.{id}', function (User $user, string $id) {
    //     $doc = Document::findOrFail($id);
    //     if (! $user->can('view', $doc)) { return false; }
    app(PendingGrants::class)->begin();

    \Lightspeed\Facades\Lightspeed::tag(["user:{$userId}", "project:{$projectId}"])
        ->with(['can_edit' => true]);

    //     return true;
    // });
    // ---------------------------------------------------------------------

    $auth = (new LightspeedBroadcaster(
        app(BroadcastBridge::class),
        app(PendingGrants::class),
        app(RevocationLog::class),
        new \Pusher\Pusher(
            config('lightspeed.reverb_compat.app_key'),
            config('lightspeed.reverb_compat.app_secret'),
            config('lightspeed.reverb_compat.app_id'),
        ),
    ))->validAuthenticationResponse(
        Request::create('/broadcasting/auth', 'POST', ['socket_id' => $socketId, 'channel_name' => $channel]),
        true,
    )['auth'];

    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => $auth,
    ]]);

    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    // $event->auth['can_edit'], in the handler.
    expect(GrantSpyHandler::$sawAuth[0]['can_edit'])->toBeTrue();

    // Lightspeed::revoke('project:7');
    \Lightspeed\Facades\Lightspeed::revoke("project:{$projectId}");

    sendClientEvent($this->server, $this->swoole, $fd, $channel);

    expect(GrantSpyHandler::$ran)->toBe(1)
        ->and(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0);

    Redis::connection('default')->del('lightspeed:revoked:project:7');
    Redis::connection('default')->del('lightspeed:revoked:user:42');
});

test('a re-subscribe that carries no grant clears the one the connection was holding', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd, $socketId] = connectAndSubscribe($this->server, $this->swoole, $channel, [$tag]);
    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->not->toBeNull();

    // A deploy removes the tag() call from this channel's callback, and the
    // client re-subscribes. Leaving the old grant in place would strand the
    // connection behind a grant that expires and then refuses forever, with
    // re-subscribing unable to clear it.
    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => grantTestAuth($socketId, $channel),
    ]]);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();

    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(1);
});

test('a grant does not survive its connection, so a reused fd cannot inherit one', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);
    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->not->toBeNull();

    app(ConnectionGrants::class)->forgetConnection($fd);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull()
        // And the tag index went with it, so a later revoke cannot resurrect a
        // reference to a connection that is gone.
        ->and(app(ConnectionGrants::class)->connectionsCarrying('anything', PHP_INT_MAX))->toBe([]);
});

test('a short revocation reply is an error, not an allow', function () {
    $log = app(RevocationLog::class);
    $now = $log->now();
    $grant = new Grant(['a', 'b', 'c'], [], $now, $now + 300_000_000);

    // Redis answering about fewer tags than it was asked about must never read
    // as "none of these were revoked". Every hole in the reverted build had
    // this shape: an incomplete answer taken for a permissive one.
    $connection = Mockery::mock();
    $connection->shouldReceive('mget')->andReturn([null, null]);
    Redis::shouldReceive('connection')->andReturn($connection);

    expect(fn () => $log->isRevoked($grant))
        ->toThrow(RuntimeException::class, 'returned 2 values for 3 tags');
});

// ---------------------------------------------------------------------------
// tag() rejects what it cannot honour
// ---------------------------------------------------------------------------

test('an over-long tag is rejected at tag() time, not truncated', function () {
    config()->set('lightspeed.auth.max_tag_length', 32);

    // Truncating is what the reverted build did, to fit a fixed-width mirror
    // row. It produced a grant carrying one string while revoke() wrote a
    // different one, a connection that could never be revoked and a revoke
    // that could never land, neither of which said anything.
    expect(fn () => app(GrantManager::class)->tag(['project:'.str_repeat('x', 64)]))
        ->toThrow(InvalidArgumentException::class, 'over the 32-byte limit');

    // A tag right at the limit is fine.
    expect(app(GrantManager::class)->tag(['project:'.str_repeat('x', 24)])->tags())
        ->toBe(['project:'.str_repeat('x', 24)]);
});

test('tag() rejects empty, non-string, and over-numerous tags', function () {
    $grants = app(GrantManager::class);

    expect(fn () => $grants->tag(['']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $grants->tag([null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $grants->tag([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $grants->tag(['ok', new stdClass()]))->toThrow(InvalidArgumentException::class);

    config()->set('lightspeed.auth.max_tags', 3);
    expect(fn () => $grants->tag(['a', 'b', 'c', 'd']))
        ->toThrow(InvalidArgumentException::class, 'over the limit of 3');
});

test('revoke() rejects an empty tag rather than revoking everything', function () {
    expect(fn () => app(GrantManager::class)->revoke(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(GrantManager::class)->revoke([null]))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Per-request isolation
// ---------------------------------------------------------------------------

test('a channel callback that describes a grant and then fails signs nothing', function () {
    $socketId = '123.456';
    $channel = uniqueChannel();

    // A `Broadcast::channel()` callback that calls tag() and then returns false,
    // or throws: auth() never reaches validAuthenticationResponse, and the next
    // authorization on this coroutine must not inherit the leftover.
    $pending = app(PendingGrants::class);
    $pending->begin();
    app(GrantManager::class)->tag(['project:leaked'])->with(['can_edit' => true]);

    // The next request starts here.
    $auth = grantTestAuth($socketId, $channel);

    expect(substr_count($auth, ':'))->toBe(1);
});

test('a grant is taken once and never reused by a later authorization', function () {
    $pending = app(PendingGrants::class);

    $pending->begin();
    app(GrantManager::class)->tag(['project:7']);

    expect($pending->take())->not->toBeNull()
        ->and($pending->take())->toBeNull();
});

// ---------------------------------------------------------------------------
// Grant encoding
// ---------------------------------------------------------------------------

test('a grant round-trips through its encoded form, numeric tags included', function () {
    $grant = new Grant(['7', 'project:7', 'user:42'], ['can_edit' => true, 'role' => 'editor'], 1_700_000_000_000, 1_700_000_300_000);

    $decoded = Grant::decode($grant->encode());

    expect($decoded->tags)->toBe(['7', 'project:7', 'user:42'])
        ->and($decoded->payload)->toBe(['can_edit' => true, 'role' => 'editor'])
        ->and($decoded->issuedAt)->toBe(1_700_000_000_000)
        ->and($decoded->expiresAt)->toBe(1_700_000_300_000);
});

test('the encoded grant contains no colon, so it cannot disturb the auth string split', function () {
    $encoded = (new Grant(['a:b:c'], ['x' => 'y:z'], 1, 2))->encode();

    expect($encoded)->not->toContain(':')
        ->and($encoded)->not->toContain('+')
        ->and($encoded)->not->toContain('/');
});

test('an unusable grant decodes to null rather than to something permissive', function () {
    expect(Grant::decode(''))->toBeNull()
        ->and(Grant::decode('not base64 !!'))->toBeNull()
        ->and(Grant::decode(rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=')))->toBeNull()
        // No tags: a grant nothing could ever revoke.
        ->and(Grant::decode(rtrim(strtr(base64_encode('{"t":[],"p":{},"i":1,"e":2}'), '+/', '-_'), '=')))->toBeNull()
        // No expiry.
        ->and(Grant::decode(rtrim(strtr(base64_encode('{"t":["a"],"p":{}}'), '+/', '-_'), '=')))->toBeNull();
});

// ===========================================================================
// F1 / F2. Grant expiry has to DROP something.
// ===========================================================================

/**
 * A connection that only ever LISTENS never sends a frame, so nothing ever
 * calls grantRefusal() for it. Before the sweeper existed, hasExpired() had
 * exactly two callers and both were on client-initiated paths, which meant a
 * grant lifetime bounded nothing at all in the receiving direction: a listener
 * with a 3-second grant was proven, on a live server, still receiving
 * broadcasts at t=8s.
 *
 * That is worse than itself. Multiple docblocks and the commit message lean on
 * expiry as the BACKSTOP that turns a lost relay control entry into a delay
 * rather than a hole. With no sweeper, the relay was the only receive-side
 * enforcement there was.
 */

/** Push the grant a connection is holding into the past, without sleeping. */
function expireHeldGrant(int $fd, string $channel): void
{
    $grants = app(ConnectionGrants::class);
    $held = $grants->grantFor($fd, $channel);

    $grants->mint($fd, $channel, new Grant($held->tags, $held->payload, $held->issuedAt, $held->issuedAt - 1));
}

test('TRIPWIRE: a listener whose grant expired is swept out of the fan-out', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);

    // It is in the fan-out, which is the whole point: a broadcast reaches it
    // without it ever having sent a frame.
    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);

    expireHeldGrant($fd, $channel);

    // Still in the fan-out, because nothing has looked yet. This is the state a
    // pure listener sat in forever.
    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);

    $this->swoole->pushed = [];
    driveServer($this->server, 'sweepExpiredGrants', []);

    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0)
        ->and(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull()
        ->and(array_map(static fn (array $f) => $f['event'], $this->swoole->pushed))->toBe(['lightspeed:stale'])
        ->and(lastFrameData($this->swoole)['reason'])->toBe('expired');
});

test('the sweeper leaves a grant that has not expired alone', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);

    driveServer($this->server, 'sweepExpiredGrants', []);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->not->toBeNull()
        ->and(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);
});

test('the sweeper is armed on worker boot and cleared on worker stop', function () {
    driveServer($this->server, 'bootGrantSweeper', [$this->swoole]);

    $timerId = readServerProperty($this->server, 'grantSweepTimerId');

    expect($timerId)->toBeInt()
        ->and(\Swoole\Timer::exists($timerId))->toBeTrue();

    driveServer($this->server, 'shutdownGrantSweeper', []);

    expect(\Swoole\Timer::exists($timerId))->toBeFalse();
});

test('a sweep never throws into the Swoole callback that runs it', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);
    expireHeldGrant($fd, $channel);

    // A server whose push fails is the stand-in for anything going wrong inside
    // the sweep, a dead socket, a Redis blip in the presence store. A Timer
    // callback that throws takes the worker's whole tick with it.
    writeLightspeedProperty($this->server, 'swoole', ExplodingSwooleServer::make());

    driveServer($this->server, 'sweepExpiredGrants', []);

    // And it still did the work it could: the grant is gone and the connection
    // is out of the fan-out, even though telling the client failed.
    // An exception escaping the sweep would fail this test where it was thrown,
    // which is the first half of what is being asserted.
    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();
});

/**
 * F2. With the sweeper in place, a lost relay control entry is a DELAY.
 *
 * Nothing here tells this worker about the revocation: the Redis key is written
 * directly, so the local listener never fires and no control entry is
 * published. That is the state a trimmed stream, a Redis blip or a relay that
 * never reconnected leaves a peer worker in.
 */
test('a lost relay control entry is a bounded delay, not a permanent hole', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [$tag]);

    Redis::connection('default')->setex(
        'lightspeed:revoked:'.$tag,
        60,
        (string) app(RevocationLog::class)->now(),
    );

    // SENDING is refused immediately, by the authoritative read, with nothing
    // having been delivered over the relay.
    sendClientEvent($this->server, $this->swoole, $fd, $channel);
    expect(GrantSpyHandler::$ran)->toBe(0);

    // RECEIVING continues for now, this is the window the grant lifetime
    // bounds, and it is the honest cost of a lost control entry.
    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(1);

    // And it is bounded: when the grant runs out of lifetime the sweeper drops
    // the connection even though nothing ever told this worker anything.
    expireHeldGrant($fd, $channel);
    driveServer($this->server, 'sweepExpiredGrants', []);

    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0);
});

// ===========================================================================
// F3. A Redis outage must not open the receive direction.
// ===========================================================================

/**
 * The subscribe path makes no Redis calls at all, which is what makes the
 * opt-in claim true. The consequence, proven live: while Redis is down an
 * already-connected socket can replay a still-unexpired auth string, land in
 * the fan-out of a protected channel, and then be neither revocable (the
 * revocation cannot be read) nor sweepable (the grant is not expired).
 *
 * So a subscribe that CARRIES A GRANT checks revocation too. That is one MGET
 * on granted subscribes only, which preserves the opt-in property exactly.
 */
test('TRIPWIRE: a granted auth string is refused at subscribe once its tag is revoked', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    app(ChannelManager::class)->connect($fd, $socketId, []);

    // One auth string, held by the client, still well inside its lifetime.
    $auth = grantTestAuth($socketId, $channel, [$tag]);

    // Revoked with nothing told to this worker: no relay entry, no local
    // listener, exactly as during an outage or after a lost control entry.
    Redis::connection('default')->setex(
        'lightspeed:revoked:'.$tag,
        60,
        (string) app(RevocationLog::class)->now(),
    );

    $this->swoole->pushed = [];
    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => $auth,
    ]]);

    // Refused, and. The part that matters. Not in the fan-out.
    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0)
        ->and(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull()
        ->and(frameEvents($this->swoole))->toContain('pusher_internal:subscription_error');
});

test('TRIPWIRE: with Redis unreachable, a granted subscribe is refused rather than admitted', function () {
    $channel = uniqueChannel();

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    app(ChannelManager::class)->connect($fd, $socketId, []);

    $auth = grantTestAuth($socketId, $channel, [uniqueTag('project')]);

    useUnreachableAuthRedis();

    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => $auth,
    ]]);

    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0);
});

test('a granted subscribe costs exactly one Redis read, and an untagged one still costs none', function () {
    $channel = uniqueChannel();
    $untaggedChannel = uniqueChannel();
    $publicChannel = 'chat.'.bin2hex(random_bytes(4));

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    app(ChannelManager::class)->connect($fd, $socketId, []);

    $grantedAuth = grantTestAuth($socketId, $channel, [uniqueTag('project')]);
    $untaggedAuth = grantTestAuth($socketId, $untaggedChannel);

    $granted = redisCommandsDuring(function () use ($fd, $channel, $grantedAuth) {
        driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
            'channel' => $channel,
            'auth' => $grantedAuth,
        ]]);
    });

    // Counted, not read off the source: one revocation read for the grant, and
    // nothing else.
    expect($granted)->toBe(['mget']);

    // The opt-in property, unchanged: an application that never calls tag()
    // still makes NO Redis call anywhere on the subscribe path, on protected
    // or public channels.
    $untagged = redisCommandsDuring(function () use ($fd, $untaggedChannel, $publicChannel, $untaggedAuth) {
        driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
            'channel' => $untaggedChannel,
            'auth' => $untaggedAuth,
        ]]);

        driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
            'channel' => $publicChannel,
            'auth' => null,
        ]]);
    });

    expect($untagged)->toBe([]);
});

// ===========================================================================
// F5. The revoke race.
// ===========================================================================

/**
 * revoke() read the clock, read the stored value and wrote it back as three
 * separate round trips. Two revokes of the same tag could interleave between
 * the read and the write, and the later one could then store the EARLIER
 * instant, proven, going backwards by 5049us. Which un-revokes every grant
 * issued in between.
 *
 * The interleave here is deterministic rather than hopeful: a second revoke is
 * driven from inside the first one's own Redis command stream, which is exactly
 * the window the race lives in.
 */
test('TRIPWIRE: two interleaved revokes cannot store the earlier instant', function () {
    $tag = uniqueTag('race');
    $log = app(RevocationLog::class);

    $inner = false;
    $innerAt = null;

    enableRedisCommandEvents();

    app('events')->listen(
        \Illuminate\Redis\Events\CommandExecuted::class,
        function ($event) use (&$inner, &$innerAt, $log, $tag) {
            if ($inner || !in_array($event->command, ['get', 'eval', 'evalsha'], true)) {
                return;
            }

            // Re-entered from inside the outer revoke, between what it has read
            // and what it is about to write.
            $inner = true;
            $innerAt = $log->revoke($tag);
        },
    );

    $outerAt = $log->revoke($tag);

    expect($inner)->toBeTrue('the interleave never happened, so this proves nothing');

    $stored = (int) Redis::connection('default')->get('lightspeed:revoked:'.$tag);

    // The stored instant never goes backwards.
    expect($stored)->toBe(max($outerAt, $innerAt));

    // And the consequence that made it matter: a grant minted between the two
    // revokes must not survive them.
    $escapee = new Grant([$tag], [], min($outerAt, $innerAt) + 1, $stored + 300_000_000);

    expect($log->isRevoked($escapee))->toBeTrue();

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

test('a revoke still refuses to move backwards over a later value already stored', function () {
    $tag = uniqueTag('race');
    $log = app(RevocationLog::class);

    $future = $log->now() + 5_000_000;
    Redis::connection('default')->setex('lightspeed:revoked:'.$tag, 60, (string) $future);

    $log->revoke($tag);

    expect((int) Redis::connection('default')->get('lightspeed:revoked:'.$tag))->toBe($future);

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

/**
 * THE HIGH-WATER MARK, AND THE WARNING THAT IS NOW THE ONLY THING WATCHING IT.
 *
 * REVOKE_SCRIPT keeps a revocation for `max(max(own dial, mark) * 2, floor)`.
 * The mark is the only thing in the system that ever carried ANOTHER process's
 * `grant_lifetime_seconds`, so with it gone, flushed, evicted under
 * `maxmemory`, or simply never written by this deployment. The retention falls
 * back to a number that provably covers this process's own grants and provably
 * says nothing about a peer's longer ones. The revocation then lapses while the
 * grants it killed are still alive, and the holder replays the identical auth
 * string back into the fan-out.
 *
 * That failure produces a revocation which looks exactly like a correct one, so
 * the script reports whether the mark was there (`:1` / `:0`) and revoke() turns
 * a `:0` into a warning. The boot refusal this used to sit beside was removed in
 * 2f69009 because it was refusing safe configurations; the measurement moved to
 * `lightspeed:doctor`, which reads the mark against real evidence. That makes
 * this warning the only thing that fires at the MOMENT the residual goes live,
 * rather than whenever an operator next runs a diagnostic. So it matters more
 * than it did, not less.
 *
 * NEITHER HALF HAD ANY COVERAGE. The Lua flag could be pinned at `:1`, or at
 * `:0`, and the PHP condition could be inverted or deleted, and the whole suite
 * stayed green. Both halves are asserted here, in both directions.
 */
test('revoking with no grant-lifetime mark to read warns that retention fell back', function () {
    Log::spy();

    $tag = uniqueTag('project');

    // The mark is gone: a flush, an eviction, or a deployment that has not
    // minted a tagged grant yet.
    Redis::connection('default')->del(RevocationLog::HIGH_WATER_KEY);

    app(RevocationLog::class)->revoke($tag);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'high-water mark')
            && ($context['tag'] ?? null) === $tag)
        ->once();

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

test('revoking while the mark is there says nothing', function () {
    // The positive half. Without it the warning could fire on every revoke and
    // every assertion above would still pass, which would make the one line
    // that means "your revocations may be lapsing early" indistinguishable from
    // noise.
    Log::spy();

    $tag = uniqueTag('project');

    // Exactly what /broadcasting/auth does on the mint path.
    app(RevocationLog::class)->noteGrantLifetime(300);

    app(RevocationLog::class)->revoke($tag);

    Log::shouldNotHaveReceived('warning');

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

// ===========================================================================
// F8. The pending grant belongs to the REQUEST, not to a coroutine.
// ===========================================================================

/**
 * The pending slot was keyed on Swoole\Coroutine::getCid(). A channel callback
 * that does its work in a nested coroutine, a concurrent permission lookup, a
 * WaitGroup, anything, wrote the grant into the CHILD's slot, and the
 * broadcaster, running in the parent, found nothing and signed an UNGRANTED
 * auth string for a channel the application had explicitly asked to protect.
 *
 * Silent, and in the direction that grants access.
 */
test('TRIPWIRE: a grant described inside a nested coroutine still reaches the auth string', function () {
    $socketId = '123.456';
    $channel = uniqueChannel();
    $auth = null;

    \Co\run(function () use (&$auth, $socketId, $channel) {
        app(PendingGrants::class)->begin();

        $done = new \Swoole\Coroutine\Channel(1);

        // The application's channel callback, doing its work in a child
        // coroutine and waiting for it, an entirely ordinary shape.
        \Swoole\Coroutine::create(function () use ($done) {
            app(GrantManager::class)->tag(['project:7'])->with(['can_edit' => true]);
            $done->push(true);
        });

        $done->pop();

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

        $auth = $broadcaster->validAuthenticationResponse(
            Request::create('/broadcasting/auth', 'POST', [
                'socket_id' => $socketId,
                'channel_name' => $channel,
            ]),
            true,
        )['auth'];
    });

    // Three fields: the grant the application asked for is in the credential.
    expect(substr_count((string) $auth, ':'))->toBe(2);

    $decision = (new SubscriptionAuthorizer(
        config('lightspeed.reverb_compat.app_key'),
        config('lightspeed.reverb_compat.app_secret'),
    ))->authorize($socketId, $channel, $auth, null);

    expect($decision->grant)->not->toBeNull()
        ->and($decision->grant->tags)->toBe(['project:7']);
});

test('two overlapping channel-auth requests still cannot cross grants', function () {
    $pending = app(PendingGrants::class);
    $seen = [];

    // Two independent top-level coroutines: what two overlapping
    // /broadcasting/auth requests look like inside one worker under
    // enable_coroutine=true. Each yields where a request would yield on its
    // database query, so they genuinely interleave.
    foreach ([['a', ['project:a']], ['b', ['project:b']]] as [$name, $tags]) {
        \Swoole\Coroutine::create(function () use ($pending, $tags, $name, &$seen) {
            $pending->begin();
            app(GrantManager::class)->tag($tags);

            \Swoole\Coroutine::sleep(0.01);

            $seen[$name] = $pending->take()?->tags();
        });
    }

    \Swoole\Event::wait();

    expect($seen)->toBe(['a' => ['project:a'], 'b' => ['project:b']]);
});

// ===========================================================================
// Round four (S1, S3, S5, S7)
// ===========================================================================

/**
 * S1. A revocation must outlive every grant it invalidates.
 *
 * The revocation key's TTL was derived from grantLifetimeSeconds() read AT
 * REVOKE TIME, in whichever process called revoke(), and the comment argued
 * that was safe because any grant it invalidates was issued before it. That
 * only holds if every outstanding grant was minted under the SAME lifetime.
 * Lower the dial. Which the config actively encourages, "Shorter is safer", 
 * or do a rolling deploy where the process serving revoke() has the new value
 * while websocket workers hold grants minted under the old one, and the
 * revocation key expires first.
 *
 * Reproduced end to end: a grant minted at 3600s, the lifetime lowered to 1,
 * revoke() writing a key with a 2 second TTL, the connection correctly dropped,
 * the key lapsing, and the client replaying the IDENTICAL auth string to be
 * readmitted to the fan-out with its client events handled again. Silent, and
 * permanent for the rest of the hour.
 */
test('a revocation outlives grants minted under a longer lifetime than the revoker has', function () {
    $tag = uniqueTag('project');

    // A grant minted while the dial was an hour.
    config()->set('lightspeed.auth.grant_lifetime_seconds', 3600);
    grantTestAuth('123.456', uniqueChannel(), [$tag]);

    // The dial is lowered, or this process is simply the one that already has
    // the new value while the workers holding grants do not.
    config()->set('lightspeed.auth.grant_lifetime_seconds', 1);

    app(RevocationLog::class)->revoke($tag);

    expect((int) Redis::connection('default')->ttl('lightspeed:revoked:'.$tag))
        ->toBeGreaterThan(3600);

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

test('the replay a lapsed revocation allowed is refused for the whole lifetime the grant was minted under', function () {
    $channel = uniqueChannel();
    $tag = uniqueTag('project');

    config()->set('lightspeed.auth.grant_lifetime_seconds', 3600);

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    app(ChannelManager::class)->connect($fd, $socketId, []);

    // The auth string the client holds. It is good for an hour and pusher-js
    // will replay it verbatim on every reconnect.
    $auth = grantTestAuth($socketId, $channel, [$tag]);

    config()->set('lightspeed.auth.grant_lifetime_seconds', 1);
    app(RevocationLog::class)->revoke($tag);

    // The revocation must still be on record for as long as that auth string
    // can be presented. Anything less and the replay below succeeds the moment
    // the key lapses.
    $ttlMicros = ((int) Redis::connection('default')->ttl('lightspeed:revoked:'.$tag)) * 1_000_000;

    $decision = (new SubscriptionAuthorizer(
        config('lightspeed.reverb_compat.app_key'),
        config('lightspeed.reverb_compat.app_secret'),
    ))->authorize($socketId, $channel, $auth, null);

    $remainingLifeMicros = $decision->grant->expiresAt - $decision->grant->issuedAt;

    expect($ttlMicros)->toBeGreaterThanOrEqual($remainingLifeMicros);

    // And the replay itself is refused while it stands.
    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => $auth,
    ]]);

    expect(app(ChannelManager::class)->broadcast($this->swoole, $channel, ['event' => 'x']))->toBe(0);

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

test('a revocation is retained generously even when nothing has ever recorded a lifetime', function () {
    $tag = uniqueTag('project');

    // The state after a Redis flush, an eviction, or the very first revoke a
    // fresh deployment performs: nothing has recorded a lifetime, so the floor
    // is what covers it.
    //
    // The mark has to be deleted rather than assumed absent. It is one global
    // key with an hour's TTL, so an earlier test in the same run leaves one
    // behind, the retention becomes max(dial, mark) * 2, and the floor this
    // test exists to check is never reached. That is exactly how this test
    // passed while the floor was broken.
    Redis::connection('default')->del('lightspeed:grant-lifetime-high-water');

    config()->set('lightspeed.auth.grant_lifetime_seconds', 1);

    app(RevocationLog::class)->revoke($tag);

    expect((int) Redis::connection('default')->ttl('lightspeed:revoked:'.$tag))
        ->toBeGreaterThanOrEqual(3600);

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

/**
 * S7 mutant. The same-microsecond tie-break in isRevoked() is `>=`, not `>`.
 *
 * A revoke landing on the same microsecond tick as a mint is genuinely
 * unorderable, and the refusing answer is the one that costs a re-subscribe
 * rather than a leaked message. Nothing in the suite noticed the operator
 * changing.
 */
test('a revocation on the same microsecond as the mint refuses the grant', function () {
    $tag = uniqueTag('tie');
    $log = app(RevocationLog::class);

    $instant = $log->now();

    Redis::connection('default')->setex('lightspeed:revoked:'.$tag, 60, (string) $instant);

    expect($log->isRevoked(new Grant([$tag], [], $instant, $instant + 60_000_000)))->toBeTrue();

    // One microsecond later is genuinely later, and is allowed. Without this
    // the assertion above would also pass for a log that refused everything.
    expect($log->isRevoked(new Grant([$tag], [], $instant + 1, $instant + 60_000_000)))->toBeFalse();

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

/**
 * S7 mutant. The open handler forgets any grant left on this fd.
 *
 * Swoole reuses fd numbers. A close that failed part way. The close handler is
 * guarded precisely because it can, leaves the grant behind, and the next
 * connection to be handed that fd number inherits it. It is the one piece of
 * per-fd state whose leaking across a reuse GRANTS access rather than merely
 * confusing.
 */
test('the open handler forgets any grant left on a reused fd', function () {
    $channel = uniqueChannel();

    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->not->toBeNull();

    // The close handler never got to clean up, a Redis blip inside it, a
    // worker that was killed between the two halves. And Swoole hands the
    // same fd number to the next client through the door.
    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = $fd;
    $request->header = ['host' => 'localhost'];
    $request->server = [
        'request_uri' => '/app/'.config('lightspeed.reverb_compat.app_key'),
        'remote_addr' => '127.0.0.1',
    ];

    driveServer($this->server, 'handleOpen', [$this->swoole, $request]);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();
});

/**
 * S3. The sweeper's presence cleanup DOES call Redis, and a client that is
 * never told is a client that never re-authorizes.
 *
 * `sweepExpiredGrants()` claimed it "makes no Redis call of its own, which is
 * exactly why it still works during the outage". It calls dropSubscription(),
 * which reaches PresenceStore::leave(), which is a Redis eval. With presence
 * Redis dead the sweep threw, the throw was swallowed, and the `lightspeed:stale`
 * frame was NEVER SENT. So the connection left the fan-out and the client was
 * never told to re-authorize. It just went quiet.
 */
function subscribeGrantedPresence(Server $server, string $channel, array $tags): int
{
    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.".random_int(1000, 999999);
    $member = ['user_id' => "u{$fd}", 'user_info' => []];

    app(ChannelManager::class)->connect($fd, $socketId, []);
    app(ChannelManager::class)->subscribe($fd, $channel, $member);
    app(PresenceStore::class)->join($channel, driveServer($server, 'presenceConnectionId', [$fd]), $member);

    $now = (int) (microtime(true) * 1_000_000);
    app(ConnectionGrants::class)->mint($fd, $channel, new Grant($tags, [], $now, $now + 60_000_000));

    return $fd;
}

test('a sweep whose presence cleanup cannot reach Redis still tells the client to re-authorize', function () {
    $channel = 'presence-doc.'.bin2hex(random_bytes(8));

    $fd = subscribeGrantedPresence($this->server, $channel, [uniqueTag('project')]);
    expireHeldGrant($fd, $channel);

    // Presence Redis is gone. Everything else. The expiry decision, the
    // unsubscribe, the socket, is fine.
    // PresenceStore reads its connection name per call, so this reaches the
    // instance the server is already holding.
    config()->set('lightspeed.presence.redis_connection', 'lightspeed_unreachable');
    Redis::purge('lightspeed_unreachable');

    $this->swoole->pushed = [];
    driveServer($this->server, 'sweepExpiredGrants', []);

    expect(frameEvents($this->swoole))->toContain('lightspeed:stale')
        ->and(lastFrameData($this->swoole)['reason'])->toBe('expired');
});

/**
 * S7 mutant. The per-connection try inside the sweep.
 *
 * Two connections, because with one the outer try absorbs the throw and the
 * inner guard is dead weight to the suite: the pass looks identical either way.
 * With two, deleting the inner guard means the first failure costs the SECOND
 * connection its sweep entirely.
 */
test('one connection whose sweep fails does not cost another connection its sweep', function () {
    $presenceChannel = 'presence-doc.'.bin2hex(random_bytes(8));
    $plainChannel = uniqueChannel();

    // First in the sweep order, and the one that will fail: its cleanup needs
    // the presence store.
    $failing = subscribeGrantedPresence($this->server, $presenceChannel, [uniqueTag('project')]);
    expireHeldGrant($failing, $presenceChannel);

    [$healthy] = connectAndSubscribe($this->server, $this->swoole, $plainChannel, [uniqueTag('project')]);
    expireHeldGrant($healthy, $plainChannel);

    // PresenceStore reads its connection name per call, so this reaches the
    // instance the server is already holding.
    config()->set('lightspeed.presence.redis_connection', 'lightspeed_unreachable');
    Redis::purge('lightspeed_unreachable');

    $this->swoole->pushed = [];
    driveServer($this->server, 'sweepExpiredGrants', []);

    // The second connection was swept: out of the fan-out, grant gone, told.
    expect(app(ConnectionGrants::class)->grantFor($healthy, $plainChannel))->toBeNull()
        ->and(app(ChannelManager::class)->broadcast($this->swoole, $plainChannel, ['event' => 'x']))->toBe(0)
        ->and(count(array_filter(frameEvents($this->swoole), fn ($e) => $e === 'lightspeed:stale')))->toBe(2);
});

/**
 * S5. PendingGrants must be scoped to a REQUEST, not to a top-level coroutine.
 *
 * scope() walked getPcid() to the top-level coroutine and the docblock asserted
 * no other request shares it. That holds only because THIS server makes each
 * request top-level. Under any runtime that runs requests as children of a
 * container coroutine, Swoole\Coroutine\Http\Server, Hyperf, any Co\run()
 * wrapper, including this file's own nested-coroutine test, every request
 * resolves to the SAME scope, and one request signs another's tags. That is the
 * exact cross-request bug this class exists to prevent, in the direction that
 * grants access.
 */
test('TRIPWIRE: two overlapping auth requests inside one container coroutine cannot cross grants', function () {
    $pending = app(PendingGrants::class);
    $seen = [];

    // Co\run() is the container coroutine. Each request below is a CHILD of it,
    // which is what every runtime that owns its own event loop does.
    \Co\run(function () use ($pending, &$seen) {
        $group = new \Swoole\Coroutine\WaitGroup();

        foreach ([['a', ['project:a']], ['b', ['project:b']]] as [$name, $tags]) {
            $group->add();

            \Swoole\Coroutine::create(function () use ($pending, $tags, $name, $group, &$seen) {
                $pending->begin();
                app(GrantManager::class)->tag($tags);

                // Where a request yields on its database query.
                \Swoole\Coroutine::sleep(0.01);

                $seen[$name] = $pending->take()?->tags();
                $group->done();
            });
        }

        $group->wait();
    });

    expect($seen)->toBe(['a' => ['project:a'], 'b' => ['project:b']]);
});

test('a grant described in a nested coroutine still reaches the request that owns it, inside a container', function () {
    $pending = app(PendingGrants::class);
    $seen = null;

    \Co\run(function () use ($pending, &$seen) {
        $group = new \Swoole\Coroutine\WaitGroup();
        $group->add();

        // One request, a child of the container, doing its work in a
        // GRANDCHILD. The shape 221f2d6 fixed, which must survive this fix.
        \Swoole\Coroutine::create(function () use ($pending, $group, &$seen) {
            $pending->begin();

            $done = new \Swoole\Coroutine\Channel(1);

            \Swoole\Coroutine::create(function () use ($done) {
                app(GrantManager::class)->tag(['project:nested']);
                $done->push(true);
            });

            $done->pop();

            $seen = $pending->take()?->tags();
            $group->done();
        });

        $group->wait();
    });

    expect($seen)->toBe(['project:nested']);
});

// ---------------------------------------------------------------------------
// F3. The sweep budget was added to one timer and not its two siblings.
// ---------------------------------------------------------------------------

/**
 * S9/F3. `sweep_max_per_tick` bounds sweepExpiredGrants() and nothing else.
 *
 * Two paths have the identical shape and had no bound at all:
 *
 *   dropConnectionsCarrying()  one PresenceStore::leave() eval per connection,
 *                              run from the relay poll timer AND from inside
 *                              Lightspeed::revoke(). Revoking one popular tag
 *                              is the whole trigger.
 *   sweepPresence()            two Redis calls per presence channel per tick.
 *
 * Measured: 10,000 leave-shaped evals is 1.40s of fully blocked event loop on
 * loopback, and 3-10s over a real network. `worker_num` defaults to 1 and
 * coroutines are off, so that is the entire server answering nothing, no
 * frames, no HTTP, no relay drain, no other sweep.
 *
 * What is left over is not abandoned. A revoke hands its remainder to the grant
 * sweeper, which drains it on its own ticks; the connection is refused on every
 * message it sends in the meantime by the authoritative per-message read, which
 * is the same guarantee a lost relay entry already relies on.
 */
function subscribeManyCarrying(Server $server, RecordingSwooleServer $swoole, string $tag, int $count): array
{
    $fds = [];

    for ($i = 0; $i < $count; $i++) {
        [$fd] = connectAndSubscribe($server, $swoole, uniqueChannel(), [$tag]);
        $fds[] = $fd;
    }

    return $fds;
}

test('revoking a busy tag unwinds no more than the sweep budget in one pass', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 3);

    $tag = uniqueTag('project');
    subscribeManyCarrying($this->server, $this->swoole, $tag, 8);

    $now = grantTestNow();
    expect(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toHaveCount(8);

    driveServer($this->server, 'dropConnectionsCarrying', [$tag, $now]);

    expect(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toHaveCount(5);
});

/**
 * THE DEFERRAL'S OWN EDGE, where `>` and `>=` part company.
 *
 * At exactly the budget there is nothing left over, and `>=` defers anyway: it
 * remembers a tag with no work behind it, logs `'remaining' => 0` at an operator
 * who now has a "deferred" line describing a revocation that completed in full,
 * and buys the sweeper an extra tick of re-querying the grant index for nothing.
 * Every test either side of this one uses a count comfortably above or below the
 * budget, so both readings pass them.
 */
test('a revoke that exactly fills its budget defers nothing', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 4);

    $tag = uniqueTag('project');
    subscribeManyCarrying($this->server, $this->swoole, $tag, 4);

    $now = grantTestNow();
    driveServer($this->server, 'dropConnectionsCarrying', [$tag, $now]);

    expect(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toBe([])
        ->and(readServerProperty($this->server, 'pendingRevocations'))->toBe([]);
});

test('the connections a revoke left over are dropped by the sweeper, not abandoned', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 3);

    $tag = uniqueTag('project');
    subscribeManyCarrying($this->server, $this->swoole, $tag, 8);

    $now = grantTestNow();
    driveServer($this->server, 'dropConnectionsCarrying', [$tag, $now]);

    // More ticks of the sweeper the server already runs, and the backlog is
    // gone. Nothing here waits for a grant to expire.
    //
    // Four ticks and not two, because half of each tick is now reserved for
    // expiry. That is the deliberate cost of not letting a revocation backlog
    // starve the backstop: a revoke storm drains at half the rate it used to,
    // and every connection in it is refused on its own next message by the
    // authoritative per-message read the whole time.
    foreach (range(1, 4) as $ignored) {
        driveServer($this->server, 'runGrantSweep', []);
    }

    expect(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toBe([]);
});

/**
 * A11. Expiry used to get the LEFTOVERS of the tick budget, and a revocation
 * backlog at or above `sweep_max_per_tick` leaves none.
 *
 * `runGrantSweep()` spent `drainPendingRevocations($budget)` first and ran
 * `sweepExpiredGrants()` only `if ($budget > 0)`, so a backlog the size of the
 * budget skipped expiry wholesale, every tick, for as long as the backlog
 * lasted. Revoking one tag with 10k connections at the default 200 per tick and
 * 1000ms interval is about 50 seconds of no expiry sweeping at all, and
 * sustained revocation traffic starves it indefinitely.
 *
 * Expiry is the BACKSTOP that makes a lost relay control entry "a delay, not a
 * hole", it is the one refusal that needs neither the relay nor a frame from
 * the client. So the thing that stands in for the relay must not be
 * switched off by the relay being busy.
 *
 * Each job now gets half the tick, and each inherits whatever the other did not
 * spend, so the common case (nothing to revoke) still gives expiry the whole
 * budget and the tick stays bounded by it either way.
 */
test('a revocation backlog does not starve grant expiry', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 4);

    $tag = uniqueTag('project');

    // A backlog at twice the budget, which is the shape sustained revocation
    // traffic makes and the shape that used to consume every tick whole.
    subscribeManyCarrying($this->server, $this->swoole, $tag, 8);
    $now = grantTestNow();
    driveServer($this->server, 'deferRevocation', [$tag, $now]);

    $expiredChannel = uniqueChannel();
    [$expiredFd] = connectAndSubscribe($this->server, $this->swoole, $expiredChannel, [uniqueTag('other')]);
    expireHeldGrant($expiredFd, $expiredChannel);

    driveServer($this->server, 'runGrantSweep', []);

    expect(app(ConnectionGrants::class)->grantFor($expiredFd, $expiredChannel))->toBeNull();
});

test('one grant sweep tick spends the budget across both of its jobs, not twice over', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 4);

    $tag = uniqueTag('project');

    // Eight carrying the tag, left over by a revoke...
    subscribeManyCarrying($this->server, $this->swoole, $tag, 8);
    $now = grantTestNow();
    driveServer($this->server, 'deferRevocation', [$tag, $now]);

    // ...and one already expired.
    $expiredChannel = uniqueChannel();
    [$expiredFd] = connectAndSubscribe($this->server, $this->swoole, $expiredChannel, [uniqueTag('other')]);
    expireHeldGrant($expiredFd, $expiredChannel);

    driveServer($this->server, 'runGrantSweep', []);

    // Half the budget to revocations, and the expiry that used to be starved
    // out of the tick entirely. Four drops for a budget of four: the ceiling
    // still bounds the whole tick, it is just no longer spent by one job.
    expect(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toHaveCount(6)
        ->and(app(ConnectionGrants::class)->grantFor($expiredFd, $expiredChannel))->toBeNull();
});

/**
 * THE BACKLOG HAS TO EMPTY, and nothing was watching that it did.
 *
 * Every test above asserts what the drain DROPPED, so all of them pass while the
 * tag stays in `$pendingRevocations` forever: `connectionsCarrying()` returns an
 * empty list, the drops are all correct, and the entry is simply never removed.
 * What that costs is not a stale array key. Every tick from then on re-queries
 * the grant index for a tag with nothing left to drop, and the array grows by
 * one entry per revocation for the life of the worker, unbounded memory and
 * unbounded per-tick work, which is exactly what the bounded sweep exists to
 * prevent.
 *
 * Two mutations of `count($carrying) < $allowed` survived the suite:
 *
 *   false      the backlog is never cleared at all.
 *   === 0      the condition the docblock explicitly argues against, which
 *              needs one extra tick per tag to notice, visible only if the
 *              test counts ticks.
 *
 * So this counts ticks. Budget 4 splits into 2 reserved for expiry and 2 for the
 * drain, and the tag is deferred with THREE connections: the first tick spends
 * its whole allowance (2 dropped of 2 allowed, which is not evidence the index
 * is empty), and the second drops the last one and, dropping fewer than it was
 * allowed to, learns there is nothing left.
 */
test('a drained revocation backlog is actually forgotten, on the tick that empties it', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 4);

    $tag = uniqueTag('project');
    subscribeManyCarrying($this->server, $this->swoole, $tag, 3);

    $now = grantTestNow();
    driveServer($this->server, 'deferRevocation', [$tag, $now]);

    // Tick one: two of three dropped, the allowance spent exactly. The tag must
    // still be remembered, or the third connection is stranded.
    driveServer($this->server, 'runGrantSweep', []);

    expect(readServerProperty($this->server, 'pendingRevocations'))->toHaveKey($tag)
        ->and(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toHaveCount(1);

    // Tick two: the last one goes, and the backlog is empty. Not "empty one tick
    // later", not "empty when the worker restarts", now.
    driveServer($this->server, 'runGrantSweep', []);

    expect(app(ConnectionGrants::class)->connectionsCarrying($tag, $now))->toBe([])
        ->and(readServerProperty($this->server, 'pendingRevocations'))->toBe([]);
});

/** Counts how many channels one presence sweep's rotation actually visited. */
class CountingPresenceStore extends PresenceStore
{
    /** @var list<string> channels this store was asked to refresh, in order */
    public array $heartbeats = [];

    public function __construct()
    {
    }

    /** The every-tick marker pass, which the budget below does not bound. */
    public function heartbeatConnections(array $connectionIdsByChannel): void
    {
    }

    public function heartbeatChannel(string $channel): void
    {
        $this->heartbeats[] = $channel;
    }

    public function reapAbandoned(string $channel): array
    {
        return [];
    }
}

/** Subscribe one fresh connection to a presence channel nobody else is on. */
function subscribePresenceMember(): void
{
    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, 'presence-doc.'.bin2hex(random_bytes(8)), [
        'user_id' => "u{$fd}",
        'user_info' => [],
    ]);
}

test('one presence sweep visits no more channels than its budget allows', function () {
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    $store = new CountingPresenceStore();
    $server = grantTestServer($this->swoole, ['presenceStore' => $store]);

    for ($i = 0; $i < 5; $i++) {
        subscribePresenceMember();
    }

    driveServer($server, 'sweepPresence', []);

    expect($store->heartbeats)->toHaveCount(2);
});

test('a presence sweep resumes where the last one stopped, so no channel is starved', function () {
    config()->set('lightspeed.presence.sweep_max_channels_per_tick', 2);

    $store = new CountingPresenceStore();
    $server = grantTestServer($this->swoole, ['presenceStore' => $store]);

    for ($i = 0; $i < 5; $i++) {
        subscribePresenceMember();
    }

    // Three ticks at two channels each covers five channels once over. A sweep
    // that always restarted at the beginning would visit the first two, three
    // times, and never reach the rest. Which is worse than no bound at all,
    // because the starved channel's ghosts are permanent.
    driveServer($server, 'sweepPresence', []);
    driveServer($server, 'sweepPresence', []);
    driveServer($server, 'sweepPresence', []);

    expect(array_unique($store->heartbeats))->toHaveCount(5);
});

// ---------------------------------------------------------------------------
// F8. The socket-id shape guard had an exception nothing else in the package has.
// ---------------------------------------------------------------------------

/**
 * F8. `auth()` only refused a NON-EMPTY STRING socket id.
 *
 *   if (is_string($socketId) && $socketId !== '' && !isValidSocketId($socketId))
 *
 * so a missing, empty or non-string one skipped the guard entirely, while
 * SubscriptionAuthorizer::signingString() (:297) and ::authorize() (:170) both
 * refuse unconditionally. docs/authorization.md states the refusal with no
 * exception, and the exception is the kind of asymmetry that only ever gets
 * found once something downstream stops covering for it.
 *
 * Nothing legitimate is lost: `socket_id` is sent by every Pusher client on
 * every /broadcasting/auth request, and a signature computed over an absent one
 * binds the grant to no connection at all.
 */
function authoriseWithSocketId(mixed $socketId): mixed
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

    $parameters = ['channel_name' => uniqueChannel()];

    if ($socketId !== null) {
        $parameters['socket_id'] = $socketId;
    }

    return $broadcaster->auth(Request::create('/broadcasting/auth', 'POST', $parameters));
}

test('a channel-auth request with no socket id at all is refused', function () {
    expect(fn () => authoriseWithSocketId(null))
        ->toThrow(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class, 'Lightspeed refuses a socket id');
});

test('a channel-auth request with an empty socket id is refused', function () {
    expect(fn () => authoriseWithSocketId(''))
        ->toThrow(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class, 'Lightspeed refuses a socket id');
});

test('a channel-auth request with a non-string socket id is refused', function () {
    expect(fn () => authoriseWithSocketId(['123.456']))
        ->toThrow(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class, 'Lightspeed refuses a socket id');
});

test('a channel-auth request with a misshapen socket id is still refused', function () {
    expect(fn () => authoriseWithSocketId('lightspeed.grant.v1:7:123.456'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class, 'Lightspeed refuses a socket id');
});

/**
 * M32. The sweeper's TIMER is what sweeps, and nothing pinned that.
 *
 * The suite called sweepExpiredGrants() directly and asserted that
 * bootGrantSweeper() sets a timer id. Emptying the timer's callback body
 * satisfied both: the method still worked, the id was still set, and the
 * sweeper swept nothing. That is exactly the "a revoked lurker receives
 * forever" failure this sweeper was added for, caught twice on live servers,
 * and the mutant walked straight back into it.
 *
 * So this test runs the real Swoole reactor for a few intervals rather than
 * calling the method the timer is supposed to call. It is the only way to
 * assert that boot wired the timer to the sweep and not to nothing.
 */
test('the sweeper TIMER, not just the sweep method, drops an expired grant', function () {
    config()->set('lightspeed.auth.sweep_interval_ms', 100);

    $channel = uniqueChannel();
    [$fd] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);
    expireHeldGrant($fd, $channel);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->not->toBeNull();

    driveServer($this->server, 'bootGrantSweeper', [$this->swoole]);

    // Three intervals, then take every timer down so the reactor has nothing
    // left to wait on and Event::wait() returns.
    Swoole\Timer::after(350, function (): void {
        Swoole\Timer::clearAll();
    });
    Swoole\Event::wait();

    driveServer($this->server, 'shutdownGrantSweeper', []);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();
});

/**
 * M26. The JSONP refusal must REFUSE.
 *
 * The legacy `callback` transport makes the parent return a Response object
 * rather than the array this needs to re-sign. Returning it unchanged hands the
 * client a valid, UNGRANTED auth string for a channel the application asked to
 * protect, a subscription with no tags, which nothing can revoke and which
 * `$event->auth` reports as null. Its own docblock calls that "precisely the
 * silent fail-open this design exists to make impossible", and the suite let
 * the mutant do it.
 */
test('JSONP channel auth with a grant raises instead of returning an ungranted string', function () {
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
        'socket_id' => '123.456',
        'channel_name' => uniqueChannel(),
        // What makes the parent answer with a JsonResponse.
        'callback' => 'cb',
    ]);

    // Laravel 12 made JSONP opt-in (PusherBroadcaster::$allowJsonp, default
    // off), so with the default the parent returns a plain array and the
    // guard under test is never reached. The guard still matters: Laravel 11
    // has no gate, and a 12 app can opt in. Force the JSONP shape wherever
    // the framework can produce it.
    if (property_exists($broadcaster, 'allowJsonp')) {
        $allowJsonp = new ReflectionProperty($broadcaster, 'allowJsonp');
        $allowJsonp->setValue($broadcaster, true);
    }

    app(PendingGrants::class)->begin();
    app(GrantManager::class)->tag([uniqueTag('project')]);

    expect(fn () => $broadcaster->validAuthenticationResponse($request, true))
        ->toThrow(Illuminate\Broadcasting\BroadcastException::class, 'JSONP');
});

test('a JSONP channel auth with no grant is left alone', function () {
    // The positive half: this refusal is about grants, not about JSONP, and an
    // untagged application must keep whatever behaviour it had.
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
        'socket_id' => '123.456',
        'channel_name' => uniqueChannel(),
        'callback' => 'cb',
    ]);

    app(PendingGrants::class)->begin();

    expect($broadcaster->validAuthenticationResponse($request, true))->not->toBeNull();
});

/**
 * M18. Re-minting AT the current high-water mark must extend the mark's life.
 *
 * The mark's TTL is the lifetime it records, so with `>` instead of `>=` the
 * mark expires while grants of exactly that length are still being issued, 
 * and then a revoke reads no mark and falls back to the floor. It is the same
 * bug one level up: a record that lapses while the thing it describes is alive.
 */
test('minting again at the current high-water mark refreshes it', function () {
    $log = app(RevocationLog::class);
    $mark = 'lightspeed:grant-lifetime-high-water';

    Redis::connection('default')->del($mark);
    $log->noteGrantLifetime(3600);

    // Age it, the way time does.
    Redis::connection('default')->expire($mark, 10);

    $log->noteGrantLifetime(3600);

    expect((int) Redis::connection('default')->ttl($mark))->toBeGreaterThan(10);
});

/**
 * M19. The retention floor is what covers a mark that is not there.
 *
 * The existing test for this was not isolated: the high-water key is one shared
 * key with an hour's TTL, so an earlier test in the same run had already
 * written it and the floor was never the thing being exercised. Deleting it
 * first is what makes the assertion about the floor.
 */
test('a revocation falls back to the floor when the high-water mark is gone', function () {
    $tag = uniqueTag('project');

    Redis::connection('default')->del('lightspeed:grant-lifetime-high-water');
    config()->set('lightspeed.auth.grant_lifetime_seconds', 1);

    app(RevocationLog::class)->revoke($tag);

    expect((int) Redis::connection('default')->ttl('lightspeed:revoked:'.$tag))
        ->toBeGreaterThanOrEqual(3600);

    Redis::connection('default')->del('lightspeed:revoked:'.$tag);
});

/**
 * M21. A revoke whose write did not land must not report success.
 *
 * revoke() is the one call in this feature that cannot fail quietly: the caller
 * has just decided somebody's access is gone, and a return that says it landed
 * when it did not leaves that connection authorized with nobody looking again.
 */
test('a revoke whose write did not land raises instead of reporting success', function () {
    $connection = Mockery::mock();
    $connection->shouldIgnoreMissing();
    $connection->shouldReceive('eval')->andReturn('');

    Redis::shouldReceive('connection')->andReturn($connection);

    expect(fn () => app(RevocationLog::class)->revoke(uniqueTag('project')))
        ->toThrow(RuntimeException::class, 'could not write the revocation');
});

/**
 * M22. A Redis TIME reply that is not two numbers is not a clock reading.
 *
 * Accepting it mints grants stamped at instant 0, which is before every
 * revocation that will ever be written. So the grant is refused forever, or,
 * with the comparison the other way about, is never refused at all. Either way
 * the package would be deciding authorization from a number it made up.
 */
test('a malformed Redis TIME reply raises rather than becoming an instant', function () {
    $connection = Mockery::mock();
    $connection->shouldIgnoreMissing();
    $connection->shouldReceive('time')->andReturn('not-a-time');

    Redis::shouldReceive('connection')->andReturn($connection);

    expect(fn () => app(RevocationLog::class)->now())
        ->toThrow(RuntimeException::class, 'could not read the current time');
});

/**
 * M16. A grant with no tags reads as REVOKED.
 *
 * Nothing can revoke a tagless grant, so a connection holding one claims to be
 * re-checked on every message and never is. The exact shape of the fail-open
 * class this design was rebuilt to remove. Grant::decode() refuses to build one
 * from the wire, so this is defence in depth; it is also one character away
 * from being the whole hole again.
 */
test('a grant carrying no tags reads as revoked', function () {
    $now = grantTestNow();

    expect(app(RevocationLog::class)->isRevoked(new Grant([], [], $now, $now + 60_000_000)))->toBeTrue();
});

/**
 * M39. Revoking an empty tag is a programming error, not a revocation.
 *
 * Without the guard it writes `lightspeed:revoked:`, a key no grant can ever
 * match, because Grant::decode() refuses empty tags. So the call succeeds,
 * returns an instant, tells every peer, and revokes nothing at all. A caller
 * whose tag came out of an empty variable gets silence instead of an error.
 */
test('revoking an empty tag raises rather than revoking nothing', function () {
    // The suite shares one Redis and this key is retained for hours, so a
    // single run of a build WITHOUT the guard leaves it behind and makes every
    // later run of this test pass for the wrong reason. Found doing exactly
    // that, while confirming the mutant dies.
    Redis::connection('default')->del('lightspeed:revoked:');

    expect(fn () => app(RevocationLog::class)->revoke(''))
        ->toThrow(InvalidArgumentException::class);

    expect(Redis::connection('default')->exists('lightspeed:revoked:'))->toBe(0);
});

/**
 * A12. Removing a `Lightspeed::tag()` call silently turns re-checking off.
 *
 * The subscribe handler deliberately forgets the held grant when an auth string
 * arrives without one, and that is correct: an auth string is signed by the
 * application, so a client cannot forge an ungranted one, and leaving the old
 * grant in place would strand the connection behind a grant it can never clear.
 *
 * The consequence is the trap. A deploy that drops a `tag()` call from a
 * channel callback converts every connection on that channel from "re-checked
 * on every message" to "never re-checked". The untagged path, which is the
 * package's own opt-out. And it used to happen with no log line and no metric.
 * The README's headline claim quietly stops being true for that channel and
 * nothing anywhere says so.
 *
 * Throttled per channel prefix, the way reportCallbackFailure() and
 * reportRefusal() are, because the trigger is a deploy and the population is
 * every connection on the channel.
 */
test('a subscribe that drops a held grant warns that the channel is no longer re-checked', function () {
    Log::spy();

    $channel = uniqueChannel();

    // Subscribed WITH a tag: the connection is being re-checked per message.
    [$fd, $socketId] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);
    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->not->toBeNull();

    // The same connection re-subscribes after a deploy that removed tag().
    driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
        'channel' => $channel,
        'auth' => grantTestAuth($socketId, $channel, null),
    ]]);

    expect(app(ConnectionGrants::class)->grantFor($fd, $channel))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'no longer re-checked')
            && ($context['channel'] ?? null) === $channel)
        ->once();
});

test('the same transition on the same channel prefix is not logged twice', function () {
    Log::spy();

    $prefix = 'private-throttled'.random_int(1000, 999999);

    foreach (range(1, 3) as $i) {
        $channel = "{$prefix}.{$i}";
        [$fd, $socketId] = connectAndSubscribe($this->server, $this->swoole, $channel, [uniqueTag('project')]);

        driveServer($this->server, 'handlePusherSubscribe', [$this->swoole, $fd, [
            'channel' => $channel,
            'auth' => grantTestAuth($socketId, $channel, null),
        ]]);
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'no longer re-checked'))
        ->once();
});

test('an untagged channel that never had a grant says nothing', function () {
    // The negative half. An application that has never tagged this channel is
    // the supported opt-out, not a regression, and must stay silent.
    Log::spy();

    $channel = uniqueChannel();
    connectAndSubscribe($this->server, $this->swoole, $channel, null);

    Log::shouldNotHaveReceived('warning');
});
