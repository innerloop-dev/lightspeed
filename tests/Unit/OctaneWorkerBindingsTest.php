<?php

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\LightspeedServiceProvider;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;

/**
 * One invariant, in two halves.
 *
 * The websocket surface and the host application must share one copy of the
 * runtime state. The Server holds channel, presence, relay and owner-routing
 * objects; an application route or an owner-command handler resolves them from
 * the container. If those ever become two sets of objects, nothing errors, 
 * presence and owner routing simply operate on different state and drift.
 *
 * Octane's ApplicationFactory applies initial instances FIRST and bootstraps
 * the application SECOND, and LightspeedServiceProvider::register() calls
 * singleton() on every one of these classes. A singleton() binding drops any
 * instance already registered for that abstract, so:
 *
 *   instance() -> provider singleton() -> make()  ==  a NEW object (dead)
 *   provider singleton() -> instance() -> make()  ==  the SAME object (live)
 *
 * The first test pins the ordering that makes an initial instance dead. The
 * second pins that OctaneWorker's own binding step, which boot() runs after the
 * application exists, actually preserves identity for all eight singletons.
 */

/**
 * A bare second application container, standing in for the one Octane builds.
 *
 * Constructing a Foundation\Application makes it the globally current
 * container, which would quietly redirect every `app()` call in the test
 * process; the previous container is put back so this one is only ever reached
 * through the variable holding it, exactly like the worker's application.
 */
function workerApplicationContainer(): Application
{
    $current = Container::getInstance();

    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new ConfigRepository());

    Container::setInstance($current);

    return $app;
}

test('an instance registered before the provider is replaced by the provider singleton', function () {
    $app = workerApplicationContainer();
    $channels = new ChannelManager();

    // This is exactly what ApplicationFactory::bootstrap() does with the
    // initial instances Worker::boot() is handed.
    $app->instance(ChannelManager::class, $channels);

    // ...and this is RegisterProviders, which runs afterwards.
    (new LightspeedServiceProvider($app))->register();

    expect($app->make(ChannelManager::class))
        ->toBeInstanceOf(ChannelManager::class)
        ->not->toBe($channels);
});

test('the worker rebinds every runtime singleton as the same instance the server holds', function () {
    $app = workerApplicationContainer();
    (new LightspeedServiceProvider($app))->register();

    $runtime = [
        ChannelManager::class => app(ChannelManager::class),
        BroadcastBridge::class => app(BroadcastBridge::class),
        ConnectionRegistry::class => app(ConnectionRegistry::class),
        ResourceRouter::class => app(ResourceRouter::class),
        OwnerCommandBus::class => app(OwnerCommandBus::class),
        PresenceStore::class => app(PresenceStore::class),
        WorkerContext::class => app(WorkerContext::class),
        RedisRelay::class => app(RedisRelay::class),
    ];

    // Every one of these resolves to something else before the worker binds
    // them: the application container built its own on the provider bindings.
    foreach ($runtime as $abstract => $instance) {
        expect($app->make($abstract))->not->toBe($instance);
    }

    $worker = new OctaneWorker(
        $runtime[ChannelManager::class],
        $runtime[BroadcastBridge::class],
        $runtime[ConnectionRegistry::class],
        $runtime[ResourceRouter::class],
        $runtime[OwnerCommandBus::class],
        $runtime[PresenceStore::class],
        $runtime[WorkerContext::class],
        $runtime[RedisRelay::class],
        app(RuntimeLogger::class),
    );

    $worker->bindRuntimeInstances($app);

    foreach ($runtime as $abstract => $instance) {
        expect($app->make($abstract))->toBe($instance);
    }
});

/**
 * The same invariant, for the two objects revocation depends on.
 *
 * `Lightspeed::revoke()` runs in an ordinary HTTP request, inside the very
 * process that holds the sockets, and the connections it has to drop are the
 * ones the Server is holding. If the application container resolves its own
 * ConnectionGrants and RevocationLog, the revoke drops connections on every
 * worker EXCEPT the one that served it. And the relay cannot cover for that,
 * because it never replays a process's own publishes back to it.
 *
 * These two do not go in the worker's constructor list, because the worker has
 * no use for them; only their identity matters. share() is the seam.
 */
