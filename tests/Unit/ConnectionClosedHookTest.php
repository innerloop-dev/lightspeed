<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Connections\ConnectionClosed;
use Lightspeed\Connections\ConnectionClosedDispatcher;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Contracts\ConnectionClosedHandler;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Http\SwooleClient;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\Teardown;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Server;
use Lightspeed\Workers\WorkerContext;

/**
 * The claim this file defends: when a connection goes, the application's own
 * PHP is told, and being told is worth nothing unless it survives the ways
 * telling can go wrong.
 *
 * The halves, and each has been the whole feature at some point:
 *
 *   it fires at all, on the real close path, carrying what the connection was;
 *   it carries the GRANTS, which the teardown drops before it works out what it
 *     is tearing down, so the read has to happen first or the field is empty,
 *     and it carries them per channel so no client can choose which one the
 *     application is shown;
 *   it fires for authorized connections only, so anyone with the public app key
 *     cannot reach application code by connecting and hanging up;
 *   it runs in an Octane sandbox, out of the application the WORKER booted,
 *     rather than in whatever container the close happened to interrupt;
 *   a handler that throws is a logged line rather than a dead worker, a
 *     half-torn-down connection, or a reason for the next handler not to run;
 *   the sweep's telling is bounded per tick, because one reaped channel can
 *     hold any number of members;
 *   an application that registered nothing is not made to pay for any of it.
 *
 * Real Redis, real presence rows and the real composed server wiring, because
 * every one of those is a place the hook can be connected wrongly and still
 * look connected.
 */

/** Records the events it is handed. */
class ConnectionClosedRecorder implements ConnectionClosedHandler
{
    /** @var list<ConnectionClosed> */
    public static array $events = [];

    public function connectionClosed(ConnectionClosed $event): void
    {
        self::$events[] = $event;
    }
}

/** The application handler that fails, which is the interesting one. */
class ConnectionClosedThrowingHandler implements ConnectionClosedHandler
{
    public static int $calls = 0;

    public function connectionClosed(ConnectionClosed $event): void
    {
        self::$calls++;

        throw new \RuntimeException('the application handler exploded');
    }
}

class ConnectionClosedNotAHandler
{
}

/** Captures the runtime log instead of writing it to stdout. */
class ConnectionClosedLogger extends RuntimeLogger
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
}

/** Counts the reads the close path makes for the hook's benefit. */
class ConnectionClosedCountingGrants extends ConnectionGrants
{
    public int $reads = 0;

    public function grantsFor(int $fd): array
    {
        $this->reads++;

        return parent::grantsFor($fd);
    }
}

/** Records the fan-out instead of publishing it. */
class ConnectionClosedBridge extends BroadcastBridge
{
    /** @var list<array{channel: string, event: string, payload: mixed}> */
    public array $fannedOut = [];

    public function __construct()
    {
    }

    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        foreach ($channels as $channel) {
            $this->fannedOut[] = ['channel' => $channel, 'event' => $event, 'payload' => $payload];
        }

        return count($channels);
    }
}

function connectionClosedChannel(): string
{
    return 'presence-lightspeed-closed-'.bin2hex(random_bytes(6));
}

/**
 * A booted HTTP worker whose tasks run in a REAL Octane sandbox.
 *
 * Not a stub that calls the closure inline, because inline is precisely the
 * bug: the point of running handlers through the worker is that Octane clones
 * the application, makes the clone current for the task, and throws it away
 * afterwards. A fake that skipped that would make every assertion below about
 * sandboxing pass against code that does not sandbox anything.
 *
 * `Laravel\Octane\Worker` is built without its constructor and handed the
 * application the test is already running in, which is what its own boot()
 * would have produced: handleTask() only ever touches that property.
 */
function connectionClosedWorker(?object $base = null): OctaneWorker
{
    $octane = (new ReflectionClass(\Laravel\Octane\Worker::class))->newInstanceWithoutConstructor();

    $application = new ReflectionProperty(\Laravel\Octane\Worker::class, 'app');
    $application->setAccessible(true);
    $application->setValue($octane, $base ?? app());

    $worker = (new ReflectionClass(OctaneWorker::class))->newInstanceWithoutConstructor();

    foreach (['worker' => $octane, 'client' => new SwooleClient()] as $name => $value) {
        $property = new ReflectionProperty(OctaneWorker::class, $name);
        $property->setAccessible(true);
        $property->setValue($worker, $value);
    }

    return $worker;
}

/**
 * A real sandbox-running worker that can be taken away and given back.
 *
 * What a worker looks like before the application has bootstrapped and after it
 * has been terminated, which a presence timer can genuinely tick into: the
 * tasks it CAN run are real ones, and the window where it cannot is the whole
 * point of the test that uses it.
 */
final class ConnectionClosedFlakyWorker extends OctaneWorker
{
    public bool $available = true;

    public function __construct(private readonly OctaneWorker $inner)
    {
    }

    public function isBooted(): bool
    {
        return $this->available && $this->inner->isBooted();
    }

    public function runTask(callable $task): mixed
    {
        return $this->inner->runTask($task);
    }
}

/** The dispatcher, wired to a sandbox-running worker. */
function connectionClosedDispatcher(?RuntimeLogger $logger = null, ?OctaneWorker $worker = null): ConnectionClosedDispatcher
{
    return new ConnectionClosedDispatcher(
        $worker ?? connectionClosedWorker(),
        $logger ?? app(RuntimeLogger::class),
    );
}

/** The composed server, wired exactly as `lightspeed:serve` wires it. */
function connectionClosedServer(BroadcastBridge $bridge, array $overrides = []): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = array_merge([
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => $bridge,
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => connectionClosedWorker(),
    ], $overrides);

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose');

    $swoole = (new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor();
    writeLightspeedProperty($server, 'swoole', $swoole);

    return $server;
}

function connectionClosedSwoole(): \Swoole\WebSocket\Server
{
    return (new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor();
}

/** A channel manager whose close result is written by the test. */
class ConnectionClosedChannels extends ChannelManager
{
    public function __construct(private readonly array $result)
    {
    }

    public function disconnect(int $fd): array
    {
        return $this->result;
    }
}

/** A presence store that always says the departure is worth announcing. */
class ConnectionClosedPresence extends PresenceStore
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function leave(string $channel, string $connectionId): array
    {
        return ['broadcast_member_removed' => true, 'user_id' => null];
    }
}

/** Records the member_removed frames instead of putting them on a wire. */
class ConnectionClosedDelivery extends Delivery
{
    /** @var list<array{channel: string, event: string, data: mixed}> */
    public array $broadcasts = [];

    public function __construct()
    {
    }

    public function broadcastPusherEvent(string $channel, string $event, mixed $data, ?int $exceptFd = null): int
    {
        $this->broadcasts[] = ['channel' => $channel, 'event' => $event, 'data' => $data];

        return 1;
    }
}

