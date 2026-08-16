<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\GrantCheck;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventGate;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Http\SwooleClient;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Protocol\Delivery;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file defends: no task this package runs can leave an
 * authenticated identity behind for whatever the worker does next.
 *
 * THE LEAK. Octane's task sandbox is a shallow clone. A binding a handler ADDS
 * dies with the clone, which is what a container test sees; a service that was
 * already RESOLVED on the base application is the SAME OBJECT in both, which is
 * what a container test misses. The auth manager is that service, and Octane
 * warms it (`auth` is in its defaultServicesToWarm), so a handler calling
 * Auth::loginUsingId() or Auth::guard()->setUser() left that user on a manager
 * the clone does not own.
 *
 * WHO INHERITED IT, and the obvious answer is wrong. Not the next HTTP request:
 * Octane's own cleanup, Listeners\FlushAuthenticationState, is bound to
 * RequestReceived and dispatched before the kernel runs, so a request arrives
 * clean whatever a task left behind. What inherited it was the next TASK (a
 * task boundary is not a request boundary, so nothing ran that listener for
 * us), and the REST OF A LIVE REQUEST that a synchronous close interrupted
 * mid-flight, which had already passed its own RequestReceived.
 *
 * The first of those was reproduced against a running server: one client event
 * calling Auth::guard()->setUser(), a second client event on the same socket
 * reading Auth::id(), and the second saw the first event's user.
 *
 * WHY HERE. The leak was proven first on the connection-closed path, which got
 * its own copy of the flush. But a client event and an owner command reach the
 * host application's code through the same door, Http\OctaneWorker::runTask(),
 * and ran with no flush at all. The flush therefore belongs to that door, once,
 * and this file pins it from both sides: at the boundary itself, which is the
 * owner-command path's mechanism as much as anyone's, and through the real
 * client-event gate, which is where an application actually meets it.
 *
 * ConnectionClosedHookTest covers the third caller and the container restore
 * that is the close path's alone.
 */

/** Somebody for a handler to log in. */
final class TaskFlushUser implements Illuminate\Contracts\Auth\Authenticatable
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

/** A client event handler that authenticates somebody, the way a real one reads its user. */
final class TaskFlushAuthenticatingHandler implements ClientEventHandler
{
    public static int $calls = 0;

    /** What the guard answered INSIDE the task, which is the positive control. */
    public static mixed $sawInside = null;

    public function handle(ClientEvent $event): ?ClientEventResult
    {
        self::$calls++;

        Illuminate\Support\Facades\Auth::guard()->setUser(new TaskFlushUser());

        self::$sawInside = Illuminate\Support\Facades\Auth::guard()->user();

        // handled(), so the event is not fanned out: this test is about the
        // container, and a broadcast would drag Redis into it for nothing.
        return ClientEventResult::handled();
    }
}

