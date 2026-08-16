<?php

namespace Lightspeed\Http;

use Illuminate\Container\Container as BaseContainer;
use Illuminate\Contracts\Container\Container;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Exceptions\TaskExceptionResult;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\TaskResult;
use Laravel\Octane\Worker;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Http\SwooleClient;
use Lightspeed\Workers\WorkerContext;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The host application's Octane worker, for the lifetime of one Swoole worker
 * process.
 *
 * This class owns the lifecycle: it boots an Octane worker and rebinds the
 * Lightspeed runtime singletons into it (so an application route or handler
 * resolves the very same channel, presence and relay state the websocket
 * surface is using), hands an HTTP request to it, runs a closure inside the
 * application container as an Octane task, and terminates it again. It exists
 * because that lifecycle is a different job from the wire protocols: the
 * realtime surface never needs to know how an application boots, and this file
 * never needs to know what a frame looks like.
 *
 * It deliberately does NOT own routing (Http\RequestRouter decides what reaches
 * it), any response of its own (a request it accepts is answered by the
 * application; a request it cannot accept is answered by the router), or the
 * behaviour of the application it boots.
 *
 * Lifecycle, per worker process:
 *
 *   workerStart -> boot()      -> Octane worker + Lightspeed bindings + owner executor
 *   request     -> handle()    -> marshalled into the application
 *   frame/relay -> runTask()   -> closure executed in the application container
 *   workerStop  -> terminate() -> worker released, isBooted() false again
 */
class OctaneWorker
{
    private ?Worker $worker = null;

    private ?SwooleClient $client = null;

    /**
     * Runtime singletons registered after construction, keyed by class name.
     *
     * The constructor list is the set this worker needs for its own work; this
     * is for state that only has to be the SAME object on both sides (the
     * websocket surface and the application container) without this class
     * having any use for it. Per-message authorization is the case that exists:
     * the server holds the grants of its own sockets, `Lightspeed::revoke()`
     * runs in an ordinary HTTP request inside this very process, and if those
     * are two objects the revoke drops connections on every worker except the
     * one that served it. The relay cannot cover for that, because it
     * deliberately never replays a process's own publishes back to it.
     *
     * @var array<class-string, object>
     */
    private array $sharedInstances = [];

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly BroadcastBridge $broadcastBridge,
        private readonly ConnectionRegistry $connectionRegistry,
        private readonly ResourceRouter $resourceRouter,
        private readonly OwnerCommandBus $ownerCommandBus,
        private readonly PresenceStore $presenceStore,
        private readonly WorkerContext $workerContext,
        private readonly RedisRelay $relay,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * Register further singletons to rebind into the application container.
     *
     * Keyed by concrete class, so registering the same class twice replaces
     * rather than duplicates. Must be called before boot(); afterwards there is
     * an application already holding whatever it built for itself.
     */
    public function share(object ...$instances): void
    {
        foreach ($instances as $instance) {
            $this->sharedInstances[$instance::class] = $instance;
        }
    }

    /**
     * Is there an application worker able to take work right now?
     *
     * Worker and client are set and cleared together, so either one answers the
     * question; both are checked because a half-booted pair would be a bug the
     * callers should see as "not ready" rather than fail on later.
     */
    public function isBooted(): bool
    {
        return $this->worker !== null && $this->client !== null;
    }

    public function boot(SwooleServer $server): void
    {
        $this->client = new SwooleClient();
        $this->worker = new Worker(new ApplicationFactory(base_path()), $this->client);

        // The Swoole server is the one instance that has to be in place before
        // the application bootstraps: nothing binds `Swoole\Http\Server`, so an
        // initial instance survives, and Octane's task and coroutine
        // dispatchers decide what they can do by asking whether it is bound.
        $this->worker->boot([
            SwooleHttpServer::class => $server,
        ]);

        // Everything Lightspeed owns is bound AFTER boot, and it has to be.
        // ApplicationFactory applies initial instances first and bootstraps
        // second, and LightspeedServiceProvider::register() calls singleton()
        // on every one of these classes: a singleton() binding drops any
        // instance already registered for that abstract. An initial instance
        // would therefore be silently replaced by a container-built one.
        //
        // The invariant this preserves: the objects the Server holds and the
        // objects resolved inside the application worker are the SAME objects.
        // If they ever fork, owner routing, presence and channel state run on
        // two copies of themselves and diverge without any error.
        $app = $this->worker->application();
        $this->bindRuntimeInstances($app);
        $this->ownerCommandBus->useApplicationContainer($app);
        $this->ownerCommandBus->useExecutor(function (OwnerCommand $command): ?array {
            return $this->runTask(function () use ($command) {
                return app(OwnerCommandDispatcher::class)->dispatch(new OwnerCommand(
                    requestId: $command->requestId,
                    resourceId: $command->resourceId,
                    command: $command->command,
                    payload: $command->payload,
                    originProcessKey: $command->originProcessKey,
                ));
            });
        });
        $this->runtimeLogger->bootHttpLogging(fn (string $event, callable|array $listener) => $app['events']->listen($event, $listener));
    }