/** A teardown built from the real collaborators, with the pieces a test steers. */
function connectionClosedTeardown(array $overrides = []): Teardown
{
    return new Teardown(
        $overrides['channels'] ?? app(ChannelManager::class),
        $overrides['grants'] ?? app(ConnectionGrants::class),
        app(ConnectionRegistry::class),
        $overrides['presence'] ?? app(PresenceStore::class),
        app(DiagnosticSockets::class),
        app(ConnectionId::class),
        $overrides['delivery'] ?? app(Delivery::class),
        $overrides['logger'] ?? app(RuntimeLogger::class),
        $overrides['dispatcher'] ?? connectionClosedDispatcher(),
    );
}

beforeEach(function () {
    ConnectionClosedRecorder::$events = [];
    ConnectionClosedThrowingHandler::$calls = 0;
});

afterEach(function () {
    foreach ((array) Redis::connection()->keys('*lightspeed:presence:channel:presence-lightspeed-closed-*') as $key) {
        $prefix = (string) config('database.redis.options.prefix');
        Redis::connection()->del(
            $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key,
        );
    }
});

test('the application is told when a connection closes, and told what it was', function () {
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $channel = connectionClosedChannel();
    $bridge = new ConnectionClosedBridge();
    $server = connectionClosedServer($bridge);

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";

    app(ChannelManager::class)->connect($fd, $socketId, []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']]);
    app(PresenceStore::class)->join(
        $channel,
        app(ConnectionId::class)->presenceConnectionId($fd, $socketId),
        ['user_id' => 'ada', 'user_info' => ['name' => 'Ada']],
    );

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1);

    $event = ConnectionClosedRecorder::$events[0];

    expect($event->reason)->toBe('closed')
        ->and($event->socketId)->toBe($socketId)
        ->and($event->channels)->toBe([$channel])
        ->and($event->presenceLeaves)->toBe([[
            'channel' => $channel,
            'user_id' => 'ada',
            'user_info' => ['name' => 'Ada'],
        ]]);
});

test('the grant the connection was carrying reaches the handler', function () {
    // The ordering hazard this feature is built around: the teardown forgets
    // the connection's grants at the TOP, before it works out what the
    // connection was, so a hook that reads them at the point it fires reads an
    // empty table and reports a connection that carried nothing. Everything an
    // application would act on here (whose connection was it, what had it been
    // approved for) is in exactly that field.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $bridge = new ConnectionClosedBridge();
    $server = connectionClosedServer($bridge);

    $fd = random_int(1000, 999999);
    $first = 'private-closed-first-'.bin2hex(random_bytes(4));
    $second = 'private-closed-second-'.bin2hex(random_bytes(4));
    $now = (int) (microtime(true) * 1_000_000);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $first);
    app(ChannelManager::class)->subscribe($fd, $second);

    app(ConnectionGrants::class)->mint($fd, $first, new Grant(
        ['user:42', 'project:7'],
        ['can_edit' => true],
        $now,
        $now + 60_000_000,
    ));

    app(ConnectionGrants::class)->mint($fd, $second, new Grant(
        ['user:42', 'doc:9'],
        ['can_edit' => false],
        $now,
        $now + 60_000_000,
    ));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    $event = ConnectionClosedRecorder::$events[0];

    // A tag carried on two channels is one tag, and `user:42` is the whole
    // reason an application can match this event to anything it holds.
    //
    // The union across the connection's channels, deduplicated and SORTED: the
    // insertion order was the client's to shuffle, and no application needs an
    // order in a list whose entries are only ever compared for equality.
    expect($event->tags)->toBe(['doc:9', 'project:7', 'user:42'])
        // Every payload, addressed by the channel it was signed for. Not one
        // payload chosen from several: merging would invent one the application
        // never signed, and choosing made the answer the client's to pick.
        ->and($event->authPayloads)->toBe([
            $first => ['can_edit' => true],
            $second => ['can_edit' => false],
        ]);
});

test('the payload map does not move when the client re-subscribes', function () {
    // THE ORDER WAS THE CLIENT'S TO CHOOSE. ConnectionGrants::mint() forgets and
    // reassigns, so a re-subscribe moves that channel to the END of the grant
    // table. While the event carried "the first channel's payload", a client
    // could decide which of its own payloads the application would be shown by
    // deciding what to re-subscribe to. A map keyed by channel has no first.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $server = connectionClosedServer(new ConnectionClosedBridge());

    $fd = random_int(1000, 999999);
    $first = 'private-order-first-'.bin2hex(random_bytes(4));
    $second = 'private-order-second-'.bin2hex(random_bytes(4));
    $now = (int) (microtime(true) * 1_000_000);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $first);
    app(ChannelManager::class)->subscribe($fd, $second);

    // Non-empty tags, because `tags` is reordered by the very same move and an
    // empty list cannot show it: the grant table is what both fields are built
    // from, so a test with no tags proves nothing about half of them.
    app(ConnectionGrants::class)->mint($fd, $first, new Grant(['user:42', 'alpha'], ['seat' => 'first'], $now, $now + 60_000_000));
    app(ConnectionGrants::class)->mint($fd, $second, new Grant(['user:42', 'zeta'], ['seat' => 'second'], $now, $now + 60_000_000));

    // The move: the first channel is granted again, which puts it last.
    app(ConnectionGrants::class)->mint($fd, $first, new Grant(['user:42', 'alpha'], ['seat' => 'first'], $now, $now + 60_000_000));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    $event = ConnectionClosedRecorder::$events[0];

    expect($event->authPayloads[$first])->toBe(['seat' => 'first'])
        ->and($event->authPayloads[$second])->toBe(['seat' => 'second'])
        ->and($event->authPayloads)->toHaveCount(2)
        // Sorted, so the re-mint that moved `alpha`'s channel to the end of the
        // grant table did not move `alpha` in what the application is handed.
        ->and($event->tags)->toBe(['alpha', 'user:42', 'zeta']);

    // And the same connection WITHOUT the re-mint reports the identical list,
    // which is the claim: the order is the connection's, not the client's.
    ConnectionClosedRecorder::$events = [];

    $untouched = random_int(1000, 999999);
    app(ChannelManager::class)->connect($untouched, "{$untouched}.1", []);
    app(ChannelManager::class)->subscribe($untouched, $first);
    app(ChannelManager::class)->subscribe($untouched, $second);
    app(ConnectionGrants::class)->mint($untouched, $first, new Grant(['user:42', 'alpha'], ['seat' => 'first'], $now, $now + 60_000_000));
    app(ConnectionGrants::class)->mint($untouched, $second, new Grant(['user:42', 'zeta'], ['seat' => 'second'], $now, $now + 60_000_000));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $untouched]);

    expect(ConnectionClosedRecorder::$events[0]->tags)->toBe(['alpha', 'user:42', 'zeta']);
});

