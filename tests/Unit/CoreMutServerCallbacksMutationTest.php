<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationDrops;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\Handshake;
use Lightspeed\Protocol\Teardown;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Connections\ConnectionSweeper;
use Lightspeed\Logging\OperatorLog;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The callback boundaries, and what survives crossing one.
 *
 * tests/Unit/SwooleCallbackSafetyTest.php pins that an exception in a callback
 * body does not end the worker. tests/Unit/WorkerStartHealthTest.php pins that
 * a worker which could not start says so and stops claiming health. Neither one
 * looks at WHAT was said: the surface, the identifying field, and the close
 * code are the entire content of a report nobody is waiting on, and a report
 * that names no connection is a report an operator cannot act on.
 *
 * This file also pins the worker-number branch, which decides whether a process
 * arms the relay and the three sweepers or only boots the application.
 */

/**
 * Every server this file starts, so its timers can be cleared again.
 *
 * A Swoole timer is process-global and outlives the test that armed it: left
 * running, the first tick after Testbench tears the container down calls
 * config() against an application that no longer has one.
 *
 * @var list<Server>
 */
$GLOBALS['coreMutServers'] = [];

afterEach(function () {
    foreach ($GLOBALS['coreMutServers'] as $server) {
        try {
            driveLightspeed($server, 'stopWorker', []);
        } catch (\Throwable) {
            // Clearing the timers is all this needs.
        }
    }

    $GLOBALS['coreMutServers'] = [];
});

/** Records what the wire was told, in place of a real websocket. */
class CoreMutServerSwoole extends SwooleServer
{
    /** @var list<array{fd: int, code: int, reason: string}> */
    public array $disconnects = [];

    /** @var list<array> decoded frames */
    public array $pushed = [];

    /** @var array<string, callable> */
    public array $callbacks = [];

    public function on(string $event_name, callable $callback): bool
    {
        $this->callbacks[strtolower($event_name)] = $callback;

        return true;
    }

    /** @param array<string, mixed>|null $setting */
    public static function make(?array $setting = ['worker_num' => 1]): self
    {
        $server = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $server->setting = $setting ?? [];

        return $server;
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
        $this->disconnects[] = ['fd' => $fd, 'code' => $code, 'reason' => $reason];

        return true;
    }
}

/** Keeps the fields of every report, which the other recorders in this suite drop. */
class CoreMutServerOperatorLog extends OperatorLog
{
    /** @var list<array{surface: string, message: string, fields: array}> */
    public array $failures = [];

    /** @var list<string> */
    public array $lines = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function reportCallbackFailure(string $surface, \Throwable $e, array $fields = []): void
    {
        $this->failures[] = ['surface' => $surface, 'message' => $e->getMessage(), 'fields' => $fields];
    }
}

/** Keeps the structured lines instead of writing them to stdout. */
class CoreMutServerRuntimeLogger extends RuntimeLogger
{
    /** @var list<array{action: string, fields: array}> */
    public array $websocket = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->websocket[] = ['action' => $action, 'fields' => $fields];
    }
}

/** An Octane worker that records its lifecycle instead of booting Laravel. */
class CoreMutServerOctaneWorker extends OctaneWorker
{
    public bool $booted = false;

    public bool $terminateThrows = false;

    public bool $taskThrows = false;

    public function __construct()
    {
    }

    public function boot(SwooleServer $server): void
    {
        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function terminate(): void
    {
        if ($this->terminateThrows) {
            throw new \RuntimeException('a WorkerStopping listener threw');
        }
    }

    public function handleTask(mixed $data): mixed
    {
        if ($this->taskThrows) {
            throw new \RuntimeException('the task body threw');
        }

        return $data;
    }
}

/** An owner command bus that records whether the worker half was armed. */
class CoreMutServerOwnerCommandBus extends OwnerCommandBus
{
    public bool $bootedWorker = false;

    public function __construct()
    {
    }

    public function bootWorker(SwooleServer $server, int $workerId): void
    {
        $this->bootedWorker = true;
    }

    public function shutdownWorker(): void
    {
    }
}

/** Revocation drops that record whether their listener was ever registered. */
class CoreMutServerRevocationDrops extends RevocationDrops
{
    public bool $listening = false;

    public function __construct()
    {
    }

    public function bootRevocationListeners(SwooleServer $server): void
    {
        $this->listening = true;
    }
}

/** A connection sweeper that records whether it was armed. */
class CoreMutServerConnectionSweeper extends ConnectionSweeper
{
    public bool $armed = false;

    public function __construct()
    {
    }

    public function bootConnectionSweeper(SwooleServer $server): void
    {
        $this->armed = true;
    }

    public function shutdownConnectionSweeper(): void
    {
    }
}

class CoreMutFailingHandshake extends Handshake
{
    public function __construct()
    {
    }

