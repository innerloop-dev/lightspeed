<?php

namespace Lightspeed;

use Laravel\Octane\Exceptions\TaskExceptionResult;
use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\GrantCheck;
use Lightspeed\Auth\GrantSweeper;
use Lightspeed\Auth\RevocationDrops;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Channels\SubscribeLimits;
use Lightspeed\Channels\Subscriptions;
use Lightspeed\ClientEvents\ClientEventGate;
use Lightspeed\Diagnostics\DiagnosticProtocol;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Http\PublishEndpoint;
use Lightspeed\Http\RequestRouter;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Presence\PresenceSweeper;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\FrameRouter;
use Lightspeed\Protocol\Handshake;
use Lightspeed\Protocol\PusherApp;
use Lightspeed\Protocol\Teardown;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Boot\BootValidation;
use Lightspeed\Connections\ConnectionClosedDispatcher;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Connections\ConnectionSweeper;
use Lightspeed\Logging\OperatorLog;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Workers\WorkerHealth;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Swoole entry point for the Lightspeed runtime: one process serving the host
 * application's HTTP traffic through Octane and the realtime websocket surface
 * side by side.
 *
 * This class owns two things and nothing else: the LISTENERS (which sockets
 * exist, which worker they belong to, what is armed at worker start and cleared
 * at worker stop) and the GUARD around every callback body. It composes the
 * surfaces that do the work and hands each frame to one of them.
 *
 * Where the work lives:
 *
 *   boot refusals        -> Boot\BootValidation      (credentials, dials, handlers)
 *   the handshake        -> Protocol\Handshake          (which protocol, and the reply)
 *   a frame              -> Protocol\FrameRouter        (decode, log, dispatch)
 *   subscribe            -> Channels\Subscriptions      (+ Channels\SubscribeLimits)
 *   a client event       -> ClientEvents\ClientEventGate
 *   the per-message read -> Auth\GrantCheck
 *   revocation           -> Auth\RevocationDrops
 *   expiry               -> Auth\GrantSweeper
 *   presence ghosts      -> Presence\PresenceSweeper
 *   registry renewal     -> Connections\ConnectionSweeper
 *   the close            -> Protocol\Teardown
 *   writing to a socket  -> Protocol\Delivery
 *
 * The other surfaces of the same process, reached from the listeners in
 * serve():
 *
 *   HTTP requests        -> Http\RequestRouter   (health, publish, app, 404)
 *   the application      -> Http\OctaneWorker    (boot, request, task, terminate)
 *   publish requests     -> Http\PublishEndpoint (parse, verify, fan out)
 *   diagnostic verbs     -> Diagnostics\DiagnosticProtocol
 *
 * It deliberately owns NO channel state (ChannelManager), presence membership
 * (PresenceStore), cross-node fan-out (BroadcastBridge / RedisRelay), or
 * application behaviour (the configured client event and owner command
 * handlers), and none of the pure halves of the protocols: frame envelopes
 * (Protocol\Frames), subscription authorization (Protocol\SubscriptionAuthorizer),
 * publish-request signatures (Protocol\PublishRequestVerifier), path parsing
 * (Protocol\PusherPaths) and socket ids (Protocol\SocketId) are functions of
 * their inputs and are unit tested without a server.
 *
 * Two protocols share the websocket port, and they are never mixed:
 *
 *   open /app/{key}  -> Pusher connection  -> only pusher:* / pusher_internal:* frames
 *   open (any other) -> diagnostic (loopback + opt-in only)
 *                                          -> only {"type": ...} frames
 */
class Server
{
    /**
     * The longest `grant_lifetime_seconds` this server will start with.
     *
     * The number itself lives on RevocationLog, which is the class that reads
     * the dial and hands it to Redis, and is therefore the class that can
     * enforce the ceiling on every tier rather than only on the one that serves.
     *
     * This alias stays because `lightspeed:doctor` reports the ceiling before
     * the server refuses to start on it, and a second COPY of the number is how
     * a diagnostic and the thing it diagnoses drift apart.
     */
    public const MAX_GRANT_LIFETIME_SECONDS = RevocationLog::MAX_GRANT_LIFETIME_SECONDS;

    /**
     * Swoole's own implicit ceiling on a single request or frame, made explicit.
     *
     * Also the largest upload the host application can accept, because it
     * serves its HTTP through this same server; see swooleOptions().
     */
    public const DEFAULT_PACKAGE_MAX_LENGTH = 2 * 1024 * 1024;