test('a connection the application never tagged arrives with no tags and no payloads', function () {
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $server = connectionClosedServer(new ConnectionClosedBridge());
    $fd = random_int(1000, 999999);

    // A channel, because a connection holding neither a channel nor a grant
    // never reaches application code at all. See the anonymous-close test.
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, 'untagged-'.bin2hex(random_bytes(4)));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    // An empty map is "the application never tagged this connection", which is
    // the same thing `ClientEvent::$auth` means by null. It is never "no
    // restrictions", and a missing channel key means the same as an empty map.
    expect(ConnectionClosedRecorder::$events[0]->tags)->toBe([])
        ->and(ConnectionClosedRecorder::$events[0]->authPayloads)->toBe([]);
});

test('a connection that held neither a channel nor a grant tells the application nothing', function () {
    // Anyone holding the PUBLIC app key can open a socket, complete the
    // handshake and hang up. Nothing about that connection was ever authorized
    // by the application, and in a loop it was a lever on the event loop that
    // serves every other connection: one open-and-close, one entry into
    // application code. The package handles those closes on its own, exactly as
    // it did before this hook existed.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $server = connectionClosedServer(new ConnectionClosedBridge());
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    expect(ConnectionClosedRecorder::$events)->toBe([]);

    // And the positive control on the same gate: one granted channel, with no
    // subscription of its own, is enough. Without this the assertion above is
    // satisfied by a hook that never fires.
    $granted = random_int(1000, 999999);
    $now = (int) (microtime(true) * 1_000_000);

    app(ChannelManager::class)->connect($granted, "{$granted}.1", []);
    app(ConnectionGrants::class)->mint($granted, 'private-gate-'.bin2hex(random_bytes(4)), new Grant(
        ['user:42'],
        ['can_edit' => true],
        $now,
        $now + 60_000_000,
    ));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $granted]);

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1)
        ->and(ConnectionClosedRecorder::$events[0]->tags)->toBe(['user:42']);
});

test('a handler that throws is logged, and costs neither the teardown nor the handlers after it', function () {
    config()->set('lightspeed.connection_closed_handlers', [
        ConnectionClosedThrowingHandler::class,
        ConnectionClosedRecorder::class,
    ]);

    $channel = connectionClosedChannel();
    $logger = ConnectionClosedLogger::make();
    $server = connectionClosedServer(new ConnectionClosedBridge(), ['runtimeLogger' => $logger]);

    $fd = random_int(1000, 999999);
    $socketId = "{$fd}.1";
    $connectionId = app(ConnectionId::class)->presenceConnectionId($fd, $socketId);

    app(ChannelManager::class)->connect($fd, $socketId, []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join($channel, $connectionId, ['user_id' => 'ada', 'user_info' => []]);
    app(ConnectionRegistry::class)->remember($socketId);

    // Not wrapped in a try: an exception escaping here is the Swoole close
    // callback ending the worker that serves every other connection.
    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    expect(ConnectionClosedThrowingHandler::$calls)->toBe(1)
        // A notification has no result, so one handler's failure is not an
        // answer on behalf of the rest: the handler AFTER it still ran.
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(1);

    // The connection still left everything it was in. A hook that could hold a
    // socket in presence or in the registry by throwing would be worse than no
    // hook at all.
    expect(app(PresenceStore::class)->snapshot($channel)['count'])->toBe(0)
        ->and(app(ConnectionRegistry::class)->metadata($socketId))->toBeNull()
        ->and(app(ChannelManager::class)->socketIdFor($fd))->toBeNull()
        ->and(app(ChannelManager::class)->channelsFor($fd))->toBe([]);

    $failures = array_values(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'connection-closed-handler-failed',
    ));

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['action'])->toBe('error')
        ->and($failures[0]['fields']['handler'])->toBe(ConnectionClosedThrowingHandler::class)
        ->and($failures[0]['fields']['message'])->toBe('the application handler exploded');
});

test('a handler class that cannot be used is logged and stepped over', function () {
    // The typo, and the class that says it handles closes without implementing
    // the contract. `Server::serve()` refuses to start on both, but a config
    // changed under a running server reaches this path instead, where the only
    // safe answer is to skip it: this is a Swoole callback.
    config()->set('lightspeed.connection_closed_handlers', [
        'App\\Realtime\\NoSuchHandler',
        ConnectionClosedNotAHandler::class,
        ConnectionClosedRecorder::class,
    ]);

    $logger = ConnectionClosedLogger::make();
    $server = connectionClosedServer(new ConnectionClosedBridge(), ['runtimeLogger' => $logger]);

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, 'invalid-handlers-'.bin2hex(random_bytes(4)));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $fd]);

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1);

    $invalid = array_values(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'connection-closed-handler-invalid',
    ));

    expect($invalid)->toHaveCount(2)
        ->and($invalid[0]['fields']['handler'])->toBe('App\\Realtime\\NoSuchHandler')
        ->and($invalid[1]['fields']['handler'])->toBe(ConnectionClosedNotAHandler::class);
});

test('a member with another tab open has not left, and is not reported as leaving', function () {
    // presenceLeaves is the memberships that ENDED, which is the same rule
    // `pusher_internal:member_removed` follows. A second tab still on the
    // channel means this user is still there, and an application acting on a
    // leave that did not happen would drop a lock its user still holds.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $channel = connectionClosedChannel();
    $server = connectionClosedServer(new ConnectionClosedBridge());

    $first = random_int(1000, 999999);
    $second = $first + 1;

    foreach ([$first, $second] as $fd) {
        app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
        app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
        app(PresenceStore::class)->join(
            $channel,
            app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
            ['user_id' => 'ada', 'user_info' => []],
        );
    }

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $first]);

    expect(ConnectionClosedRecorder::$events[0]->channels)->toBe([$channel])
        ->and(ConnectionClosedRecorder::$events[0]->presenceLeaves)->toBe([]);

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $second]);

    expect(ConnectionClosedRecorder::$events[1]->presenceLeaves)->toBe([[
        'channel' => $channel,
        'user_id' => 'ada',
        'user_info' => [],
    ]]);
});

test('the sweeper reports a reaped member as swept, and claims nothing it cannot know', function () {
    // The other half of the correctness bound, and the only telling there will
    // ever be for a connection whose worker was killed: no teardown ran, so
    // there is no socket id, no channel list and no grant anywhere in the fleet
    // to put in the event.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $channel = connectionClosedChannel();
    $bridge = new ConnectionClosedBridge();
    $server = connectionClosedServer($bridge);

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);

    $connectionId = app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1");
    app(PresenceStore::class)->join($channel, $connectionId, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join($channel, 'dead-worker:socket:9.9', ['user_id' => 'grace', 'user_info' => []]);

    // The marker lapsing is what a killed worker looks like from Redis.
    Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:9.9");

    expect(driveLightspeed($server, 'sweepPresence'))->toBe(1);

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1);

    $event = ConnectionClosedRecorder::$events[0];

    expect($event->reason)->toBe('swept')
        ->and($event->socketId)->toBeNull()
        ->and($event->tags)->toBe([])
        ->and($event->authPayloads)->toBe([])
        ->and($event->channels)->toBe([$channel])
        ->and($event->presenceLeaves)->toBe([[
            'channel' => $channel,
            'user_id' => 'grace',
            'user_info' => null,
        ]]);
});