    public function openConnection(SwooleServer $server, \Swoole\Http\Request $request): void
    {
        throw new \RedisException('the handshake reached a dead Redis');
    }
}

class CoreMutFailingTeardown extends Teardown
{
    public function __construct()
    {
    }

    public function closeConnection(SwooleServer $server, int $fd): void
    {
        throw new \RedisException('the teardown reached a dead Redis');
    }
}

/**
 * Assemble a Server whose reports and disconnects are things a test can read.
 *
 * @param array<string, object> $constructorOverrides
 * @param array<string, object> $composedOverrides applied after compose()
 */
function coreMutServer(array $constructorOverrides = [], array $composedOverrides = []): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = array_merge([
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => new CoreMutServerOwnerCommandBus(),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => new CoreMutServerRuntimeLogger(),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => new CoreMutServerOctaneWorker(),
    ], $constructorOverrides);

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    writeLightspeedProperty($server, 'operatorLog', new CoreMutServerOperatorLog());

    foreach ($composedOverrides as $name => $value) {
        writeLightspeedProperty($server, $name, $value);
    }

    $GLOBALS['coreMutServers'][] = $server;

    return $server;
}

function coreMutServerLog(Server $server): CoreMutServerOperatorLog
{
    return readLightspeedProperty($server, 'operatorLog');
}

function coreMutOpenRequest(int $fd): \Swoole\Http\Request
{
    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = $fd;
    $request->header = ['host' => 'localhost'];
    $request->server = [
        'request_uri' => '/app/'.config('lightspeed.reverb_compat.app_key'),
        'remote_addr' => '127.0.0.1',
    ];

    return $request;
}

// ---------------------------------------------------------------------------
// Construction.
// ---------------------------------------------------------------------------

test('the application worker is handed the two services a revoke inside a request needs', function () {
    // `Lightspeed::revoke()` runs in an ordinary HTTP request, inside this very
    // process, and the connections it has to drop are the ones this object is
    // holding. The relay cannot make up for a second copy: it never replays a
    // process's own publishes back to it, so a revoke served here would drop
    // connections on every worker except the one that served it.
    $server = app(Server::class);

    $shared = new ReflectionProperty(OctaneWorker::class, 'sharedInstances');
    $shared->setAccessible(true);

    $instances = $shared->getValue(readLightspeedProperty($server, 'httpWorker'));

    expect($instances[ConnectionGrants::class] ?? null)->toBe(app(ConnectionGrants::class))
        ->and($instances[RevocationLog::class] ?? null)->toBe(app(RevocationLog::class));
});

// ---------------------------------------------------------------------------
// The banner.
// ---------------------------------------------------------------------------

test('one listener on one port is announced once, and two ports are announced separately', function () {
    // An operator reads this line to find out where the server actually is.
    // When HTTP and realtime share a port there is one address to print, and
    // when they do not there are two: printing the combined line for a split
    // listener names a port the realtime socket is not on.
    $combined = coreMutServer();
    $swoole = CoreMutServerSwoole::make();
    driveLightspeed($combined, 'registerCallbacks', [$swoole, '0.0.0.0', 8000, 8000, true]);

    $split = coreMutServer();
    $splitSwoole = CoreMutServerSwoole::make();
    driveLightspeed($split, 'registerCallbacks', [$splitSwoole, '0.0.0.0', 8080, 9000, false]);

    ($swoole->callbacks['start'])($swoole);
    ($splitSwoole->callbacks['start'])($splitSwoole);

    expect(coreMutServerLog($combined)->lines)->toBe([
        'Lightspeed HTTP + realtime listening on 0.0.0.0:8000',
        'PID: '.getmypid(),
    ])->and(coreMutServerLog($split)->lines)->toBe([
        'Lightspeed HTTP listening on 0.0.0.0:9000',
        'Lightspeed realtime listening on 0.0.0.0:8080',
        'PID: '.getmypid(),
    ]);
});

// ---------------------------------------------------------------------------
// Which processes arm the realtime machinery.
// ---------------------------------------------------------------------------

test('a task worker boots the application and arms none of the realtime machinery', function () {
    // Swoole numbers task workers from `worker_num` upwards, and a task worker
    // holds no websocket connections: arming the relay and the sweepers there
    // would be timers ticking against an empty registry and a second process
    // claiming coordination identity it cannot act on. What it DOES need is the
    // application, because running one is the only thing it is for.
    $worker = new CoreMutServerOctaneWorker();
    $sweeper = new CoreMutServerConnectionSweeper();
    $drops = new CoreMutServerRevocationDrops();
    $bus = new CoreMutServerOwnerCommandBus();

    $server = coreMutServer(
        ['httpWorker' => $worker, 'ownerCommandBus' => $bus],
        ['connectionSweeper' => $sweeper, 'revocationDrops' => $drops],
    );

    driveLightspeed($server, 'bringWorkerUp', [CoreMutServerSwoole::make(['worker_num' => 2]), 2]);

    expect($worker->booted)->toBeTrue()
        ->and($sweeper->armed)->toBeFalse()
        ->and($drops->listening)->toBeFalse()
        ->and($bus->bootedWorker)->toBeFalse();
});