    /** The host application's Octane worker, shared with the HTTP router. */
    private readonly OctaneWorker $httpWorker;

    private RequestRouter $requestRouter;

    private OperatorLog $operatorLog;

    private BootValidation $bootValidation;

    private AttachedServer $attachedServer;

    private Delivery $delivery;

    private Handshake $handshake;

    private Teardown $teardown;

    private FrameRouter $frameRouter;

    private GrantSweeper $grantSweeper;

    private RevocationDrops $revocationDrops;

    private PresenceSweeper $presenceSweeper;

    private ConnectionSweeper $connectionSweeper;

    private WorkerHealth $workerHealth;

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
        private readonly ConnectionGrants $connectionGrants,
        private readonly RevocationLog $revocations,
    ) {
        // The surfaces are composed here rather than resolved from the
        // container so that the application worker is unambiguously one object:
        // the HTTP router hands requests to the same worker this class runs
        // client-event tasks on, and both see the same booted/terminated state.
        $this->httpWorker = new OctaneWorker(
            $channels,
            $broadcastBridge,
            $connectionRegistry,
            $resourceRouter,
            $ownerCommandBus,
            $presenceStore,
            $workerContext,
            $relay,
            $runtimeLogger,
        );

        // Not constructor arguments of the worker, because the worker has no
        // use for them; only their identity matters. `Lightspeed::revoke()`
        // runs in an ordinary HTTP request, inside this very process, and the
        // connections it has to drop are the ones this object is holding. The
        // relay cannot make up for two copies: it never replays a process's own
        // publishes back to it, so the revoke would drop connections on every
        // worker except the one that served it.
        $this->httpWorker->share($connectionGrants, $revocations);

        $this->compose();
    }

    /**
     * Build the websocket surfaces out of the collaborators this object holds.
     *
     * One wiring, stated once, in dependency order. Every surface is given
     * exactly what it needs and nothing else, so what a class can reach is
     * visible from its constructor rather than from the whole server's.
     */
    private function compose(): void
    {
        // Before the router, because the router is one of its three readers.
        // See Workers\WorkerHealth for why the default is "not ready".
        $this->workerHealth = new WorkerHealth();

        $this->requestRouter = new RequestRouter(
            $this->httpWorker,
            new PublishEndpoint($this->broadcastBridge, $this->runtimeLogger),
            $this->runtimeLogger,
            $this->workerHealth,
        );

        $this->operatorLog = new OperatorLog();
        $this->bootValidation = new BootValidation();
        $this->attachedServer = new AttachedServer();

        $pusherApp = new PusherApp();
        $diagnosticSockets = new DiagnosticSockets();

        // The application's own notification of a connection ending, shared by
        // the two things that can observe one: the teardown, and the presence
        // sweeper reconciling a teardown that never ran.
        //
        // Given the worker rather than a container or a config repository, and
        // that is the whole point: this method runs in the process that will
        // fork, so anything captured here is the console process's, while the
        // handlers belong to the application each worker boots afterwards. The
        // worker is the one object that knows about that application, and
        // running handlers through it is what puts them in a sandbox instead of
        // in whatever container the close happened to interrupt.
        $connectionClosed = new ConnectionClosedDispatcher(
            $this->httpWorker,
            $this->runtimeLogger,
        );

        $connectionId = new ConnectionId($this->channels, $this->workerContext);
        $grantCheck = new GrantCheck($this->revocations, $this->runtimeLogger);

        $this->delivery = new Delivery($this->channels, $this->broadcastBridge);

        $this->handshake = new Handshake(
            $this->channels,
            $this->connectionRegistry,
            $this->connectionGrants,
            $diagnosticSockets,
            $pusherApp,
            $this->delivery,
            $this->runtimeLogger,
            $this->operatorLog,
        );

        $this->teardown = new Teardown(
            $this->channels,
            $this->connectionGrants,
            $this->connectionRegistry,
            $this->presenceStore,
            $diagnosticSockets,
            $connectionId,
            $this->delivery,
            $this->runtimeLogger,
            $connectionClosed,
        );

        $subscriptions = new Subscriptions(
            $this->channels,
            $this->connectionGrants,
            $this->presenceStore,
            $this->resourceRouter,
            new SubscribeLimits($this->channels),
            $grantCheck,
            $connectionId,
            $pusherApp,
            $this->delivery,
            $this->runtimeLogger,
            $this->operatorLog,
        );

        $this->frameRouter = new FrameRouter(
            $subscriptions,
            new ClientEventGate(
                $this->channels,
                $this->connectionGrants,
                $grantCheck,
                $this->httpWorker,
                $this->delivery,
                $this->runtimeLogger,
            ),
            new DiagnosticProtocol($this->channels, $this->broadcastBridge, $this->workerContext),
            $diagnosticSockets,
            $this->delivery,
            $this->runtimeLogger,
        );

        $this->revocationDrops = new RevocationDrops(
            $this->connectionGrants,
            $this->revocations,
            $this->relay,
            $subscriptions,
            $this->attachedServer,
            $this->delivery,
            $this->runtimeLogger,
        );

        $this->grantSweeper = new GrantSweeper(
            $this->connectionGrants,
            $this->revocationDrops,
            $subscriptions,
            $this->attachedServer,
            $this->delivery,
            $this->runtimeLogger,
        );

        $this->presenceSweeper = new PresenceSweeper(
            $this->channels,
            $this->presenceStore,
            $connectionId,
            $this->attachedServer,
            $this->delivery,
            $this->runtimeLogger,
            $connectionClosed,
        );

        $this->connectionSweeper = new ConnectionSweeper(
            $this->channels,
            $this->connectionRegistry,
            $this->attachedServer,
            $this->runtimeLogger,
        );
    }

    public function serve(string $host, int $port, array $settings = []): void
    {
        // Both checked before the listener exists so a misconfigured server
        // aborts the command with one clear message, rather than throwing in
        // every worker and leaving Swoole to restart them in a loop.
        //
        // Credentials first: an empty app secret is the one misconfiguration
        // that produces a server which appears to work.
        $this->bootValidation->validateCredentials();
        $this->bootValidation->validateGrantLifetime();
        $this->bootValidation->validateHandlerConfiguration();

        $server = new SwooleServer($host, $port);
        $httpPort = (int) ($settings['http_port'] ?? 8000);
        $samePort = $httpPort === $port;

        if (!$samePort) {
            $server->listen($host, $httpPort, SWOOLE_SOCK_TCP);
        }

        $server->set($this->swooleOptions($settings));

        // Captured HERE, in the process about to fork, because this pid is
        // unknowable afterwards: `$server->master_pid` read inside a worker
        // is not it (measured: a respawned worker reports its own pid). This
        // is the pid Swoole writes to pid_file, the banner prints, and every
        // worker builds its instance identity from.
        $this->workerContext->bindServingPid((int) getmypid());

        $this->registerCallbacks($server, $host, $port, $httpPort, $samePort);

        try {
            $server->start();
        } finally {
            $this->grantSweeper->shutdownGrantSweeper();
            $this->presenceSweeper->shutdownPresenceSweeper();
            $this->connectionSweeper->shutdownConnectionSweeper();
            $this->httpWorker->terminate();
            $this->broadcastBridge->detach();
            $this->ownerCommandBus->shutdownWorker();
            $this->workerContext->shutdown();
        }
    }

    /**
     * Every option this package hands to Swoole itself.
     *
     * Separate from serve() for the same reason registerCallbacks() is: an
     * option set that only exists inside a method ending in `$server->start()`
     * is an option set no test can look at, and two of these decide what the
     * server does with a connection rather than how it is configured.
     *
     * `package_max_length` is the bound on a single request or frame BEFORE any
     * code in this package sees it, and it is stated rather than left to
     * Swoole's implicit 2MB so an operator can find it. It cannot be sized to
     * the client-event limit: the same process serves the host application's
     * HTTP on the same port, so this number is also the largest upload that
     * application can accept. The real client-event bound is
     * ClientEvents\ClientEventLimits, enforced where the frame's meaning is
     * known.
     *
     * The heartbeat pair is OMITTED unless configured, because Swoole's
     * heartbeat closes a quiet connection rather than pinging it, and a client
     * that only listens is quiet while it is perfectly healthy. See the config
     * file for the measurement behind that.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function swooleOptions(array $settings): array
    {
        $heartbeatIdleTime = (int) ($settings['heartbeat_idle_time'] ?? 0);
        $heartbeatCheckInterval = (int) ($settings['heartbeat_check_interval'] ?? 0);

        // BOTH OR NEITHER, and the "neither" half is the one that matters.
        // Swoole arms its reaper on an idle time ALONE, checking on its own
        // default cadence (measured on 6.2.0: a healthy silent client was cut).
        // So an operator who sets one env var and not the other would get
        // exactly the listen-only-browser regression this pair is kept off to
        // avoid, without having asked for a heartbeat at all.
        $heartbeat = $heartbeatIdleTime > 0 && $heartbeatCheckInterval > 0;

        return array_filter([
            'enable_coroutine' => (bool) ($settings['enable_coroutine'] ?? false),
            'http_compression' => (bool) ($settings['http_compression'] ?? false),
            'worker_num' => $settings['worker_num'] ?? 1,
            'task_worker_num' => $settings['task_worker_num'] ?? 0,
            'package_max_length' => self::positiveOrDefault(
                $settings['package_max_length'] ?? null,
                self::DEFAULT_PACKAGE_MAX_LENGTH,
            ),
            'heartbeat_idle_time' => $heartbeat ? $heartbeatIdleTime : null,
            'heartbeat_check_interval' => $heartbeat ? $heartbeatCheckInterval : null,
            'log_file' => $settings['log_file'] ?? null,
            'pid_file' => $settings['pid_file'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * A configured size, or the shipped default when the configuration is not a
     * usable size.
     *
     * NOT floored at 1, which is what this used to do and which turned a typo
     * into an outage: `package_max_length` of 1 assembles no request at all, and
     * the mundane way to get there is an empty env value, since `(int) ''` is 0.
     * A setting nobody can have meant is answered with the number this package
     * ships, because a misconfiguration should leave you with the default
     * server, never with a broken one.
     */
    private static function positiveOrDefault(mixed $value, int $default): int
    {
        $size = (int) $value;

        return $size > 0 ? $size : $default;
    }

    /**
     * Arm every Swoole callback this server answers, on one server object.
     *
     * Separate from serve() so the set can be asserted without binding a port.
     * The whole of the `task_worker_num` blocker was a callback that was never
     * registered, and a listener list that only exists inside a method which
     * ends in `$server->start()` is a listener list no test can look at.
     */
    private function registerCallbacks(
        SwooleServer $server,
        string $host,
        int $port,
        int $httpPort,
        bool $samePort,
    ): void {
        // The banner pid is captured NOW, in the registering process, not read
        // off the server inside the callback: 'start' fires in a forked child
        // where `$server->master_pid` names a respawnable worker, and a banner
        // telling the operator to kill a pid that respawns is worse than none.
        $servingPid = (int) getmypid();

        $server->on('start', function (SwooleServer $server) use ($host, $httpPort, $port, $samePort, $servingPid): void {
            if ($samePort) {
                $this->operatorLog->line("Lightspeed HTTP + realtime listening on {$host}:{$port}");
            } else {
                $this->operatorLog->line("Lightspeed HTTP listening on {$host}:{$httpPort}");
                $this->operatorLog->line("Lightspeed realtime listening on {$host}:{$port}");
            }
            $this->operatorLog->line("PID: {$servingPid}");
        });

        $server->on('workerStart', function (SwooleServer $server, int $workerId): void {
            $this->startWorker($server, $workerId);
        });

        $server->on('workerStop', function (): void {
            $this->stopWorker();
        });

        // REGISTERED UNCONDITIONALLY, AND SWOOLE REQUIRES THAT IT BE.
        //
        // `task_worker_num` is checked by Swoole in start_check(), before any
        // listener is bound: a server configured with task workers and no
        // 'task' callback does not start degraded, it does not start at all
        // (ERRNO 9015, `require 'onTask' callback`, then a fatal error out of
        // Server::start()). So this pair is not an optional extra that pays for
        // itself only when the dial is raised; without it the dial is a way to
        // make the server unbootable, exposed as `--task-workers`, as
        // LIGHTSPEED_TASK_WORKER_NUM, and as a tuning suggestion in
        // docs/PRODUCTION.md.
        //
        // The alternative was to refuse `task_worker_num > 0` at boot on the
        // grounds that this package dispatches no tasks of its own. It does
        // not, but the host application can, and by a route this package opened
        // deliberately: Http\OctaneWorker::boot() binds `Swoole\Http\Server`
        // into the application container, which is the exact binding Octane's
        // ProvidesConcurrencySupport::tasks() looks for when choosing a
        // dispatcher. `Octane::concurrently()` in a Lightspeed application
        // therefore reaches `$server->taskWaitMulti()` on THIS server. Refusing
        // the dial would not remove that caller, only guarantee it can never be
        // served.
        $server->on('task', function (SwooleServer $server, int $taskId, int $fromWorkerId, mixed $data): mixed {
            return $this->handleTask($server, $taskId, $fromWorkerId, $data);
        });

        // Swoole delivers the task's return value here, in the worker that
        // asked for it. `taskWaitMulti()` collects the value itself, so there is
        // nothing to do but hand it back; the callback exists because Swoole
        // will not accept a 'task' registration without somewhere to deliver.
        $server->on('finish', function (SwooleServer $server, int $taskId, mixed $result): mixed {
            return $result;
        });

        $server->on('request', function (Request $request, Response $response) use ($httpPort, $samePort): void {
            $this->handleRequest($request, $response, $httpPort, $samePort);
        });

        $server->on('open', function (SwooleServer $server, Request $request): void {
            $this->handleOpen($server, $request);
        });

        $server->on('message', function (SwooleServer $server, Frame $frame): void {
            $this->handleMessage($server, $frame);
        });

        $server->on('close', function (SwooleServer $server, int $fd): void {
            $this->handleClose($server, $fd);
        });
    }

    /**
     * Bring one worker process up.
     *
     * Swoole fires workerStart for every process it forks, request workers and
     * task workers alike, and the two need very different things. A request
     * worker holds websocket connections, so it arms the relay, the sweepers
     * and the owner-command poller. A task worker holds none, so arming any of
     * that would be timers ticking against an empty registry and a second
     * process claiming coordination identity it cannot act on.
     *
     * What a task worker DOES need is the application, because running one is
     * the only thing it is for. The old body returned early for every id at or
     * above `worker_num` and booted nothing, which was harmless only for as
     * long as no task could ever arrive.
     */
    private function startWorker(SwooleServer $server, int $workerId): void
    {
        // GUARDED LIKE EVERY OTHER CALLBACK, and it was the one that was not.
        //
        // Swoole gives workerStart no exception boundary of its own, exactly as
        // it gives `message` and `close` none. What made this one worse than the
        // others is that nobody is waiting on a frame here, so the failure had
        // no symptom: a throw injected into BroadcastBridge::attach() on a live
        // server produced no log line, no respawn, clients connecting and
        // subscribing normally, every broadcast going nowhere, and `/healthz`
        // answering 200. The steps below are in dependency order and
        // `httpWorker->boot()` is near the end, which is precisely what let the
        // earlier ones fail with the websocket surface up and serving.
        //
        // The recovery is to REPORT and to STOP CLAIMING TO BE HEALTHY, not to
        // exit. See Workers\WorkerHealth for why a crash loop is the worse of
        // the two failure modes here.
        try {
            $this->bringWorkerUp($server, $workerId);

            $this->workerHealth->markReady();
        } catch (\Throwable $e) {
            $this->workerHealth->markFailed($e);

            // stdout, through Logging\OperatorLog, because the websocket log
            // channel is opt-in and a worker that came up broken has to be
            // visible on a configuration nobody changed.
            $this->operatorLog->reportCallbackFailure('workerStart', $e, ['worker' => $workerId]);

            // And again on the structured channel, for the deployment that
            // ships these somewhere searchable.
            try {
                $this->runtimeLogger->logWebsocket('error', [
                    'worker' => $workerId,
                    'reason' => 'worker-start-failed',
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // The log stack is the thing that failed. Saying so is not
                // worth ending the process that was trying to say it.
            }
        }
    }

    /**
     * Everything one worker process has to have in place before it serves.
     *
     * Swoole fires workerStart for every process it forks, request workers and
     * task workers alike, and the two need very different things. A request
     * worker holds websocket connections, so it arms the relay, the sweepers
     * and the owner-command poller. A task worker holds none, so arming any of
     * that would be timers ticking against an empty registry and a second
     * process claiming coordination identity it cannot act on.
     *
     * What a task worker DOES need is the application, because running one is
     * the only thing it is for. The old body returned early for every id at or
     * above `worker_num` and booted nothing, which was harmless only for as
     * long as no task could ever arrive.
     *
     * Allowed to throw: startWorker() is the guard.
     */
    private function bringWorkerUp(SwooleServer $server, int $workerId): void
    {
        // Worker identity first, in both shapes: the relay, owner routing,
        // presence and the runtime logger all key their Redis state on this
        // process key, so it must be bound before anything can publish under
        // it. Swoole numbers task workers from `worker_num` upwards, so each
        // one gets a key of its own rather than sharing a request worker's.
        $this->workerContext->boot($server, $workerId);

        if ($workerId >= (int) ($server->setting['worker_num'] ?? 1)) {
            $this->httpWorker->boot($server);

            return;
        }

        $this->broadcastBridge->attach($server, $workerId);

        // After the relay is attached, because this registers the listener
        // that drops revoked connections when a peer worker's revocation
        // arrives over it.
        $this->revocationDrops->bootRevocationListeners($server);

        // The other half of revocation's receive direction, and the only
        // thing that ever judges a grant nobody is using. See
        // Auth\GrantSweeper.
        $this->grantSweeper->bootGrantSweeper($server);

        // The only thing that can tell a presence row belonging to a live
        // connection from one left behind by a killed worker. See
        // Presence\PresenceSweeper.
        $this->presenceSweeper->bootPresenceSweeper($server);

        // The only thing that keeps a live connection findable from outside the
        // process that is holding it. Its registry entry expires, and until
        // this existed nothing ever renewed one. See Connections\ConnectionSweeper.
        $this->connectionSweeper->bootConnectionSweeper($server);

        $this->httpWorker->boot($server);
        $this->ownerCommandBus->bootWorker($server, $workerId);
    }

    /**
     * Take one worker process back down.
     *
     * The health flag is cleared FIRST, so a worker on its way out stops
     * claiming to be a load balancer target before it stops being able to
     * serve, rather than after.
     *
     * GUARDED PER STEP, because `workerStop` is a Swoole callback like any
     * other and this body is not a list of local assignments: `terminate()`
     * dispatches Octane's WorkerStopping event into the HOST APPLICATION, so
     * one listener in somebody else's code is enough to throw here. Unguarded,
     * that threw out of the callback and skipped every step after it, which is
     * the wrong three: the relay is left attached, the owner-command poller is
     * left holding its lease, and worker identity is left bound. A shutdown
     * step that fails is worth a line; it is not worth abandoning the rest of
     * the shutdown.
     */
    private function stopWorker(): void
    {
        $this->workerHealth->reset();

        $steps = [
            'grant-sweeper' => fn () => $this->grantSweeper->shutdownGrantSweeper(),
            'presence-sweeper' => fn () => $this->presenceSweeper->shutdownPresenceSweeper(),
            'connection-sweeper' => fn () => $this->connectionSweeper->shutdownConnectionSweeper(),
            'attached-server' => fn () => $this->attachedServer->detach(),
            'http-worker' => fn () => $this->httpWorker->terminate(),
            'broadcast-bridge' => fn () => $this->broadcastBridge->detach(),
            'owner-command-bus' => fn () => $this->ownerCommandBus->shutdownWorker(),
            'worker-context' => fn () => $this->workerContext->shutdown(),
        ];

        foreach ($steps as $name => $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                // Logging\OperatorLog is the one thing here that may never
                // throw, which is why it is what a callback boundary reports
                // through.
                $this->operatorLog->reportCallbackFailure('workerStop', $e, ['step' => $name]);
            }
        }
    }

    /**
     * Run one Swoole task inside the host application.
     *
     * Every failure comes back as a VALUE rather than as an exception, which is
     * Octane's contract and not a stylistic choice: SwooleTaskDispatcher
     * inspects the result for a TaskExceptionResult and rethrows the original
     * in the process that asked for the work. An exception allowed to escape
     * here would instead kill the task worker and leave the caller waiting for
     * a result that is never coming.
     */
    private function handleTask(SwooleServer $server, int $taskId, int $fromWorkerId, mixed $data): mixed
    {
        try {
            return $this->httpWorker->handleTask($data);
        } catch (\Throwable $e) {
            $this->operatorLog->reportCallbackFailure('task', $e, ['task_id' => $taskId]);

            return TaskExceptionResult::from($e);
        }
    }

    /**
     * Every websocket and HTTP callback below runs directly on the Swoole event
     * loop, where an escaping exception is not an error: it is the END of the
     * worker that was serving every other connection. `worker_num` defaults to
     * 1, so that worker is the whole server.
     *
     * The handshake path has guarded its Redis dependency since it was written
     * (see the try in Protocol\Handshake), for exactly this reason. These are
     * the rest of the surface: the message path reaches Redis through the grant
     * check, presence and the relay's XADD, and the close path reaches it
     * through the presence store, the connection registry and the member_removed
     * fan-out. A Redis blip on any of them used to kill the process.
     *
     * The recovery differs per surface because what the client can be told
     * differs. Everything shares Logging\OperatorLog, which is the one thing
     * here that may never throw.
     */
    private function handleRequest(Request $request, Response $response, int $httpPort, bool $samePort): void
    {
        try {
            $this->requestRouter->handle($request, $response, $httpPort, $samePort);
        } catch (\Throwable $e) {
            $this->operatorLog->reportCallbackFailure('request', $e, [
                'path' => (string) ($request->server['request_uri'] ?? '/'),
            ]);

            // A request whose handler died has written nothing, and Swoole holds
            // the connection open until something ends it. 500 is the honest
            // answer and it releases the socket.
            try {
                $response->status(500);
                $response->end();
            } catch (\Throwable) {
                // The response is already gone, which is the other way this
                // path ends. Nothing further to say to this client.
            }
        }
    }

    private function handleOpen(SwooleServer $server, Request $request): void
    {
        // A connection this worker cannot serve is refused rather than
        // accepted. The half of the failure that `/healthz` cannot cover: a
        // worker whose relay never attached will complete the handshake, accept
        // every subscribe, and deliver nothing, and the client has no way to
        // find that out because nothing is refused and nothing is logged. 4100
        // is the Pusher code for "reconnect after a backoff", which is the
        // honest advice: another node, or this one after a restart, can serve
        // it.
        if (!$this->workerHealth->isReady()) {
            $this->disconnectQuietly($server, $request->fd, 4100, 'Server is not ready');

            return;
        }

        try {
            $this->handshake->openConnection($server, $request);
        } catch (\Throwable $e) {
            $this->operatorLog->reportCallbackFailure('open', $e, ['fd' => $request->fd]);

            // Same advice the guarded handshake gives: 4100 tells a Pusher
            // client to reconnect after a backoff. A connection left open here
            // would sit in "connecting" forever.
            $this->disconnectQuietly($server, $request->fd, 4100, 'Handshake failed');
        }
    }

    private function handleMessage(SwooleServer $server, Frame $frame): void
    {
        try {
            $this->frameRouter->receiveFrame($server, $frame);
        } catch (\Throwable $e) {
            $this->operatorLog->reportCallbackFailure('message', $e, ['fd' => $frame->fd]);

            // The socket stays open. One frame that could not be served is not
            // evidence the connection is unusable, and a client that is told
            // nothing cannot tell a dropped frame from a slow one.
            try {
                $this->delivery->pushPusherError($server, $frame->fd, 'internal-error', 'Message could not be handled.');
            } catch (\Throwable) {
                // The socket died too. The frame is lost either way, and the
                // close handler will do the teardown.
            }
        }
    }

    private function handleClose(SwooleServer $server, int $fd): void
    {
        try {
            $this->teardown->closeConnection($server, $fd);
        } catch (\Throwable $e) {
            // Nothing is pushed back: the socket is already gone. What is lost
            // is the shared-state half of the teardown (the presence row and
            // the registry entry), which is why the presence sweeper exists to
            // reconcile it rather than trusting this path to always run.
            $this->operatorLog->reportCallbackFailure('close', $e, ['fd' => $fd]);
        }
    }

    /**
     * Close a socket without letting the close itself escape.
     *
     * Used only from a callback's catch, where the connection is already known
     * to be unusable: a disconnect that throws there would replace the failure
     * being handled with a fatal one.
     */
    private function disconnectQuietly(SwooleServer $server, int $fd, int $code, string $reason): void
    {
        try {
            $server->disconnect($fd, $code, $reason);
        } catch (\Throwable) {
            // Already gone, which is the outcome that was wanted.
        }
    }
}