test('a handler that throws inside the sweep does not take the worker with it', function () {
    // The sweep is a Swoole timer callback with `enable_coroutine` off, which
    // is the one place in the package where an escaping exception has no
    // coroutine to contain it and no frame to be caught in. Application code
    // runs there now, so it is wrapped there.
    config()->set('lightspeed.connection_closed_handlers', [
        ConnectionClosedThrowingHandler::class,
        ConnectionClosedRecorder::class,
    ]);

    $channel = connectionClosedChannel();
    $logger = ConnectionClosedLogger::make();
    $bridge = new ConnectionClosedBridge();
    $server = connectionClosedServer($bridge, ['runtimeLogger' => $logger]);

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);

    app(PresenceStore::class)->join(
        $channel,
        app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
        ['user_id' => 'ada', 'user_info' => []],
    );
    app(PresenceStore::class)->join($channel, 'dead-worker:socket:9.9', ['user_id' => 'grace', 'user_info' => []]);
    Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:9.9");

    // The sweep still reports its work, the reap still happened, and the
    // channel was still told.
    expect(driveLightspeed($server, 'sweepPresence'))->toBe(1)
        ->and(ConnectionClosedThrowingHandler::$calls)->toBe(1)
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(1)
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada'])
        ->and($bridge->fannedOut)->toHaveCount(1);
});

test('an application that registers no handler pays nothing on a close', function () {
    // The opt-in, stated as a cost rather than as an intention: with the list
    // empty the close path does not read the connection's grants, does not
    // build an event, and does not resolve anything from the container.
    config()->set('lightspeed.connection_closed_handlers', []);

    $grants = new ConnectionClosedCountingGrants();
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, 'cost-'.bin2hex(random_bytes(4)));

    connectionClosedTeardown(['grants' => $grants])
        ->closeConnection(connectionClosedSwoole(), $fd);

    expect($grants->reads)->toBe(0);

    // And the same close with one handler registered does read them, so the
    // zero above is a measurement rather than a method nobody calls. How MANY
    // times is not pinned: that is the close path's business, and the claim
    // here is only that an unconfigured application is not asked at all.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $again = random_int(1000, 999999);
    app(ChannelManager::class)->connect($again, "{$again}.1", []);
    app(ChannelManager::class)->subscribe($again, 'cost-'.bin2hex(random_bytes(4)));

    connectionClosedTeardown(['grants' => $grants])
        ->closeConnection(connectionClosedSwoole(), $again);

    expect($grants->reads)->toBeGreaterThan(0)
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(1);
});

test('an fd that was never a connection tells the application nothing', function () {
    // Swoole runs the close callback for anything that reached the port,
    // including a TCP scan that opened a socket and hung up. An application
    // handler firing for those is a lie about a connection that never existed,
    // and the same rule already governs the close log line.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $server = connectionClosedServer(new ConnectionClosedBridge());

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), random_int(1000, 999999)]);

    expect(ConnectionClosedRecorder::$events)->toBe([]);
});

test('the server refuses to start on a connection-closed handler that cannot work', function () {
    // The same fail-fast the other two handler lists get. Without it a typo is
    // invisible until a connection closes in production, and the answer there
    // is deliberately a log line rather than a crash, so nothing would ever be
    // loud about it.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedNotAHandler::class]);

    expect(fn () => app(Lightspeed\Boot\BootValidation::class)->validateHandlerConfiguration())
        ->toThrow(
            RuntimeException::class,
            'must implement [Lightspeed\Contracts\ConnectionClosedHandler]',
        );

    config()->set('lightspeed.connection_closed_handlers', ['App\\Realtime\\NoSuchHandler']);

    expect(fn () => app(Lightspeed\Boot\BootValidation::class)->validateHandlerConfiguration())
        ->toThrow(RuntimeException::class, 'does not exist');
});

/**
 * A handler that says which container built it.
 *
 * Which container the handlers come out of is not cosmetic: the application is
 * booted per worker process, AFTER the server object that holds this dispatcher
 * was built, and a close can run inside ANOTHER request's sandbox, which is
 * flushed moments later. Both were real, and the mark is how a test tells them
 * apart.
 */
class ConnectionClosedMarkedHandler implements ConnectionClosedHandler
{
    /** @var list<string> */
    public static array $marks = [];

    public function __construct(public string $mark = 'application')
    {
    }

    public function connectionClosed(ConnectionClosed $event): void
    {
        self::$marks[] = $this->mark;
    }
}

/** A handler that authenticates somebody, the way a real one reads its user. */
class ConnectionClosedAuthenticatingHandler implements ConnectionClosedHandler
{
    public function connectionClosed(ConnectionClosed $event): void
    {
        Illuminate\Support\Facades\Auth::guard()->setUser(new ConnectionClosedUser());
    }
}

/** Somebody for the handler above to log in. */
class ConnectionClosedUser implements Illuminate\Contracts\Auth\Authenticatable
{
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return 'the-handler-s-user';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}

/** A handler that leaves something behind in the container it ran in. */
class ConnectionClosedMutatingHandler implements ConnectionClosedHandler
{
    public const BINDING = 'lightspeed-closed-handler-was-here';

    public function connectionClosed(ConnectionClosed $event): void
    {
        Illuminate\Container\Container::getInstance()->instance(self::BINDING, $event->reason);
    }
}

/** A config repository that cannot answer. */
class ConnectionClosedBrokenConfig implements Illuminate\Contracts\Config\Repository
{
    public function has($key): bool
    {
        return false;
    }

    public function get($key, $default = null)
    {
        throw new \RuntimeException('the config store is unreachable');
    }

    public function all(): array
    {
        return [];
    }

    public function set($key, $value = null): void
    {
    }

    public function prepend($key, $value): void
    {
    }

    public function push($key, $value): void
    {
    }
}

test('handlers run in a sandbox, not in the container the close interrupted', function () {
    // THE STRANGER'S SANDBOX. A close does not always run on the event loop's
    // own stack: ChannelManager::closeDroppedFds() calls $server->close(), and
    // Swoole runs the close callback SYNCHRONOUSLY there, so a connection can be
    // torn down inside the request sandbox of somebody else's HTTP request,
    // which Octane flushes as soon as that request answers. Resolving a handler
    // out of it means the application's services for one request being handed to
    // an event about another connection entirely.
    ConnectionClosedMarkedHandler::$marks = [];

    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedMarkedHandler::class]);

    $application = Illuminate\Container\Container::getInstance();

    // An Octane-style sandbox: a clone of the application, bound('config') like
    // the real thing, made current exactly as Octane makes one current.
    $sandbox = clone $application;
    $sandbox->instance(
        ConnectionClosedMarkedHandler::class,
        new ConnectionClosedMarkedHandler("somebody else's request sandbox"),
    );

    $dispatcher = connectionClosedDispatcher(worker: connectionClosedWorker($application));

    try {
        Illuminate\Container\Container::setInstance($sandbox);

        $dispatcher->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-marks', 'ada'));

        // And the container the close interrupted is still the current one
        // afterwards. Octane's handleTask() restores the BASE application when
        // it finishes, which for a task dispatched mid-request would hand the
        // rest of that request a different container than it started with.
        expect(Illuminate\Container\Container::getInstance())->toBe($sandbox)
            // The facades too, or the restore is half a restore: a facade root
            // pointing at one application while the container points at another
            // is the same bleed by a different door.
            ->and(Illuminate\Support\Facades\Facade::getFacadeApplication())->toBe($sandbox);
    } finally {
        Illuminate\Container\Container::setInstance($application);
    }

    expect(ConnectionClosedMarkedHandler::$marks)->toBe(['application']);
});