test('the last request worker is a request worker, not a task worker', function () {
    // The boundary itself: with `worker_num` 2 the request workers are 0 and 1,
    // and reading the dial wrong by one either drains the last request worker
    // of everything that makes it able to serve, or arms a task worker with
    // timers it has no connections for.
    $sweeper = new CoreMutServerConnectionSweeper();
    $drops = new CoreMutServerRevocationDrops();
    $bus = new CoreMutServerOwnerCommandBus();

    $server = coreMutServer(
        ['ownerCommandBus' => $bus],
        ['connectionSweeper' => $sweeper, 'revocationDrops' => $drops],
    );

    driveLightspeed($server, 'bringWorkerUp', [CoreMutServerSwoole::make(['worker_num' => 2]), 1]);

    expect($sweeper->armed)->toBeTrue()
        ->and($drops->listening)->toBeTrue()
        ->and($bus->bootedWorker)->toBeTrue();
});

test('a server that reports no worker_num at all is read as having one request worker', function () {
    // The fallback decides the split when Swoole has not been told: worker 0
    // serves, and anything above it is a task worker.
    $firstSweeper = new CoreMutServerConnectionSweeper();
    $first = coreMutServer([], ['connectionSweeper' => $firstSweeper]);
    driveLightspeed($first, 'bringWorkerUp', [CoreMutServerSwoole::make([]), 0]);

    $secondSweeper = new CoreMutServerConnectionSweeper();
    $second = coreMutServer([], ['connectionSweeper' => $secondSweeper]);
    driveLightspeed($second, 'bringWorkerUp', [CoreMutServerSwoole::make([]), 1]);

    expect($firstSweeper->armed)->toBeTrue()
        ->and($secondSweeper->armed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// What a failed worker start says.
// ---------------------------------------------------------------------------

/** A bridge that cannot attach, standing in for a relay against a dead Redis. */
class CoreMutUnattachableBridge extends BroadcastBridge
{
    public function __construct()
    {
    }

    public function attach(SwooleServer $server, int $workerId): void
    {
        throw new \RedisException('Connection refused');
    }
}

test('a failed worker start names the worker, on stdout and on the structured channel', function () {
    // Both, deliberately: stdout because the websocket log channel is opt-in
    // and a worker that came up broken has to be visible on a configuration
    // nobody changed, and the structured channel for the deployment that ships
    // these somewhere searchable. WHICH worker is the field that turns "a
    // worker failed" into something an operator can go and look at, on a server
    // where the other workers are serving normally.
    $logger = new CoreMutServerRuntimeLogger();
    $server = coreMutServer([
        'broadcastBridge' => new CoreMutUnattachableBridge(),
        'runtimeLogger' => $logger,
    ]);

    driveLightspeed($server, 'startWorker', [CoreMutServerSwoole::make(), 0]);

    $failures = coreMutServerLog($server)->failures;

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['surface'])->toBe('workerStart')
        ->and($failures[0]['fields'])->toBe(['worker' => 0])
        ->and($logger->websocket)->toHaveCount(1)
        ->and($logger->websocket[0]['action'])->toBe('error')
        ->and($logger->websocket[0]['fields'])->toBe([
            'worker' => 0,
            'reason' => 'worker-start-failed',
            'message' => 'Connection refused',
        ]);
});

// ---------------------------------------------------------------------------
// What a worker stop does, and says when a step of it fails.
// ---------------------------------------------------------------------------

test('the shutdown detaches the server every timer acts through', function () {
    // The sweepers clear their own timers, but the attached server is what each
    // of their callbacks reaches the wire through. Left attached, a timer that
    // outlived its worker goes on acting against a server this object no longer
    // holds.
    $server = coreMutServer();

    driveLightspeed($server, 'startWorker', [CoreMutServerSwoole::make(), 0]);

    expect(readLightspeedProperty($server, 'attachedServer')->get())->not->toBeNull();

    driveLightspeed($server, 'stopWorker', []);

    expect(readLightspeedProperty($server, 'attachedServer')->get())->toBeNull();
});

test('a shutdown step that throws is reported by name, so the rest of the sequence is readable', function () {
    // `terminate()` dispatches Octane's WorkerStopping event into the host
    // application, so this is somebody else's code throwing inside a callback
    // with no exception boundary of its own. Eight steps run here and seven of
    // them will have succeeded; a report that does not say which one failed
    // leaves an operator to guess between the relay, the poller, the three
    // sweepers and the application.
    $worker = new CoreMutServerOctaneWorker();
    $worker->terminateThrows = true;

    $server = coreMutServer(['httpWorker' => $worker]);

    driveLightspeed($server, 'startWorker', [CoreMutServerSwoole::make(), 0]);
    driveLightspeed($server, 'stopWorker', []);

    $failures = coreMutServerLog($server)->failures;

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['surface'])->toBe('workerStop')
        ->and($failures[0]['fields'])->toBe(['step' => 'http-worker']);
});

// ---------------------------------------------------------------------------
// The four wire callbacks.
// ---------------------------------------------------------------------------

test('a task whose body threw is reported against the task it was, and answered as a value', function () {
    // Every failure comes back as a VALUE rather than as an exception, which is
    // Octane's contract: SwooleTaskDispatcher inspects the result and rethrows
    // the original in the process that asked for the work. The task id is what
    // ties the report to the dispatch, since nobody is waiting on this line.
    $worker = new CoreMutServerOctaneWorker();
    $worker->taskThrows = true;

    $server = coreMutServer(['httpWorker' => $worker]);

    $result = driveLightspeed($server, 'handleTask', [CoreMutServerSwoole::make(), 77, 0, ['job']]);

    $failures = coreMutServerLog($server)->failures;

    expect($result)->toBeInstanceOf(\Laravel\Octane\Exceptions\TaskExceptionResult::class)
        ->and($failures[0]['surface'])->toBe('task')
        ->and($failures[0]['fields'])->toBe(['task_id' => 77]);
});

test('a worker that is not ready refuses the upgrade with the code that means try again', function () {
    // 4100 is the Pusher code for "reconnect after a backoff", and it is the
    // honest advice: another node, or this one after a restart, can serve the
    // client. A code outside the 4000 range, or one that means "do not retry",
    // turns a rolling deploy into clients that never come back.
    $server = coreMutServer();
    $swoole = CoreMutServerSwoole::make();

    driveLightspeed($server, 'handleOpen', [$swoole, coreMutOpenRequest(4242)]);

    expect($swoole->disconnects)->toBe([
        ['fd' => 4242, 'code' => 4100, 'reason' => 'Server is not ready'],
    ]);
});

test('a handshake that threw is reported against its connection and answered with the same advice', function () {
    // A connection left open here would sit in "connecting" forever, and the
    // fd is the only thing that ties this report to the client that met it.
    $server = coreMutServer([], ['handshake' => new CoreMutFailingHandshake()]);
    markLightspeedWorkerReady($server);

    $swoole = CoreMutServerSwoole::make();

    driveLightspeed($server, 'handleOpen', [$swoole, coreMutOpenRequest(51)]);

    $failures = coreMutServerLog($server)->failures;

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['surface'])->toBe('open')
        ->and($failures[0]['fields'])->toBe(['fd' => 51])
        ->and($swoole->disconnects)->toBe([
            ['fd' => 51, 'code' => 4100, 'reason' => 'Handshake failed'],
        ]);
});

