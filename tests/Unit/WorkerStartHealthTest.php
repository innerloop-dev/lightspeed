<?php

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
use Lightspeed\Logging\OperatorLog;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: a worker that could not finish starting
 * does not go on pretending it did.
 *
 * `workerStart` was the ONE Swoole callback with no try/catch, in a class whose
 * stated job is "the GUARD around every callback body". Injecting a throw into
 * `BroadcastBridge::attach()` on a live server produced, all at once:
 *
 *   - no log line, anywhere
 *   - no respawn: the worker stayed up
 *   - clients connecting and subscribing normally
 *   - every broadcast going nowhere
 *   - `GET /healthz` returning 200
 *
 * docs/PRODUCTION.md tells operators to point a load balancer at that endpoint,
 * so the one signal that could have taken the broken node out of rotation was
 * the signal actively saying it was fine. `httpWorker->boot()` runs LAST in
 * that callback, which is what let every earlier step fail with the websocket
 * surface up and answering.
 *
 * Confirmed by mutation as well: making the whole `workerStart` body not run
 * left the entire suite green.
 *
 * Two halves, and neither is sufficient alone. A worker that logs its failure
 * and still reports 200 is still drained into by the load balancer. A worker
 * that reports 503 and says nothing leaves an operator with a red check and no
 * cause.
 */

/**
 * Every server this file starts, so its timers can be cleared again.
 *
 * `startWorker()` arms real Swoole timers, and a Swoole timer is process-global
 * and outlives the test that armed it. Left running, the first tick after
 * Testbench has torn the container down calls config() against an application
 * that no longer has one, and the whole suite ends in a fatal error AFTER
 * reporting every test as passed.
 *
 * @var list<Server>
 */
$GLOBALS['lightspeedWorkerHealthServers'] = [];

afterEach(function () {
    // Each teardown is its own try. Several of these servers are built around
    // a collaborator that deliberately cannot work, and stopWorker() runs
    // through that collaborator on its way out: without the try, the first such
    // server aborts the loop and every server after it in the list is left
    // ticking, which is the exact failure this block exists to prevent.
    foreach ($GLOBALS['lightspeedWorkerHealthServers'] as $server) {
        try {
            driveLightspeed($server, 'stopWorker', []);
        } catch (\Throwable) {
            // The timers are cleared first, which is all this needs.
        }
    }

    $GLOBALS['lightspeedWorkerHealthServers'] = [];
});

/** Records pushes and disconnects, in place of a real websocket. */
class WorkerHealthSwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

    /** @var list<int> fds this server was asked to disconnect */
    public array $disconnected = [];

    public static function make(int $workerNum = 1): self
    {
        $server = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $server->setting = ['worker_num' => $workerNum];

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
        $this->disconnected[] = $fd;

        return true;
    }
}

/** A bridge that cannot attach, standing in for a relay against a dead Redis. */
class UnattachableBroadcastBridge extends BroadcastBridge
{
    public function __construct()
    {
    }

    public function attach(SwooleServer $server, int $workerId): void
    {
        throw new \RedisException('Connection refused');
    }
}

/** Records what the operator was told, instead of writing to stdout. */
class RecordingOperatorLog extends OperatorLog
{
    /** @var list<array{surface: string, message: string}> */
    public array $failures = [];

    /** @var list<string> */
    public array $lines = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function reportCallbackFailure(string $surface, \Throwable $e, array $fields = []): void
    {
        $this->failures[] = ['surface' => $surface, 'message' => $e->getMessage()];
    }
}

/** A Swoole request with the fields the router reads, and nothing else. */
class WorkerHealthRequest extends \Swoole\Http\Request
{
    public static function make(string $path, int $serverPort = 8000): self
    {
        $request = new self();
        $request->server = [
            'request_uri' => $path,
            'request_method' => 'GET',
            'server_port' => $serverPort,
            'remote_addr' => '10.0.0.9',
        ];
        $request->header = ['host' => 'example.test'];
        $request->get = [];
        $request->post = [];

        return $request;
    }
}

/** A Swoole response that records what was written instead of sending it. */
class WorkerHealthResponse extends \Swoole\Http\Response
{
    public ?int $sentStatus = null;

    public ?string $sentBody = null;

    public function status(int|string $statusCode, string $reason = ''): bool
    {
        $this->sentStatus = (int) $statusCode;

        return true;
    }