test('the worker rebinds the shared authorization singletons as the same instances', function () {
    $app = workerApplicationContainer();
    (new LightspeedServiceProvider($app))->register();

    $grants = app(ConnectionGrants::class);
    $revocations = app(RevocationLog::class);

    $worker = new OctaneWorker(
        app(ChannelManager::class),
        app(BroadcastBridge::class),
        app(ConnectionRegistry::class),
        app(ResourceRouter::class),
        app(OwnerCommandBus::class),
        app(PresenceStore::class),
        app(WorkerContext::class),
        app(RedisRelay::class),
        app(RuntimeLogger::class),
    );

    expect($app->make(ConnectionGrants::class))->not->toBe($grants)
        ->and($app->make(RevocationLog::class))->not->toBe($revocations);

    $worker->share($grants, $revocations);
    $worker->bindRuntimeInstances($app);

    expect($app->make(ConnectionGrants::class))->toBe($grants)
        ->and($app->make(RevocationLog::class))->toBe($revocations);
});

/**
 * THE RETHROW, which nothing was watching.
 *
 * Deleting
 *
 *     if ($taskResult instanceof TaskExceptionResult) {
 *         throw $taskResult->getOriginal();
 *     }
 *
 * from runTask() left the entire suite green, and it is not a tidy-up: Octane
 * does not throw out of handleTask(), it RETURNS a TaskExceptionResult. So
 * without that line a failed handler comes back as an object that every caller
 * then treats as a successful result.
 *
 * What that costs at the two call sites:
 *
 *   a client event   ClientEvents\ClientEventGate has a try/catch around
 *                    runTask() whose whole job is to translate a handler
 *                    failure into a `pusher:error`. With nothing thrown the
 *                    catch never runs, and the result object falls through the
 *                    `$result !== null` branch, reading `errorCode` and
 *                    `requestId` off a TaskExceptionResult, which are not
 *                    properties it has. Both read as null, so the method
 *                    returns having told the client nothing and having NOT
 *                    broadcast the event. The client cannot tell a failed
 *                    handler from a slow network.
 *
 *   an owner command Owner\OwnerCommandBus's executor returns the value to a
 *                    peer worker that is blocked waiting for it, and a
 *                    TaskExceptionResult is not an array.
 *
 * Which is why the exception is unwrapped HERE rather than at each caller: a
 * caller that wants to translate a handler failure onto the wire should be able
 * to catch it, not know to inspect a result type.
 */

/** An Octane worker whose handleTask returns whatever the test wants. */
final class TaskResultWorker extends \Laravel\Octane\Worker
{
    public function __construct(private mixed $answer)
    {
    }

    public function handleTask($data)
    {
        return $this->answer;
    }
}

/** An OctaneWorker holding a given Octane worker, without booting Laravel. */
function octaneWorkerReturning(mixed $answer): OctaneWorker
{
    $worker = (new ReflectionClass(OctaneWorker::class))->newInstanceWithoutConstructor();

    $property = new ReflectionProperty(OctaneWorker::class, 'worker');
    $property->setAccessible(true);
    $property->setValue($worker, new TaskResultWorker($answer));

    return $worker;
}

test('a task that failed comes back out of runTask as a thrown exception', function () {
    $worker = octaneWorkerReturning(
        \Laravel\Octane\Exceptions\TaskExceptionResult::from(new \RuntimeException('the handler blew up'))
    );

    expect(fn () => $worker->runTask(fn () => null))
        ->toThrow(\Laravel\Octane\Exceptions\TaskException::class, 'the handler blew up');
});

test('a task that succeeded comes back as its value, unwrapped', function () {
    // The positive control. A runTask() that threw unconditionally would
    // satisfy the assertion above while failing every client event.
    $worker = octaneWorkerReturning(new \Laravel\Octane\Swoole\TaskResult(['ok' => true]));

    expect($worker->runTask(fn () => null))->toBe(['ok' => true]);
});

test('a task answered without a result wrapper is returned as-is', function () {
    // Octane's Worker returns a TaskResult, but handleTask() is typed `mixed`
    // and the unwrapping is written to tolerate a bare value. Pinned so the
    // tolerance is deliberate rather than accidental.
    $worker = octaneWorkerReturning(['bare' => 'value']);

    expect($worker->runTask(fn () => null))->toBe(['bare' => 'value']);
});

test('running a task before the worker is booted is refused rather than fatal', function () {
    $worker = (new ReflectionClass(OctaneWorker::class))->newInstanceWithoutConstructor();

    expect(fn () => $worker->runTask(fn () => null))
        ->toThrow(RuntimeException::class, 'not ready');

    expect(fn () => $worker->handleTask(fn () => null))
        ->toThrow(RuntimeException::class, 'not ready');
});