/** A frame router that cannot serve a frame. */
class CoreMutFailingFrameRouter extends \Lightspeed\Protocol\FrameRouter
{
    public function __construct()
    {
    }

    public function receiveFrame(SwooleServer $server, Frame $frame): void
    {
        throw new \RedisException('the grant check reached a dead Redis');
    }
}

test('a frame that could not be served is reported against its connection', function () {
    // The socket stays open: one frame that could not be served is not evidence
    // the connection is unusable. What is left behind is this line, and without
    // the fd it says only that some frame somewhere failed.
    $server = coreMutServer([], ['frameRouter' => new CoreMutFailingFrameRouter()]);

    $frame = new Frame();
    $frame->fd = 88;
    $frame->data = '{"event":"pusher:ping"}';

    driveLightspeed($server, 'handleMessage', [CoreMutServerSwoole::make(), $frame]);

    $failures = coreMutServerLog($server)->failures;

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['surface'])->toBe('message')
        ->and($failures[0]['fields'])->toBe(['fd' => 88]);
});

test('a teardown that could not finish is reported against the connection it was for', function () {
    // Nothing is pushed back, because the socket is already gone. What is lost
    // is the shared-state half of the teardown, the presence row and the
    // registry entry, so this line is the only evidence that a particular
    // connection left state behind for the sweepers to reconcile.
    $server = coreMutServer([], ['teardown' => new CoreMutFailingTeardown()]);

    driveLightspeed($server, 'handleClose', [CoreMutServerSwoole::make(), 99]);

    $failures = coreMutServerLog($server)->failures;

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['surface'])->toBe('close')
        ->and($failures[0]['fields'])->toBe(['fd' => 99]);
});