/** Swallows the frames the gate pushes, in place of a real websocket. */
final class TaskFlushSwoole extends SwooleServer
{
    /** @var list<array> */
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

/**
 * A booted HTTP worker whose tasks run in a REAL Octane sandbox.
 *
 * Not a stub that calls the closure inline, because inline is precisely the
 * bug: the point of the flush is what Octane's clone-and-discard does NOT undo,
 * and a fake that never cloned would make every assertion below pass against
 * code that flushes nothing.
 */
function taskFlushWorker(?object $base = null): OctaneWorker
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

/** The gate, wired to a sandbox-running worker and to nothing that needs a socket. */
function taskFlushGate(OctaneWorker $worker): ClientEventGate
{
    return new ClientEventGate(
        app(ChannelManager::class),
        app(ConnectionGrants::class),
        app(GrantCheck::class),
        $worker,
        app(Delivery::class),
        app(RuntimeLogger::class),
    );
}

/**
 * Warm the auth manager on the BASE application.
 *
 * Resolved on the base application first, which is what makes it shared: an
 * unresolved service would be built fresh inside the sandbox, die with the
 * clone, and prove nothing.
 *
 * The manager is left pointing where it is. Sabotaging that pointer here would
 * be convenient for the setApplication() half of the flush and would break the
 * other half outright: an auth manager holding a container with no `config`
 * cannot build a guard at all, so a handler asked to authenticate somebody
 * would throw instead, and every "nobody is logged in afterwards" assertion
 * would pass on a handler that never logged anybody in. The pointer is
 * sabotaged in the two tests below that are ABOUT the pointer, where the task
 * touches no guard.
 *
 * @return array{0: object, 1: object, 2: ReflectionProperty}
 */
function taskFlushWarmAuth(Illuminate\Container\Container $application): array
{
    $shared = $application->make('auth');
    $driver = $application->make('auth.driver');

    expect($application->resolved('auth'))->toBeTrue()
        ->and($application->resolved('auth.driver'))->toBeTrue();

    $pointer = new ReflectionProperty($shared::class, 'app');
    $pointer->setAccessible(true);

    return [$shared, $driver, $pointer];
}

beforeEach(function () {
    TaskFlushAuthenticatingHandler::$calls = 0;
    TaskFlushAuthenticatingHandler::$sawInside = null;
});

test('a client event handler cannot leave an authenticated user behind for the next event', function () {
    config()->set('lightspeed.client_event_handlers', [TaskFlushAuthenticatingHandler::class]);

    $application = Illuminate\Container\Container::getInstance();
    [$shared, $driver, $pointer] = taskFlushWarmAuth($application);

    $channel = 'private-lightspeed-task-flush-'.bin2hex(random_bytes(6));
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, "{$fd}.".random_int(1000, 999999), []);
    app(ChannelManager::class)->subscribe($fd, $channel, null);

    taskFlushGate(taskFlushWorker($application))->handlePusherClientEvent(
        TaskFlushSwoole::make(),
        $fd,
        $channel,
        'client-typing',
        ['message' => 'hi'],
        96,
    );

    // The positive control: the handler really ran, and really did authenticate
    // somebody. Without this the assertions below pass on an event that never
    // reached the application at all.
    expect(TaskFlushAuthenticatingHandler::$calls)->toBe(1)
        ->and(TaskFlushAuthenticatingHandler::$sawInside)->toBeInstanceOf(TaskFlushUser::class);

    expect($application->make('auth'))->toBe($shared)
        ->and($shared->guard()->user())->toBeNull()
        // The singleton driver instance goes with the guards, so the next
        // resolution builds a new one; leaving it bound would hand the next
        // task a guard built around the user just forgotten.
        ->and($application->make('auth.driver'))->not->toBe($driver)
        ->and($pointer->getValue($shared))->toBe($application);
});

test('a task that authenticated nobody still leaves the manager pointed at a usable application', function () {
    // The other half of what Octane's listener corrects, and why it calls
    // setApplication() BEFORE forgetting the guards: a guard rebuilt afterwards
    // out of the wrong container's services is a guard reading another
    // application's config. The task here touches no guard, because a manager
    // aimed at a container with no `config` cannot build one.
    $application = Illuminate\Container\Container::getInstance();
    [$shared, , $pointer] = taskFlushWarmAuth($application);

    $pointer->setValue($shared, new Illuminate\Container\Container());

    taskFlushWorker($application)->runTask(fn () => null);

    expect($pointer->getValue($shared))->toBe($application)
        ->and($shared->guard()->user())->toBeNull();
});

test('any task is flushed at the boundary, whatever dispatched it', function () {
    // The boundary itself, which is what makes this one flush rather than three.
    // An owner command reaches the application through exactly this call:
    // Http\OctaneWorker::boot() installs an executor on Owner\OwnerCommandBus
    // whose whole body is a runTask() of the owner-command dispatch. There is no
    // unit harness for that executor (every owner test injects its own, because
    // installing the real one means booting a Swoole server and a real Octane
    // application), so the mechanism is pinned here, where it lives.
    $application = Illuminate\Container\Container::getInstance();
    [$shared, $driver, $pointer] = taskFlushWarmAuth($application);

    $answer = taskFlushWorker($application)->runTask(function () {
        Illuminate\Support\Facades\Auth::guard()->setUser(new TaskFlushUser());

        return ['ran' => true];
    });

    expect($answer)->toBe(['ran' => true])
        ->and($application->make('auth'))->toBe($shared)
        ->and($shared->guard()->user())->toBeNull()
        ->and($application->make('auth.driver'))->not->toBe($driver)
        ->and($pointer->getValue($shared))->toBe($application);
});