test('what a handler leaves in its container does not outlive the event', function () {
    // The other proven failure of resolving from whatever is current: outside
    // any request or task that is the BASE application, which Octane clones for
    // every request afterwards. A handler that bound something scoped there was
    // inheriting it to every later request in the worker. A sandbox is thrown
    // away instead.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedMutatingHandler::class]);

    $application = Illuminate\Container\Container::getInstance();

    connectionClosedDispatcher(worker: connectionClosedWorker($application))
        ->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-leak', 'ada'));

    expect($application->bound(ConnectionClosedMutatingHandler::BINDING))->toBeFalse()
        // The positive control: the handler really did run and really did write
        // that binding, into a container that no longer exists.
        ->and(Illuminate\Container\Container::getInstance())->toBe($application);
});

test('a handler cannot leave an authenticated user behind for the next task', function () {
    // THE SANDBOX IS A SHALLOW CLONE, and that is the half a container test
    // cannot see. A binding the handler ADDS dies with the clone; a service
    // that was already resolved on the base application is the SAME OBJECT in
    // both, so what the handler does to it survives. The auth manager is that
    // service, and Octane warms it: a handler logging somebody in left that
    // user on a manager the clone does not own.
    //
    // Not for the next HTTP REQUEST, which is the answer that sounds right and
    // is not: Octane's own cleanup, FlushAuthenticationState, is bound to
    // RequestReceived, so a request arrives clean whatever a task left behind.
    // It is the next TASK that inherited it, because a task boundary is not a
    // request boundary and nothing ran that listener for us, and the rest of a
    // live request that a synchronous close interrupted after its own
    // RequestReceived had already fired.
    //
    // The flush that closes it is Http\OctaneWorker::runTask()'s, applied at
    // the boundary every handler this package runs passes through; this test
    // pins the close path's share of it. Tests\Unit\TaskAuthenticationFlushTest
    // covers the other callers and the boundary itself.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedAuthenticatingHandler::class]);

    $application = Illuminate\Container\Container::getInstance();

    // Resolved on the BASE application first, which is what makes it shared:
    // an unresolved service would be built fresh inside the sandbox and prove
    // nothing.
    $shared = $application->make('auth');
    $driver = $application->make('auth.driver');

    expect($application->resolved('auth'))->toBeTrue()
        ->and($application->resolved('auth.driver'))->toBeTrue();

    // A manager left pointing at an application that is not the current one is
    // the other half of what Octane's listener corrects, and it is why the
    // listener calls setApplication() before forgetting the guards: a guard
    // rebuilt afterwards would be built out of the wrong container's services.
    $application = Illuminate\Container\Container::getInstance();
    $pointer = new ReflectionProperty($shared::class, 'app');
    $pointer->setAccessible(true);
    $pointer->setValue($shared, new Illuminate\Container\Container());

    connectionClosedDispatcher(worker: connectionClosedWorker($application))
        ->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-auth', 'ada'));

    expect($application->make('auth'))->toBe($shared)
        ->and($shared->guard()->user())->toBeNull()
        // The singleton driver instance goes with the guards, so the next
        // resolution builds a new one; leaving it bound would hand the next
        // request a guard built around the user just forgotten.
        ->and($application->make('auth.driver'))->not->toBe($driver)
        ->and($pointer->getValue($shared))->toBe($application);
});

test('a container that is not an application is put back too', function () {
    // The restore has to cope with whatever it found, and what it found is not
    // this class's to choose. `CurrentApplication::set()` is typed to an
    // Illuminate application, so handing it a bare container is a TypeError
    // inside a `finally` on a Swoole callback's stack: the container would stay
    // whatever Octane left it as, silently, for everything that ran afterwards.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $application = Illuminate\Container\Container::getInstance();
    $bare = new Illuminate\Container\Container();

    $dispatcher = connectionClosedDispatcher(worker: connectionClosedWorker($application));

    try {
        Illuminate\Container\Container::setInstance($bare);

        $dispatcher->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-bare', 'ada'));

        $restored = Illuminate\Container\Container::getInstance();
    } finally {
        Illuminate\Container\Container::setInstance($application);
    }

    expect($restored)->toBe($bare)
        // And the handler still ran, out of the worker's application, so the
        // restore above is not the restore of a dispatch that did nothing.
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(1);
});

test('the handler list is read from the application running now, not from one captured before the fork', function () {
    // This object is built by `lightspeed:serve`, in the process that is about
    // to fork. A config repository captured then belongs to the CONSOLE
    // application: a provider that registers a handler in the booted worker
    // would never be seen, and a list edited before the fork would go on being
    // the list forever. Neither failure says anything out loud.
    ConnectionClosedMarkedHandler::$marks = [];

    $application = Illuminate\Container\Container::getInstance();
    $live = app('config');

    // The repository as it was before the fork: no handlers in it, ever.
    $beforeTheFork = new Illuminate\Config\Repository($live->all());
    $beforeTheFork->set('lightspeed.connection_closed_handlers', []);

    try {
        $application->instance('config', $beforeTheFork);

        $dispatcher = connectionClosedDispatcher(worker: connectionClosedWorker($application));

        expect($dispatcher->hasHandlers())->toBeFalse();

        // The worker boots its own application, with its own configuration.
        $application->instance('config', $live);
        $live->set('lightspeed.connection_closed_handlers', [ConnectionClosedMarkedHandler::class]);

        expect($dispatcher->hasHandlers())->toBeTrue();

        $dispatcher->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-config', 'ada'));
    } finally {
        $application->instance('config', $live);
    }

    expect(ConnectionClosedMarkedHandler::$marks)->toBe(['application']);
});