    /**
     * Bind the runtime singletons this worker was constructed with into an
     * application container.
     *
     * Called by boot() once the application exists, and separately callable so
     * the identity it guarantees can be asserted without a Swoole server.
     */
    public function bindRuntimeInstances(Container $app): void
    {
        $app->instance(ChannelManager::class, $this->channels);
        $app->instance(BroadcastBridge::class, $this->broadcastBridge);
        $app->instance(ConnectionRegistry::class, $this->connectionRegistry);
        $app->instance(ResourceRouter::class, $this->resourceRouter);
        $app->instance(OwnerCommandBus::class, $this->ownerCommandBus);
        $app->instance(PresenceStore::class, $this->presenceStore);
        $app->instance(WorkerContext::class, $this->workerContext);
        $app->instance(RedisRelay::class, $this->relay);

        foreach ($this->sharedInstances as $abstract => $instance) {
            $app->instance($abstract, $instance);
        }
    }

    public function terminate(): void
    {
        $this->worker?->terminate();
        $this->worker = null;
        $this->client = null;
    }

    /**
     * Hand one HTTP request to the application.
     *
     * Only call this when isBooted() is true: a request that arrives before the
     * worker exists is the router's to answer, because only the router knows
     * what an unanswerable request should look like on the wire.
     */
    public function handle(Request $request, Response $response, string $requestId, float $startedAt): void
    {
        $this->normalizeForwardedHttpHeaders($request);

        $context = new RequestContext([
            'swooleRequest' => $request,
            'swooleResponse' => $response,
            'publicPath' => public_path(),
            'octaneConfig' => [
                'serve_static_files' => true,
                'static_file_headers' => [],
            ],
        ]);

        [$illuminateRequest, $context] = $this->client->marshalRequest($context);
        $illuminateRequest->attributes->set('_lightspeed_request_id', $requestId);
        $illuminateRequest->attributes->set('_lightspeed_started_at', $startedAt);
        $this->worker->handle($illuminateRequest, $context);
    }

    /**
     * Run one unit of work inside the application and return Octane's own
     * result object for it.
     *
     * This is the raw form, and the one a Swoole `task` callback wants: Octane
     * answers a failed task with a TaskExceptionResult rather than by throwing,
     * because the process that asked for the work is a DIFFERENT process and an
     * exception cannot cross that boundary. SwooleTaskDispatcher::resolve()
     * inspects the returned value and rethrows the original there.
     *
     * `$data` is whatever was handed to `$server->task()`. Octane invokes it,
     * so in practice it is a Closure or a SerializableClosure; it is typed
     * `mixed` because this class does not get to choose what an application
     * dispatches, and Octane's own handler makes the same assumption.
     *
     * @throws \RuntimeException if no application worker has been booted yet.
     */
    public function handleTask(mixed $data): mixed
    {
        if ($this->worker === null) {
            throw new \RuntimeException('Lightspeed HTTP worker is not ready.');
        }

        return $this->worker->handleTask($data);
    }

