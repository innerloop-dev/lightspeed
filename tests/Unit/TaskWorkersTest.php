<?php

use Laravel\Octane\Exceptions\TaskException;
use Laravel\Octane\Exceptions\TaskExceptionResult;
use Laravel\Octane\Swoole\TaskResult;
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
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: `--task-workers` is a dial an operator
 * can actually turn.
 *
 * `task_worker_num` was passed to Swoole by `Server::serve()` and no `onTask`
 * callback was ever registered. Swoole checks that pairing before it binds
 * anything, so the server did not start degraded: it did not start at all.
 * Reproduced on Swoole 6.2.0, with the banner having already printed
 * `Task Workers ... 1`:
 *
 *   WARNING Server::start_check() (ERRNO 9015): require 'onTask' callback
 *   Fatal error: Swoole\Server::start(): failed to start server.
 *
 * The dial is exposed three times over (`--task-workers`,
 * `LIGHTSPEED_TASK_WORKER_NUM`, and `lightspeed.server.task_worker_num`) and
 * docs/PRODUCTION.md talks about when to raise it, so an operator following
 * this package's own documentation got a server that could not boot.
 *
 * WHY THE PAIR IS REGISTERED RATHER THAN THE DIAL REFUSED. Refusing was the
 * other option, and it would be the right one if nothing in a Lightspeed
 * process could ever produce a Swoole task. Something can. Http\OctaneWorker
 * binds `Swoole\Http\Server` into the application container on purpose, and
 * that binding is exactly what Octane's own task dispatcher looks for:
 *
 *   Laravel\Octane\Concerns\ProvidesConcurrencySupport::tasks()
 *     app()->bound(DispatchesTasks::class) => app(DispatchesTasks::class),
 *     app()->bound(Server::class)          => new SwooleTaskDispatcher,
 *
 * so a host application calling `Octane::concurrently()` under Lightspeed
 * reaches `$server->taskWaitMulti()`, on this server, today. Refusing the dial
 * would not remove that caller; it would only guarantee it can never be served.
 * Octane's own Swoole runtime registers the identical pair, four lines of it,
 * in bin/swoole-server.
 */

/** Records which Swoole events were registered, in place of a real server. */
class TaskWorkerRecordingServer extends SwooleServer
{
    /** @var array<string, callable> event name => callback */
    public array $registered = [];

    public static function make(int $workerNum = 1): self
    {
        $server = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $server->setting = ['worker_num' => $workerNum];

        return $server;
    }

    public function on(string $eventName, callable $callback): bool
    {
        $this->registered[strtolower($eventName)] = $callback;

        return true;
    }
}

/** An Octane worker that records its lifecycle instead of booting Laravel. */
class TaskWorkerOctaneWorker extends OctaneWorker
{
    public bool $booted = false;

    /** @var list<mixed> everything handed to handleTask(), in order */
    public array $tasks = [];

    public mixed $taskAnswer = null;

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

    public function handleTask(mixed $data): mixed
    {
        $this->tasks[] = $data;

        return $this->taskAnswer;
    }
}

/**
 * Assemble a Server whose collaborators are the container's, with an Octane
 * worker that records rather than boots.
 */
function taskWorkerServer(TaskWorkerOctaneWorker $worker): Server
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
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => $worker,
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    return $server;
}

test('the server registers the task and finish callbacks Swoole demands of a task-worker server', function () {
    // The whole blocker in one assertion. Swoole's start_check() refuses a
    // server with task_worker_num > 0 and no 'task' callback, and refuses
    // BEFORE the listener exists, so there is no degraded mode to observe: the
    // process dies with a fatal error.
    $swoole = TaskWorkerRecordingServer::make();

    driveLightspeed(app(Server::class), 'registerCallbacks', [$swoole, '127.0.0.1', 8080, 8000, false]);

    expect(array_keys($swoole->registered))
        ->toContain('task')
        ->and(array_keys($swoole->registered))->toContain('finish');
});

test('the callbacks that were already registered are still registered', function () {
    // The positive control. Every assertion above passes on a registerCallbacks
    // that registers everything under the sun, and the five callbacks the
    // server has always needed are the ones a regression would take away.
    $swoole = TaskWorkerRecordingServer::make();

    driveLightspeed(app(Server::class), 'registerCallbacks', [$swoole, '127.0.0.1', 8080, 8000, false]);

    expect(array_keys($swoole->registered))->toContain(
        'start',
        'workerstart',
        'workerstop',
        'request',
        'open',
        'message',
        'close',
    );
});

test('a task worker boots the application, because a task it cannot run is a task that fails', function () {
    // Swoole starts task workers as their own processes and fires workerStart
    // for each of them with an id at or above worker_num. The old early return
    // meant those processes reached the end of workerStart having booted
    // nothing, so every task handed to one would have found no application.
    $worker = new TaskWorkerOctaneWorker();
    $server = taskWorkerServer($worker);
    $swoole = TaskWorkerRecordingServer::make(workerNum: 1);

    driveLightspeed($server, 'startWorker', [$swoole, 1]);

    expect($worker->booted)->toBeTrue();
});

test('a task worker arms none of the realtime machinery, because it holds no connections', function () {
    // A task worker has no websocket connections of its own, so the sweepers,
    // the relay listeners and the owner-command poller would be timers ticking
    // against an empty registry: Redis load for nothing, and a second process
    // claiming worker identity it cannot act on.
    $worker = new TaskWorkerOctaneWorker();
    $server = taskWorkerServer($worker);
    $swoole = TaskWorkerRecordingServer::make(workerNum: 1);

    driveLightspeed($server, 'startWorker', [$swoole, 1]);

    expect(readLightspeedProperty($server, 'presenceSweepTimerId'))->toBeNull()
        ->and(readLightspeedProperty($server, 'grantSweepTimerId'))->toBeNull();
});

test('the task callback hands the payload to the application worker', function () {
    $worker = new TaskWorkerOctaneWorker();
    $worker->taskAnswer = new TaskResult('answered');
    $server = taskWorkerServer($worker);
    $swoole = TaskWorkerRecordingServer::make();

    $payload = new class {
        public function __invoke(): string
        {
            return 'answered';
        }
    };

    $result = driveLightspeed($server, 'handleTask', [$swoole, 1, 0, $payload]);

    expect($worker->tasks)->toBe([$payload])
        ->and($result)->toBeInstanceOf(TaskResult::class)
        ->and($result->result)->toBe('answered');
});

test('a task that threw comes back as a result rather than escaping the task worker', function () {
    // Octane's contract, and it is not decoration. SwooleTaskDispatcher::resolve
    // inspects the returned value for a TaskExceptionResult and rethrows it in
    // the process that asked for the work. A task callback that let the
    // exception escape instead would kill the task worker and tell the caller
    // nothing.
    $worker = new class extends TaskWorkerOctaneWorker {
        public function handleTask(mixed $data): mixed
        {
            throw new \RuntimeException('the application blew up inside the task');
        }
    };

    $server = taskWorkerServer($worker);
    $swoole = TaskWorkerRecordingServer::make();

    $result = driveLightspeed($server, 'handleTask', [$swoole, 1, 0, fn () => null]);

    // Octane rebuilds the failure as its own TaskException on the far side,
    // because the real one cannot be serialized across a process boundary. What
    // has to survive is the message, which is what an operator reads.
    expect($result)->toBeInstanceOf(TaskExceptionResult::class)
        ->and($result->getOriginal())->toBeInstanceOf(TaskException::class)
        ->and($result->getOriginal()->getMessage())->toContain('the application blew up inside the task');
});