test('an event with no application worker to run in is logged, never run unsandboxed', function () {
    // The presence sweep is a timer that starts with the worker and can tick
    // before the application has bootstrapped or after it has been terminated.
    // There is no container to run a handler in then, and the answer is to say
    // so: running one anyway is the unsandboxed path this feature does not have.
    ConnectionClosedMarkedHandler::$marks = [];

    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedMarkedHandler::class]);

    $logger = ConnectionClosedLogger::make();

    (new ConnectionClosedDispatcher(app(OctaneWorker::class), $logger))
        ->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-unbooted', 'ada'));

    // Whole-entry: an operator reading this needs to know that nobody was
    // notified about a specific connection, and no handler is named because no
    // handler is at fault.
    expect(ConnectionClosedMarkedHandler::$marks)->toBe([])
        ->and($logger->entries)->toHaveCount(1)
        ->and($logger->entries[0]['fields'])->toBe([
            'reason' => 'connection-closed-worker-unavailable',
            'handler' => '',
            'socket' => null,
            'close_reason' => 'swept',
            'message' => 'no booted application worker',
        ]);
});

test('a handler list that is one class name rather than a list still runs', function () {
    // `'connection_closed_handlers' => App\Realtime\Handler::class` without the
    // brackets is the config typo that reads correctly to a human. It is
    // accepted rather than ignored, which is the same thing the other handler
    // lists do.
    config()->set('lightspeed.connection_closed_handlers', ConnectionClosedRecorder::class);

    connectionClosedDispatcher()->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-one', 'ada'));

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1);
});

test('an entry that is not a class name at all is named as such', function () {
    // The two shapes a list can hold that no resolution could ever fix, and
    // they are reported differently from a class that merely could not be
    // built, because the fix is different: this one is the config file.
    config()->set('lightspeed.connection_closed_handlers', ['', 123, ConnectionClosedRecorder::class]);

    $logger = ConnectionClosedLogger::make();
    $dispatcher = connectionClosedDispatcher($logger);

    $dispatcher->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-junk', 'ada'));

    $invalid = array_values(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'connection-closed-handler-invalid',
    ));

    // Whole-entry: there is no handler class to name, because the entry is not
    // one, and a line naming some other class would send an operator to the
    // wrong place in the config file.
    $expected = [
        'reason' => 'connection-closed-handler-invalid',
        'handler' => '',
        'socket' => null,
        'close_reason' => null,
        'message' => 'not a class name',
    ];

    expect($invalid)->toHaveCount(2)
        ->and($invalid[0]['fields'])->toBe($expected)
        ->and($invalid[1]['fields'])->toBe($expected)
        // And the one real handler in the list still ran.
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(1);
});

test('a handler that does not implement the contract is named with the contract it is missing', function () {
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedNotAHandler::class]);

    $logger = ConnectionClosedLogger::make();

    connectionClosedDispatcher($logger)
        ->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-contract', 'ada'));

    expect($logger->entries[0]['fields']['message'])
        ->toBe('does not implement '.ConnectionClosedHandler::class);
});

test('a configuration lookup that fails is a log line, not a dead worker', function () {
    // The config store is reachable through the application, and an application
    // is a thing that can be broken. This runs inside a Swoole callback, so the
    // answer is "nobody is notified", never an exception on the event loop.
    $logger = ConnectionClosedLogger::make();

    $application = Illuminate\Container\Container::getInstance();
    $live = app('config');

    $dispatcher = connectionClosedDispatcher($logger, connectionClosedWorker($application));

    try {
        $application->instance('config', new ConnectionClosedBrokenConfig());

        expect($dispatcher->hasHandlers())->toBeFalse();

        $dispatcher->dispatch(ConnectionClosed::swept('presence-lightspeed-closed-broken', 'ada'));
    } finally {
        $application->instance('config', $live);
    }

    expect($logger->entries[0]['fields'])->toBe([
        'reason' => 'connection-closed-handler-invalid',
        'handler' => '',
        'socket' => null,
        'close_reason' => null,
        'message' => 'the config store is unreachable',
    ]);
});

test('a failure names the connection it was about', function () {
    // An operator reading this line has one question first: which connection,
    // and was this an orderly close or a sweep? Without both, the line reports
    // that something failed somewhere.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedThrowingHandler::class]);

    $logger = ConnectionClosedLogger::make();

    connectionClosedDispatcher($logger)->dispatch(
        ConnectionClosed::closed('123.456', ['private-a'], [], ['user:42'], []),
    );

    expect($logger->entries[0]['fields'])->toBe([
        'reason' => 'connection-closed-handler-failed',
        'handler' => ConnectionClosedThrowingHandler::class,
        'socket' => '123.456',
        'close_reason' => 'closed',
        'message' => 'the application handler exploded',
    ]);
});

test('one sweep tells the application only as much as its budget allows, and the rest on the ticks after', function () {
    // The channel budget bounds CHANNELS, and one channel can hold any number
    // of abandoned members: a worker OOM-killed while holding a large presence
    // channel leaves all of them for one reapAbandoned() call to return at once.
    // Every one of those is an entry into application code, serially, inside a
    // timer callback on the loop that serves every connection this worker still
    // has. Nothing is lost by the ceiling: the reap happened, and the telling
    // catches up on the ticks after.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);
    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 2);

    $channel = connectionClosedChannel();
    $server = connectionClosedServer(new ConnectionClosedBridge());

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join(
        $channel,
        app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
        ['user_id' => 'ada', 'user_info' => []],
    );

    // Five members of one channel whose worker went away.
    foreach (range(1, 5) as $index) {
        app(PresenceStore::class)->join($channel, "dead-worker:socket:9.{$index}", [
            'user_id' => "ghost-{$index}",
            'user_info' => [],
        ]);
        Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:9.{$index}");
    }

    // All five are reaped, because the reap is one atomic script per channel and
    // deferring it would leave rows nothing else can remove. Only the telling
    // waits.
    expect(driveLightspeed($server, 'sweepPresence'))->toBe(5)
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(2)
        ->and(app(PresenceStore::class)->snapshot($channel)['ids'])->toBe(['ada']);

    expect(driveLightspeed($server, 'sweepPresence'))->toBe(0)
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(4);

    expect(driveLightspeed($server, 'sweepPresence'))->toBe(0)
        ->and(ConnectionClosedRecorder::$events)->toHaveCount(5);

    // Every reaped member, exactly once, oldest first.
    expect(array_map(
        fn (ConnectionClosed $event): string => (string) $event->presenceLeaves[0]['user_id'],
        ConnectionClosedRecorder::$events,
    ))->toBe(['ghost-1', 'ghost-2', 'ghost-3', 'ghost-4', 'ghost-5']);
});

