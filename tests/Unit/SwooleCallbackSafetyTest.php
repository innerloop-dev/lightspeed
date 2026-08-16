<?php

use Illuminate\Support\Facades\Log;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: a dependency that fails underneath a
 * Swoole callback costs the frame, not the process.
 *
 * Swoole gives a callback no exception boundary of its own. Whatever escapes
 * `message`, `close`, `open`, `request` or a `Timer::tick` body takes the
 * worker down with it, and `worker_num` defaults to 1. So one Redis blip on
 * one connection's close handler ended the server for every other connection
 * on it. The handshake path guarded this dependency from the day it was
 * written; nothing else did.
 *
 * These tests do not simulate Redis being down. They make the collaborator on
 * the far side of the boundary throw, which is the same thing from the
 * callback's point of view and is what every one of those code paths does when
 * Redis is unreachable.
 */

/** Records what the server pushed, in place of a real websocket. */
class CallbackSafetySwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

    /** @var list<int> fds this server was asked to disconnect */
    public array $disconnected = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push(int $fd, Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        $this->disconnected[] = $fd;

        return true;
    }
}

/** A presence store whose every Redis call fails, standing in for an outage. */
class UnreachablePresenceStore extends PresenceStore
{
    public function __construct()
    {
    }

    public function join(string $channel, string $connectionId, array $member): array
    {
        throw new \RedisException('Connection lost');
    }

    public function leave(string $channel, string $connectionId): array
    {
        throw new \RedisException('Connection lost');
    }

    public function snapshot(string $channel): array
    {
        throw new \RedisException('Connection lost');
    }
}

/** A bridge whose fan-out fails, standing in for a relay XADD against a dead Redis. */
class UnreachableBroadcastBridge extends BroadcastBridge
{
    public function __construct()
    {
    }

    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        throw new \RedisException('Connection lost');
    }
}

/** A registry whose writes fail, which is what the handshake path already guards. */
class UnreachableConnectionRegistry extends ConnectionRegistry
{
    public function __construct()
    {
    }

    public function remember(string $socketId): void
    {
        throw new \RedisException('Connection lost');
    }

    public function forget(?string $socketId): void
    {
        throw new \RedisException('Connection lost');
    }
}

/**
 * A runtime logger that cannot write.
 *
 * The handshake's own try covers the Redis half of `open`; this covers the
 * rest of the callback, which is everything AFTER that try. The access log
 * line and the connection_established frame.
 */
class UnwritableRuntimeLogger extends RuntimeLogger
{
    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        throw new \RuntimeException('log stack is unwritable');
    }

    public function maybePayloadSnippet(mixed $payload): ?string
    {
        return null;
    }
}

/** Runs the client-event task inline, in the container the test is already in. */
class CallbackSafetyOctaneWorker extends OctaneWorker
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
 * Assemble a Server whose collaborators can be swapped for failing ones.
 *
 * @param array<string, object> $overrides
 */
function callbackSafetyServer(array $overrides = []): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = array_merge([
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
        'httpWorker' => new CallbackSafetyOctaneWorker(),
    ], $overrides);

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    // Builds the websocket surfaces out of the collaborators just injected,
    // exactly as the constructor does after building the Octane worker.
    driveCallback($server, 'compose', []);

    // These tests drive the callbacks directly, so nothing has run the
    // workerStart that a real process would. Say so, or every handshake here is
    // refused by the readiness gate rather than judged on its merits.
    markLightspeedWorkerReady($server);

    return $server;
}

/** Call one of the server's private methods, wherever it lives. */
function driveCallback(Server $server, string $method, array $arguments): mixed
{
    return driveLightspeed($server, $method, $arguments);
}

function callbackSafetyFrame(int $fd, array $payload): Frame
{
    $frame = new Frame();
    $frame->fd = $fd;
    $frame->opcode = WEBSOCKET_OPCODE_TEXT;
    $frame->data = json_encode($payload);

    return $frame;
}

test('a presence store that cannot reach Redis does not kill the worker on close', function () {
    // The named bug: Server's close handler called PresenceStore::leave(),
    // ConnectionRegistry::forget() and a member_removed fan-out with no try
    // anywhere between them and the Swoole boundary.
    $swoole = CallbackSafetySwooleServer::make();
    $server = callbackSafetyServer(['presenceStore' => new UnreachablePresenceStore()]);

    $fd = random_int(1000, 999999);
    $channel = 'presence-callback-safety-'.bin2hex(random_bytes(6));

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => '1', 'user_info' => []]);

    driveCallback($server, 'handleClose', [$swoole, $fd]);

    // Surviving is necessary and is not the whole job. A close handler that
    // caught the failure and then gave up would leave the fd in this worker's
    // subscriber list forever, so every later broadcast on the channel would
    // push to a dead socket. The Redis-backed presence membership is what could
    // not be cleaned up; the local state is what still had to be.
    expect(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeFalse()
        ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([])
        ->and(app(ChannelManager::class)->socketIdFor($fd))->toBeNull();
});