    /**
     * Run a closure inside the application container and return its value.
     *
     * The in-process form, used by the client-event, owner-command and
     * connection-closed paths, which are running in the very worker that will
     * need the answer. Octane reports a failed task as a result object rather
     * than by throwing, so the original exception is unwrapped and rethrown
     * here: a caller that wants to translate a handler failure onto the wire
     * should be able to catch it, not inspect a result type.
     *
     * THIS IS ALSO THE TASK BOUNDARY, which is why the authentication flush
     * lives here rather than at any one caller: client events, owner commands
     * and connection-closed handlers all reach the host application's code
     * through this method, and one of them leaving a user on a shared service
     * is a leak for all three. See flushAuthenticationState().
     *
     * NOT the only way into the application, and the omission is deliberate.
     * handleTask() above is entered directly by Server::handleTask(), the
     * Swoole `onTask` callback, which runs an `Octane::concurrently()` closure
     * in a TASK WORKER process. That process serves no HTTP and no websocket
     * frames, so nothing there can inherit an identity into a request; closure
     * to closure within one task worker, it can, and that is out of this
     * boundary's scope by choice rather than by oversight.
     *
     * @throws \RuntimeException if no application worker has been booted yet.
     */
    public function runTask(callable $task): mixed
    {
        // Read BEFORE the task, because Octane makes its sandbox current for
        // the duration and hands the BASE application back afterwards. What has
        // to be flushed is the application that CONTINUES once this boundary is
        // unwound, and that is the one the task interrupted: on the event loop
        // it is the base application, and inside a live request (a close runs
        // synchronously on the stack of the request that dropped the socket) it
        // is that request's sandbox, which the caller is about to make current
        // again.
        $interrupted = BaseContainer::getInstance();

        try {
            $taskResult = $this->handleTask($task);
        } finally {
            $this->flushAuthenticationState($interrupted);
        }

        if ($taskResult instanceof TaskExceptionResult) {
            throw $taskResult->getOriginal();
        }

        return $taskResult instanceof TaskResult ? $taskResult->result : $taskResult;
    }

    /**
     * Undo what the task may have done to a service the sandbox does not own.
     *
     * THE SANDBOX IS A SHALLOW CLONE. Octane clones the application, so the
     * clone's own bindings are its own, but every instance that was already
     * RESOLVED on the base application is the SAME OBJECT in both. The auth
     * manager is one of them, and a warm one at that (`auth` is in Octane's
     * services-to-warm list), so a handler calling Auth::loginUsingId() sets a
     * user on a manager the clone does not own, and throwing the clone away
     * does not touch it.
     *
     * WHO INHERITS IT, precisely, because the obvious answer is wrong. Not the
     * next HTTP request: Octane's own Listeners\FlushAuthenticationState is
     * bound to RequestReceived, which is dispatched before the kernel runs, so
     * a request arrives clean whatever a task left behind. What inherits it is
     *
     *   the next TASK, on any of the three paths, because a task boundary is
     *     not a request boundary and nothing was running that listener for us.
     *     Proven live: one client event calling Auth::guard()->setUser() and a
     *     second client event on the same socket reading Auth::id() saw the
     *     first event's user until this flush existed;
     *   the REST OF A LIVE REQUEST that a task interrupted, because a close
     *     runs synchronously on the stack of the request that dropped the
     *     socket (Channels\ChannelManager::closeDroppedFds calls
     *     $server->close()), and that request has already passed its own
     *     RequestReceived.
     *
     * This is that listener's behaviour, not an invention of ours, applied to
     * the application the task interrupted.
     *
     * ONLY THAT ONE. The other three flushes at Octane's request boundary
     * (session, queued cookies, locale) are about state a REQUEST owns, and
     * FlushSessionState in particular flushes and regenerates the session:
     * running it here, on a task that can land inside a live request, would
     * destroy that request's session to protect against a handler that touched
     * nothing. A handler that wants those flushed can flush them itself.
     *
     * Guarded, because the callers include Swoole `close` callbacks and timer
     * ticks running on the event loop with no coroutine scheduler under them,
     * where an escaping exception ends the worker serving every other
     * connection.
     */
    private function flushAuthenticationState(BaseContainer $application): void
    {
        try {
            // The same questions Octane asks: an application that never
            // resolved these answers no to both, and a container that cannot
            // answer at all throws into the catch below.
            if ($application->resolved('auth.driver')) {
                $application->forgetInstance('auth.driver');
            }

            if (!$application->resolved('auth')) {
                return;
            }

            $auth = $application->make('auth');
            $auth->setApplication($application);
            $auth->forgetGuards();
        } catch (\Throwable) {
            // A worker is not worth losing over an auth manager that could not
            // be asked to forget a guard it may not even have.
        }
    }

    /**
     * A proxied websocket upgrade that was downgraded to plain HTTP can still
     * carry `Connection: upgrade` with no `Upgrade` header, which the HTTP
     * stack would treat as a protocol switch it can never complete.
     */
    private function normalizeForwardedHttpHeaders(Request $request): void
    {
        $connection = strtolower((string) ($request->header['connection'] ?? ''));
        $upgrade = strtolower((string) ($request->header['upgrade'] ?? ''));

        if ($connection === 'upgrade' && $upgrade === '') {
            $request->header['connection'] = 'keep-alive';
            $request->server['http_connection'] = 'keep-alive';
        }
    }
}