test('a diagnostic socket closing tells the application nothing, whatever channels it holds', function () {
    // The gate asks whether the connection held a channel or a grant, because
    // both are things the APPLICATION agreed to. The diagnostic protocol agrees
    // to neither: Diagnostics\DiagnosticProtocol attaches through ChannelManager
    // directly, with no subscribe ladder, no channel authorization and no
    // grant, so its close arrives at the gate looking exactly like an
    // authorized one. Enabling diagnostics would otherwise turn the promise
    // "your handler only fires for connections that passed channel
    // authorization" into a false sentence in the docs.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $server = connectionClosedServer(new ConnectionClosedBridge());

    // The server's own instance, which is the one the teardown consults: the
    // eligibility map is composed inside Server rather than resolved, exactly
    // as it is in production.
    $probe = random_int(1000, 999999);
    readLightspeedProperty($server, 'diagnosticSockets')->admit($probe);
    app(ChannelManager::class)->subscribe($probe, 'diagnostic-'.bin2hex(random_bytes(4)));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $probe]);

    expect(ConnectionClosedRecorder::$events)->toBe([]);

    // The positive control on the same close path: an ordinary socket holding
    // the same kind of channel is still reported, so the assertion above is the
    // diagnostic gate rather than a hook that stopped working.
    $ordinary = random_int(1000, 999999);
    app(ChannelManager::class)->connect($ordinary, "{$ordinary}.1", []);
    app(ChannelManager::class)->subscribe($ordinary, 'ordinary-'.bin2hex(random_bytes(4)));

    driveLightspeed($server, 'closeConnection', [connectionClosedSwoole(), $ordinary]);

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1);
});

test('the notification budget is 100 a tick, floors at 1, and is read as a number', function () {
    // The same three failures the channel budget has. A ceiling of 0 does not
    // slow the telling down, it stops it forever while the queue grows; a
    // ceiling read as text is a ceiling no tick can reach, which is the
    // unbounded event loop this budget exists to bound.
    $server = connectionClosedServer(new ConnectionClosedBridge());

    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 0);
    expect(driveLightspeed($server, 'sweepNotificationBudget'))->toBe(1);

    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', -1000);
    expect(driveLightspeed($server, 'sweepNotificationBudget'))->toBe(1);

    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 'plenty');
    expect(driveLightspeed($server, 'sweepNotificationBudget'))->toBe(1);

    // A configured ceiling above the floor is honoured, so the floor is a floor
    // rather than a constant.
    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 7);
    expect(driveLightspeed($server, 'sweepNotificationBudget'))->toBe(7);

    // And the default, which is what almost every deployment runs.
    $presence = config('lightspeed.presence');
    unset($presence['sweep_max_notifications_per_tick']);
    config()->set('lightspeed.presence', $presence);

    expect(driveLightspeed($server, 'sweepNotificationBudget'))->toBe(100);
});

test('a queued notification the worker could not take is kept, not spent', function () {
    // The drain spends a budget slot per entry. An entry that reaches a
    // dispatcher with no booted application worker was never delivered to
    // anybody, and taking it off the queue anyway loses it for the one reason
    // guaranteed to be true for every entry behind it as well. So it goes back,
    // at the front, and the tick stops asking: one refusal answers for the
    // whole tick, or a backlog becomes a log flood on the event loop.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);
    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 2);

    $channel = connectionClosedChannel();
    $logger = ConnectionClosedLogger::make();

    // Composed exactly as `lightspeed:serve` composes it, with the one worker
    // this test can take away: the dispatcher the sweeper holds is built from
    // it inside Server::compose(), so nothing here is reaching past the wiring.
    $worker = new ConnectionClosedFlakyWorker(connectionClosedWorker());
    $server = connectionClosedServer(new ConnectionClosedBridge(), [
        'runtimeLogger' => $logger,
        'httpWorker' => $worker,
    ]);
    $sweeper = lightspeedSurface($server, 'sweepPresence');

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join(
        $channel,
        app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
        ['user_id' => 'ada', 'user_info' => []],
    );

    $abandon = function (string $userId) use ($channel): void {
        app(PresenceStore::class)->join($channel, "dead-worker:socket:8.{$userId}", [
            'user_id' => $userId,
            'user_info' => [],
        ]);
        Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:8.{$userId}");
    };

    $unavailable = fn (): int => count(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'connection-closed-worker-unavailable',
    ));

    $pending = fn (): array => readLightspeedProperty($sweeper, 'pendingNotifications');

    $abandon('ghost-1');
    $abandon('ghost-2');

    // The application worker goes away, which is what a terminated or
    // not-yet-booted one looks like from a timer callback.
    $worker->available = false;

    // Both members are reaped and both are held: the first was attempted and
    // came back undelivered, the second was not attempted at all, because the
    // first already answered for it.
    expect(driveLightspeed($server, 'sweepPresence'))->toBe(2)
        ->and(ConnectionClosedRecorder::$events)->toBe([])
        ->and($pending())->toHaveCount(2)
        ->and($unavailable())->toBe(1);

    // A tick that both drains and reaps, with the worker still gone: the drain
    // asks once, and the fresh reap is queued rather than asked again.
    $abandon('ghost-3');

    expect(driveLightspeed($server, 'sweepPresence'))->toBe(1)
        ->and($pending())->toHaveCount(3)
        ->and($unavailable())->toBe(2);

    // And a tick with nothing new: still one ask, still nothing lost.
    expect(driveLightspeed($server, 'sweepPresence'))->toBe(0)
        ->and($pending())->toHaveCount(3)
        ->and($unavailable())->toBe(3);

    // When a worker is there again the backlog is delivered, oldest first, at
    // the budget's rate.
    $worker->available = true;

    driveLightspeed($server, 'sweepPresence');

    expect(ConnectionClosedRecorder::$events)->toHaveCount(2)
        ->and($pending())->toHaveCount(1);

    driveLightspeed($server, 'sweepPresence');

    expect(array_map(
        fn (ConnectionClosed $event): string => (string) $event->presenceLeaves[0]['user_id'],
        ConnectionClosedRecorder::$events,
    ))->toBe(['ghost-1', 'ghost-2', 'ghost-3'])
        ->and(array_map(
            fn (ConnectionClosed $event): array => $event->channels,
            ConnectionClosedRecorder::$events,
        ))->toBe([[$channel], [$channel], [$channel]])
        ->and($pending())->toBe([])
        ->and($unavailable())->toBe(3);
});

test('a backlog nobody is listening for any more is dropped out loud, not replayed later', function () {
    // A queue held across a config change is a queue that delivers hours-old
    // departures to a handler registered long afterwards, about connections
    // whose leases have expired twice over. And a queue held FOREVER is a leak
    // with no reader. Dropped, said out loud with a count, once.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);
    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 1);

    $channel = connectionClosedChannel();
    $logger = ConnectionClosedLogger::make();
    $server = connectionClosedServer(new ConnectionClosedBridge(), ['runtimeLogger' => $logger]);
    $sweeper = lightspeedSurface($server, 'sweepPresence');

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join(
        $channel,
        app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
        ['user_id' => 'ada', 'user_info' => []],
    );

    foreach ([1, 2, 3] as $index) {
        app(PresenceStore::class)->join($channel, "dead-worker:socket:7.{$index}", [
            'user_id' => "ghost-{$index}",
            'user_info' => [],
        ]);
        Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:7.{$index}");
    }

    expect(driveLightspeed($server, 'sweepPresence'))->toBe(3)
        ->and(readLightspeedProperty($sweeper, 'pendingNotifications'))->toHaveCount(2);

    // The application takes its handler out of the list.
    config()->set('lightspeed.connection_closed_handlers', []);

    driveLightspeed($server, 'sweepPresence');

    $discards = array_values(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'connection-closed-handlers-removed',
    ));

    // Whole-entry: an operator's question here is "how many departures was my
    // application never told about", and a line that says a backlog was dropped
    // without saying how big it was answers nothing.
    expect(readLightspeedProperty($sweeper, 'pendingNotifications'))->toBe([])
        ->and($discards)->toHaveCount(1)
        ->and($discards[0]['action'])->toBe('error')
        ->and($discards[0]['fields'])->toBe([
            'reason' => 'connection-closed-handlers-removed',
            'discarded' => 2,
            'message' => 'swept connection-closed notifications were dropped without being delivered',
        ]);

    // Once. A tick that finds nothing to drop says nothing.
    driveLightspeed($server, 'sweepPresence');

    expect(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'connection-closed-handlers-removed',
    ))->toHaveCount(1);

    // And a handler registered again is not told about any of them.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    driveLightspeed($server, 'sweepPresence');

    expect(ConnectionClosedRecorder::$events)->toHaveCount(1);
});