test('a nested task resets the guards of the task it is nested inside', function () {
    // THE COST OF A PROCESS-WIDE RESET, and the reason docs/extending.md tells
    // handler authors to hold an identity in a local variable rather than
    // re-reading the Auth facade after they broadcast.
    //
    // The flush is on the shared manager, so it is not scoped to the task that
    // triggered it. A handler broadcasting can reach a dead fd;
    // Channels\ChannelManager::closeDroppedFds closes it, Swoole runs the close
    // callback on that stack, and Connections\ConnectionClosedDispatcher runs a
    // task INSIDE the handler's own call. The inner boundary's flush lands on
    // the outer handler's guards. The nested runTask below is that shape.
    $application = Illuminate\Container\Container::getInstance();
    [$shared] = taskFlushWarmAuth($application);

    $worker = taskFlushWorker($application);

    [$before, $after] = $worker->runTask(function () use ($worker) {
        Illuminate\Support\Facades\Auth::guard()->setUser(new TaskFlushUser());

        $before = Illuminate\Support\Facades\Auth::id();

        // What a broadcast that closed a dead socket does, from here.
        $worker->runTask(fn () => null);

        return [$before, Illuminate\Support\Facades\Auth::id()];
    });

    expect($before)->toBe('the-handler-s-user')
        ->and($after)->toBeNull()
        ->and($shared->guard()->user())->toBeNull();
});

test('the value of a task survives the flush', function () {
    // The positive control for the try/finally: a flush that swallowed the
    // return value, or ran instead of returning it, would satisfy every
    // assertion above while breaking every caller. Owner\OwnerCommandBus hands
    // this array straight to a peer worker that is blocked waiting for it.
    expect(taskFlushWorker()->runTask(fn () => ['answered' => 'yes']))->toBe(['answered' => 'yes']);
});

test('a task that threw is flushed too', function () {
    // The failure path, which is the one a handler bug actually takes: an
    // exception unwound out of runTask() must not carry an identity out with it.
    $application = Illuminate\Container\Container::getInstance();
    [$shared, $driver] = taskFlushWarmAuth($application);

    expect(fn () => taskFlushWorker($application)->runTask(function () {
        Illuminate\Support\Facades\Auth::guard()->setUser(new TaskFlushUser());

        throw new \RuntimeException('the handler exploded');
    }))->toThrow(\Laravel\Octane\Exceptions\TaskException::class, 'the handler exploded');

    expect($shared->guard()->user())->toBeNull()
        ->and($application->make('auth.driver'))->not->toBe($driver);
});

test('the flush targets the application the task interrupted, not the one Octane hands back', function () {
    // WHOSE APPLICATION. A close runs synchronously inside the request that
    // dropped the socket (Channels\ChannelManager::closeDroppedFds calls
    // $server->close()), so the container that CONTINUES after the task is that
    // request's sandbox, not the base application Octane restores. The manager
    // has to be pointed back at the former, or the rest of that live request
    // rebuilds its guards out of a container it is not running in.
    $application = Illuminate\Container\Container::getInstance();
    [$shared, $driver, $pointer] = taskFlushWarmAuth($application);

    $interrupted = clone $application;

    try {
        Illuminate\Container\Container::setInstance($interrupted);

        taskFlushWorker($application)->runTask(function () {
            Illuminate\Support\Facades\Auth::guard()->setUser(new TaskFlushUser());
        });
    } finally {
        Illuminate\Container\Container::setInstance($application);
    }

    expect($pointer->getValue($shared))->toBe($interrupted)
        ->and($shared->guard()->user())->toBeNull()
        // Forgotten on the container that continues, which is the one whose
        // next resolution would otherwise hand back the guard just flushed.
        ->and($interrupted->make('auth.driver'))->not->toBe($driver);
});