    public function header(string $key, mixed $value, bool $format = true): bool
    {
        return true;
    }

    public function end(mixed $content = null): bool
    {
        $this->sentBody = (string) $content;

        return true;
    }

    /** @return array<string, mixed> */
    public function decodedBody(): array
    {
        return json_decode((string) $this->sentBody, true, 512, JSON_THROW_ON_ERROR);
    }
}

/** An Octane worker that records its lifecycle instead of booting Laravel. */
class WorkerHealthOctaneWorker extends OctaneWorker
{
    public bool $booted = false;

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

    public function runTask(callable $task): mixed
    {
        return $task();
    }
}

/**
 * Assemble a Server with a recording operator log, so what an operator would
 * have seen is a thing this file can assert rather than a thing on stdout.
 *
 * @param array<string, object> $overrides
 */
function workerHealthServer(array $overrides = []): Server
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
        'httpWorker' => new WorkerHealthOctaneWorker(),
    ], $overrides);

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    writeLightspeedProperty($server, 'operatorLog', new RecordingOperatorLog());

    $GLOBALS['lightspeedWorkerHealthServers'][] = $server;

    return $server;
}

function workerHealthOperatorLog(Server $server): RecordingOperatorLog
{
    return readLightspeedProperty($server, 'operatorLog');
}

// ---------------------------------------------------------------------------
// Half one: it must not be silent.
// ---------------------------------------------------------------------------

test('a collaborator that throws during worker start does not take the callback with it', function () {
    // Swoole gives workerStart no exception boundary of its own, exactly as it
    // gives `message` and `close` none. Every other callback in this class has
    // been guarded since SwooleCallbackSafetyTest was written; this one was
    // missed, and it is the callback where a failure is least visible because
    // there is no client waiting on a frame to notice.
    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);

    $escaped = null;

    try {
        driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);
    } catch (\Throwable $e) {
        $escaped = $e;
    }

    expect($escaped)->toBeNull();
});

test('a worker that could not start says so where a default install will see it', function () {
    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    // Logging\OperatorLog and not Logging\RuntimeLogger: the websocket log
    // channel is opt-in, and a worker that came up broken has to be visible on
    // a configuration nobody changed.
    $reported = workerHealthOperatorLog($server)->failures;

    expect($reported)->toHaveCount(1)
        ->and($reported[0]['surface'])->toBe('workerStart')
        ->and($reported[0]['message'])->toBe('Connection refused');
});

test('a worker that started cleanly reports no failure', function () {
    // The positive control. Without it, a startWorker that reported a failure
    // unconditionally would satisfy the assertion above.
    $server = workerHealthServer();

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    expect(workerHealthOperatorLog($server)->failures)->toBe([]);
});

// ---------------------------------------------------------------------------
// Half two: /healthz must not claim health it does not have.
// ---------------------------------------------------------------------------

test('the health endpoint refuses to report 200 for a worker that did not come up', function () {
    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    $response = new WorkerHealthResponse();
    driveLightspeed($server, 'handleRequest', [
        WorkerHealthRequest::make('/healthz', 8000),
        $response,
        8000,
        true,
    ]);

    expect($response->sentStatus)->toBe(503)
        ->and($response->decodedBody()['ok'])->toBeFalse();
});

test('the health endpoint reports 200 once the worker is up', function () {
    // The positive control, and the one that matters most: a /healthz that
    // answered 503 unconditionally would satisfy the assertion above while
    // taking every healthy node out of the load balancer.
    $server = workerHealthServer();

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    $response = new WorkerHealthResponse();
    driveLightspeed($server, 'handleRequest', [
        WorkerHealthRequest::make('/healthz', 8000),
        $response,
        8000,
        true,
    ]);

    expect($response->sentStatus)->toBe(200)
        ->and($response->decodedBody()['ok'])->toBeTrue();
});

test('a worker reports itself unhealthy before it has started at all', function () {
    // The default has to be "not ready", not "ready until proven otherwise".
    // A request that arrives between the listener binding and the worker
    // finishing its start-up is exactly the window a rolling deploy drives
    // through, and answering 200 in it is the same lie one tick earlier.
    $server = workerHealthServer();

    $response = new WorkerHealthResponse();
    driveLightspeed($server, 'handleRequest', [
        WorkerHealthRequest::make('/healthz', 8000),
        $response,
        8000,
        true,
    ]);

    expect($response->sentStatus)->toBe(503);
});