test('a backlog the worker is stopping on is reported rather than dropped in silence', function () {
    // The timer that would have delivered these is being cleared, so they are
    // gone. An operator deciding whether a lease covered the gap needs the
    // number; a queue that empties itself in silence is the failure this hook
    // documents itself against.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);
    config()->set('lightspeed.presence.sweep_max_notifications_per_tick', 1);

    $channel = connectionClosedChannel();
    $logger = ConnectionClosedLogger::make();
    $server = connectionClosedServer(new ConnectionClosedBridge(), ['runtimeLogger' => $logger]);
    $sweeper = lightspeedSurface($server, 'sweepPresence');

    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
    app(PresenceStore::class)->join(
        $channel,
        app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
        ['user_id' => 'ada', 'user_info' => []],
    );

    foreach ([1, 2] as $index) {
        app(PresenceStore::class)->join($channel, "dead-worker:socket:6.{$index}", [
            'user_id' => "ghost-{$index}",
            'user_info' => [],
        ]);
        Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:6.{$index}");
    }

    driveLightspeed($server, 'sweepPresence');

    expect(readLightspeedProperty($sweeper, 'pendingNotifications'))->toHaveCount(1);

    driveLightspeed($server, 'shutdownPresenceSweeper');

    $discards = array_values(array_filter(
        $logger->entries,
        fn (array $entry): bool => ($entry['fields']['reason'] ?? null) === 'presence-sweeper-shutdown',
    ));

    expect(readLightspeedProperty($sweeper, 'pendingNotifications'))->toBe([])
        ->and($discards)->toHaveCount(1)
        ->and($discards[0]['action'])->toBe('error')
        ->and($discards[0]['fields'])->toBe([
            'reason' => 'presence-sweeper-shutdown',
            'discarded' => 1,
            'message' => 'swept connection-closed notifications were dropped without being delivered',
        ]);
});

test('one killed connection on several presence channels arrives as one event per channel', function () {
    // Nothing correlates them, and nothing can: the connection they belonged to
    // was in a process that is gone, so there is no socket id in any of them to
    // tie them together. An application that expects one event per connection
    // would act on the first and ignore the rest.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $first = connectionClosedChannel();
    $second = connectionClosedChannel();
    $server = connectionClosedServer(new ConnectionClosedBridge());

    foreach ([$first, $second] as $channel) {
        $fd = random_int(1000, 999999);
        app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
        app(ChannelManager::class)->subscribe($fd, $channel, ['user_id' => 'ada', 'user_info' => []]);
        app(PresenceStore::class)->join(
            $channel,
            app(ConnectionId::class)->presenceConnectionId($fd, "{$fd}.1"),
            ['user_id' => 'ada', 'user_info' => []],
        );

        // The same dead connection, a member of both channels.
        app(PresenceStore::class)->join($channel, 'dead-worker:socket:9.9', ['user_id' => 'grace', 'user_info' => []]);
        Redis::connection()->del("lightspeed:presence:channel:{$channel}:live:dead-worker:socket:9.9");
    }

    expect(driveLightspeed($server, 'sweepPresence'))->toBe(2);

    $reported = array_map(
        function (ConnectionClosed $event): string {
            expect($event->channels)->toHaveCount(1);

            return $event->channels[0];
        },
        ConnectionClosedRecorder::$events,
    );

    $expected = [$first, $second];
    sort($reported);
    sort($expected);

    expect(ConnectionClosedRecorder::$events)->toHaveCount(2)
        ->and($reported)->toBe($expected)
        // One channel each, a socket id in neither, so an application holding
        // per-connection state has to key on the channel and the user.
        ->and(ConnectionClosedRecorder::$events[0]->socketId)->toBeNull()
        ->and(ConnectionClosedRecorder::$events[1]->socketId)->toBeNull();
});

test('a departing presence member reaches the application named as a string', function () {
    // The same rule the member_removed frame follows, and for a related reason:
    // an application keying its own state by user id would miss every member
    // whose id happens to be numeric if the type depended on what the channel
    // callback returned.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    connectionClosedTeardown([
        'channels' => new ConnectionClosedChannels([
            'channels' => ['presence-a', 'presence-b'],
            'socket_id' => '125.456',
            'presence_leaves' => [
                ['channel' => 'presence-a', 'presence_member' => ['user_id' => 12345]],
                ['channel' => 'presence-b', 'presence_member' => ['name' => 'no id here']],
            ],
        ]),
        'presence' => ConnectionClosedPresence::make(),
        'delivery' => new ConnectionClosedDelivery(),
    ])->closeConnection(connectionClosedSwoole(), random_int(1000, 999999));

    expect(ConnectionClosedRecorder::$events[0]->presenceLeaves)->toBe([
        ['channel' => 'presence-a', 'user_id' => '12345', 'user_info' => null],
        // A member payload with no id at all is carried as null rather than as
        // an empty string, so an application can tell "no identity" from "an
        // identity that is the empty string".
        ['channel' => 'presence-b', 'user_id' => null, 'user_info' => null],
    ]);
});

test('a numeric grant tag reaches the application as the string it was signed as', function () {
    // Tags are deduplicated through an array key, and PHP turns the key "7"
    // into the integer 7. A tag is only ever compared for equality against a
    // revocation key, so an application matching on its own tag would find the
    // wrong type here.
    config()->set('lightspeed.connection_closed_handlers', [ConnectionClosedRecorder::class]);

    $grants = new ConnectionGrants();
    $fd = random_int(1000, 999999);
    $now = (int) (microtime(true) * 1_000_000);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    $grants->mint($fd, 'private-numeric', new Grant(['7', '7', 'user:42'], [], $now, $now + 60_000_000));

    connectionClosedTeardown(['grants' => $grants])
        ->closeConnection(connectionClosedSwoole(), $fd);

    expect(ConnectionClosedRecorder::$events[0]->tags)->toBe(['7', 'user:42']);
});