test('a relay that cannot reach Redis does not kill the worker on a client event', function () {
    // The named bug's other half: the fan-out at the end of the client-event
    // path is OUTSIDE the try that guards the application handler, so a failed
    // XADD escaped straight through the message callback.
    $swoole = CallbackSafetySwooleServer::make();
    $server = callbackSafetyServer(['broadcastBridge' => new UnreachableBroadcastBridge()]);

    $fd = random_int(1000, 999999);
    $channel = 'private-callback-safety-'.bin2hex(random_bytes(6));

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel);

    driveCallback($server, 'handleMessage', [$swoole, callbackSafetyFrame($fd, [
        'event' => 'client-typing',
        'channel' => $channel,
        'data' => ['message' => 'hello'],
    ])]);

    // Told, not silently dropped: a client that hears nothing cannot tell a
    // lost frame from a slow one.
    expect(array_column($swoole->pushed, 'event'))->toContain('pusher:error');
});

test('a dependency that fails outside the handshake try does not kill the worker on open', function () {
    // Not the registry: that sits inside the handshake's own try, which has
    // guarded it since it was written. This fails in the access log line
    // AFTER it, which is the part of the open callback that had no guard.
    $swoole = CallbackSafetySwooleServer::make();
    $server = callbackSafetyServer(['runtimeLogger' => new UnwritableRuntimeLogger()]);

    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = random_int(1000, 999999);
    $request->header = ['host' => 'localhost'];
    $request->server = [
        'request_uri' => '/app/'.config('lightspeed.reverb_compat.app_key'),
        'remote_addr' => '127.0.0.1',
    ];

    driveCallback($server, 'handleOpen', [$swoole, $request]);

    expect($swoole->disconnected)->toContain($request->fd);
});

test('a log stack that cannot write does not kill the worker on a relay poll', function () {
    // The relay guards its stream read and each delivery, but the very first
    // thing a read failure does is Log::warning(). So an unwritable log
    // channel turned a Redis outage into a dead server.
    config()->set('lightspeed.relay.redis_connection', 'lightspeed-callback-safety-missing');

    Log::swap(new class {
        public function __call($method, $arguments)
        {
            throw new \RuntimeException('log stack is unwritable');
        }
    });

    $relay = app(RedisRelay::class);

    $attached = new ReflectionProperty(RedisRelay::class, 'server');
    $attached->setAccessible(true);
    $attached->setValue($relay, CallbackSafetySwooleServer::make());

    $tick = new ReflectionMethod(RedisRelay::class, 'tick');
    $tick->setAccessible(true);
    $tick->invoke($relay);

    // The point is that the worker keeps polling. A tick that survived by
    // detaching, or by clearing its own timer, would be just as quiet and would
    // never deliver another broadcast, presence event or revocation. So the
    // relay must still be attached, and a second tick must still be harmless.
    expect($relay->hasLocalServer())->toBeTrue();

    $tick->invoke($relay);

    expect($relay->hasLocalServer())->toBeTrue();
});

test('a log stack that cannot write does not kill the worker on an owner command poll', function () {
    config()->set('lightspeed.owner_commands.redis_connection', 'lightspeed-callback-safety-missing');

    Log::swap(new class {
        public function __call($method, $arguments)
        {
            throw new \RuntimeException('log stack is unwritable');
        }
    });

    $bus = app(OwnerCommandBus::class);

    $attached = new ReflectionProperty(OwnerCommandBus::class, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, CallbackSafetySwooleServer::make());

    $tick = new ReflectionMethod(OwnerCommandBus::class, 'tick');
    $tick->setAccessible(true);
    $tick->invoke($bus);

    // Same reasoning as the relay poll above: still attached, and still
    // pollable. `shutdownWorker()` nulls this property, so a tick that took
    // itself out of service would show up here.
    expect($attached->getValue($bus))->not->toBeNull();

    $tick->invoke($bus);

    expect($attached->getValue($bus))->not->toBeNull();
});