test('the health body names the failure, so a red check comes with a cause', function () {
    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    $response = new WorkerHealthResponse();
    driveLightspeed($server, 'handleRequest', [
        WorkerHealthRequest::make('/healthz', 8000),
        $response,
        8000,
        true,
    ]);

    expect($response->decodedBody()['error'])->toContain('Connection refused');
});

// ---------------------------------------------------------------------------
// The third surface, and the reason the first two are not enough on their own.
// ---------------------------------------------------------------------------

test('a worker that cannot broadcast does not accept websocket connections', function () {
    // The observation that started this: clients connected and subscribed
    // normally while every broadcast went nowhere. A subscription on a worker
    // whose relay never attached is a promise the worker cannot keep, and the
    // client has no way to find that out: nothing is refused, nothing is
    // logged, and the frames simply never arrive.
    //
    // 4100 is the Pusher code for "reconnect after a backoff", which is the
    // honest advice: another node, or this one after a restart, can serve it.
    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);
    $swoole = WorkerHealthSwooleServer::make();

    driveLightspeed($server, 'startWorker', [$swoole, 0]);

    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = random_int(1000, 999999);
    $request->header = ['host' => 'localhost'];
    $request->server = [
        'request_uri' => '/app/'.config('lightspeed.reverb_compat.app_key'),
        'remote_addr' => '127.0.0.1',
    ];

    driveLightspeed($server, 'handleOpen', [$swoole, $request]);

    expect($swoole->disconnected)->toContain($request->fd)
        ->and(array_column($swoole->pushed, 'event'))->not->toContain('pusher:connection_established');
});

test('a healthy worker still accepts websocket connections', function () {
    // The positive control for the refusal above.
    $server = workerHealthServer();
    $swoole = WorkerHealthSwooleServer::make();

    driveLightspeed($server, 'startWorker', [$swoole, 0]);

    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = random_int(1000, 999999);
    $request->header = ['host' => 'localhost'];
    $request->server = [
        'request_uri' => '/app/'.config('lightspeed.reverb_compat.app_key'),
        'remote_addr' => '127.0.0.1',
    ];

    driveLightspeed($server, 'handleOpen', [$swoole, $request]);

    expect($swoole->disconnected)->not->toContain($request->fd)
        ->and(array_column($swoole->pushed, 'event'))->toContain('pusher:connection_established');
});

// ---------------------------------------------------------------------------
// The mutation that made every one of the above necessary: a workerStart body
// that does not run at all.
// ---------------------------------------------------------------------------

test('a request worker arms the machinery it is the only thing that can arm', function () {
    // Deleting the whole workerStart body left 371 tests green, which is what a
    // callback whose every effect is a side effect on Redis and on timers looks
    // like from a suite that only ever drove frames.
    $worker = new WorkerHealthOctaneWorker();
    $server = workerHealthServer(['httpWorker' => $worker]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    expect($worker->booted)->toBeTrue()
        // The two sweepers, which are the only things that reconcile a grant
        // nobody is using and a presence row whose worker was killed.
        ->and(readLightspeedProperty($server, 'grantSweepTimerId'))->not->toBeNull()
        ->and(readLightspeedProperty($server, 'presenceSweepTimerId'))->not->toBeNull()
        // The server every one of those timers acts through. Without it each
        // tick returns immediately and reconciles nothing.
        ->and(readLightspeedProperty($server, 'swoole'))->not->toBeNull();
});

test('the health flag is cleared when the worker stops', function () {
    // A worker on its way down must stop claiming to be a load balancer target
    // before it stops answering, not after.
    $server = workerHealthServer();

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    expect(readLightspeedProperty($server, 'ready'))->toBeTrue();

    driveLightspeed($server, 'stopWorker', []);

    $response = new WorkerHealthResponse();
    driveLightspeed($server, 'handleRequest', [
        WorkerHealthRequest::make('/healthz', 8000),
        $response,
        8000,
        true,
    ]);

    expect($response->sentStatus)->toBe(503);
});


// ---------------------------------------------------------------------------
// The other unguarded callback. `workerStart` was not the only one.
// ---------------------------------------------------------------------------

/** An Octane worker whose terminate() throws, as a host WorkerStopping listener can. */
class UnstoppableOctaneWorker extends WorkerHealthOctaneWorker
{
    public function terminate(): void
    {
        throw new \RuntimeException('a WorkerStopping listener threw');
    }
}

test('a shutdown step that throws does not escape the workerStop callback', function () {
    // `terminate()` dispatches Octane's WorkerStopping event into the host
    // application, so this is somebody else's code throwing inside a Swoole
    // callback that has no exception boundary of its own.
    $server = workerHealthServer(['httpWorker' => new UnstoppableOctaneWorker()]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    $escaped = null;

    try {
        driveLightspeed($server, 'stopWorker', []);
    } catch (\Throwable $e) {
        $escaped = $e;
    }

    expect($escaped)->toBeNull()
        ->and(array_column(workerHealthOperatorLog($server)->failures, 'surface'))
        ->toContain('workerStop');
});

test('a shutdown step that throws does not skip the steps after it', function () {
    // The half that matters. `terminate()` sits in the MIDDLE of the sequence,
    // so a throw there used to leave the relay attached, the owner-command
    // poller holding its lease, and worker identity still bound.
    $server = workerHealthServer(['httpWorker' => new UnstoppableOctaneWorker()]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    expect(app(WorkerContext::class)->workerId())->toBe(0);

    driveLightspeed($server, 'stopWorker', []);

    // The last step in the sequence, three steps after the one that threw.
    expect(app(WorkerContext::class)->workerId())->toBeNull();
});

// ---------------------------------------------------------------------------
// What a drained worker actually has left. Pinned, because the docblock on
// Workers\WorkerHealth and the health-check section of docs/PRODUCTION.md both
// said it "keeps serving the application" and it does not.
// ---------------------------------------------------------------------------

test('a worker that could not attach the relay serves no application requests either', function () {
    // bringWorkerUp() attaches the relay near the START and boots the
    // application near the END, so the cause the documentation names -- Redis
    // unreachable at worker start -- lands before there is an application in
    // the process. `/healthz` answering 503 is therefore not the extent of it:
    // every application route answers 503 too, forever, because nothing retries
    // the attach and nothing marks the worker ready again.
    //
    // If this test ever fails because the application now boots first, that is
    // an improvement -- but the health-check section of docs/PRODUCTION.md is
    // then wrong in the other direction and has to be rewritten with it.
    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    $response = new WorkerHealthResponse();
    driveLightspeed($server, 'handleRequest', [
        WorkerHealthRequest::make('/dashboard', 8000),
        $response,
        8000,
        true,
    ]);

    expect($response->sentStatus)->toBe(503)
        ->and($response->decodedBody()['error'])->toBe('Lightspeed HTTP worker is not ready.');
});

test('a healthy worker does reach the application, so the refusal above is the health one', function () {
    // The positive control. Without it the assertion above passes on a router
    // that answers 503 to everything.
    $server = workerHealthServer();

    driveLightspeed($server, 'startWorker', [WorkerHealthSwooleServer::make(), 0]);

    expect(readLightspeedProperty($server, 'httpWorker')->isBooted())->toBeTrue();
});

test('a drained worker refuses the diagnostic protocol as well, so it cannot be inspected over the wire', function () {
    // The readiness gate in handleOpen() is ahead of the path branch, so it
    // covers the diagnostic protocol too: the operator's own inspection channel
    // is closed on exactly the worker an operator would want to inspect. What
    // is left is /healthz and stdout.
    config()->set('lightspeed.diagnostics.enabled', true);

    $server = workerHealthServer(['broadcastBridge' => new UnattachableBroadcastBridge()]);
    $swoole = WorkerHealthSwooleServer::make();

    driveLightspeed($server, 'startWorker', [$swoole, 0]);

    $request = (new ReflectionClass(\Swoole\Http\Request::class))->newInstanceWithoutConstructor();
    $request->fd = random_int(1000, 999999);
    $request->header = ['host' => 'localhost'];
    $request->server = ['request_uri' => '/diagnostics', 'remote_addr' => '127.0.0.1'];

    driveLightspeed($server, 'handleOpen', [$swoole, $request]);

    expect($swoole->disconnected)->toContain($request->fd)
        ->and($swoole->pushed)->toBe([]);
});
