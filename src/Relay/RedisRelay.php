<?php

namespace Lightspeed\Relay;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Workers\WorkerContext;
use Swoole\Timer;
use Swoole\WebSocket\Server;

/**
 * Cross-worker broadcast relay backed by a Redis stream.
 *
 * Each worker delivers to its own local subscribers immediately, then publishes
 * the normalized message so peer workers can fan the same payload out to their
 * local subscribers.
 *
 * The same stream also carries control entries, which go to registered
 * listeners in each worker instead of to sockets. They ride here rather than on
 * a stream of their own because the poll loop, the origin filter, the reconnect
 * handling and the failure backoff are all already correct, and a second timer
 * per worker would duplicate every one of them. What a control entry MEANS is
 * not this class's business: it hands the payload over and the listener
 * decides (see Auth\RevocationLog, which drops revoked connections this way).
 *
 * A control entry is ALWAYS an optimization, never an enforcement, and that
 * distinction is the only reason this mechanism is safe now when it was not in
 * the reverted build. There, the state a control entry carried was the state
 * the message path consulted, so a lost entry, a trimmed stream or a Redis blip
 * the relay never recovered from was a permanent, silent authorization hole.
 * Nothing that decides whether a message is allowed may read state that arrived
 * over this stream.
 *
 * Owns: the per-worker poll loop over the shared broadcast stream, the
 * origin-process filter that stops a worker replaying its own publishes, the
 * read-failure reporting policy for that loop, and recycling the Redis
 * connection after a failure so an outage self-heals.
 * Deliberately does not own: socket bookkeeping (ChannelManager), client
 * differences (RedisStreams), or worker identity (WorkerContext); bootWorker
 * only binds identity for the process.
 *
 *   publishMessage -> deliverLocal (this worker's sockets)
 *                  -> XADD         -> peer worker poll -> deliverLocal
 *   publishControl -> XADD         -> peer worker poll -> control listeners
 */
class RedisRelay
{
    /** Ceiling on the read-failure log backoff, in consecutive failures. */
    private const MAX_READ_FAILURE_LOG_STEP = 1000;

    private ?Server $server = null;

    private ?int $timerId = null;

    private string $lastBroadcastId = '0-0';

    /** Failed stream reads since the last successful one. */
    private int $readFailures = 0;

    /** Failure count at which the next warning is allowed through. */
    private int $nextReadFailureLog = 1;

    /** Cause of the last logged read failure, used to suppress repeats. */
    private ?string $readFailureCause = null;

    /**
     * One listener per control type, keyed by type.
     *
     * Registration replaces rather than appends because a worker restart calls
     * the same boot path again in the same process, and a list would grow a
     * duplicate listener on every cycle.
     *
     * @var array<string, callable(array): void>
     */
    private array $controlListeners = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ChannelManager $channels,
        private readonly WorkerContext $workerContext,
    ) {
    }

    /**
     * Attach one worker and start polling the shared broadcast stream.
     *
     * Worker identity is already bound by the time this runs: the server boots
     * WorkerContext as the first thing in its workerStart hook. This
     * used to bind it here as a side effect, which made owner-routing
     * correctness depend on relay boot order.
     */
    public function bootWorker(Server $server, int $workerId): void
    {
        $this->shutdownWorker();

        $this->server = $server;

        if (!$this->enabled()) {
            return;
        }

        $this->lastBroadcastId = $this->latestBroadcastId();

        $intervalMs = max(10, (int) $this->config->get('lightspeed.relay.poll_interval_ms', 25));
        $this->timerId = Timer::tick($intervalMs, function (): void {
            $this->tick();
        });
    }

    /**
     * One poll, with nothing allowed out of it.
     *
     * An exception escaping a Swoole timer callback is FATAL to the worker, and
     * `worker_num` defaults to 1, so it is fatal to the server. drainBroadcasts()
     * already guards the stream read and each individual delivery; what it
     * cannot guard is itself: the failure REPORTING is the remaining exposure,
     * because a Redis outage's very first act is to call Log::warning(), and a
     * log channel that cannot write (a full disk, a rotated descriptor, a
     * misconfigured stack) turns that outage into a dead server.
     */
    private function tick(): void
    {
        try {
            $this->drainBroadcasts();
        } catch (\Throwable $e) {
            // Deliberately not logged: reaching here means the reporting path
            // is itself the thing that failed, so trying again would rethrow.
            // The poll runs again on the next tick, which is what recovery is.
        }
    }

    /** Stop polling and detach the relay from the current worker. */
    public function shutdownWorker(): void
    {
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }

        $this->server = null;
        $this->lastBroadcastId = '0-0';
        $this->resetReadFailures();
    }

    /** Whether this process can deliver directly to locally attached sockets. */
    public function hasLocalServer(): bool
    {
        return $this->server !== null;
    }

    /** Whether this process can publish through either local delivery or Redis. */
    public function canPublish(): bool
    {
        return $this->hasLocalServer() || $this->enabled();
    }

    /**
     * Fan one normalized message out locally and, when enabled, to peer workers.
     */
    public function publishMessage(array $channels, array $baseMessage, ?string $exceptSocketId = null): int
    {
        $normalizedChannels = array_values(array_filter(array_map(static fn ($channel) => is_string($channel) ? $channel : null, $channels)));
        if ($normalizedChannels === []) {
            return 0;
        }

        $delivered = $this->deliverLocal($normalizedChannels, $baseMessage, $exceptSocketId);

        if ($this->enabled()) {
            $this->publishToRedis($normalizedChannels, $baseMessage, $exceptSocketId);
        } elseif (!$this->hasLocalServer()) {
            throw new \RuntimeException('Lightspeed relay has no attached worker and Redis relay is disabled.');
        }

        return $delivered;
    }

    /**
     * Register the listener for one control type in this process.
     *
     * @param  callable(array): void  $listener
     */
    public function onControl(string $type, callable $listener): void
    {
        $this->controlListeners[$type] = $listener;
    }

    /**
     * Publish one control entry to peer workers.
     *
     * The publishing process does NOT receive its own entry back (the origin
     * filter in the poll loop drops it, exactly as it does for broadcasts), so
     * a caller that also needs the local effect must apply it itself.
     *
     * With the relay disabled there are no peers to tell, so this is a no-op
     * rather than an error: a single-process deployment already has the whole
     * truth in its own memory.
     *
     * The entry is SIGNED with the application secret; see controlSignature().
     */
    public function publishControl(string $type, array $payload): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            RedisStreams::add(
                $this->redisClient(),
                $this->broadcastStreamKey(),
                [
                    'origin_process_key' => $this->workerContext->currentProcessKey(),
                    'control' => $type,
                    'payload' => $encodedPayload,
                    'signature' => $this->controlSignature($type, $encodedPayload),
                ],
                $this->maxBroadcastEntries(),
            );
        } catch (\Throwable $e) {
            $this->discardRedisConnection();

            throw $e;
        }
    }

    /** Deliver the message to subscribers attached to the current worker only. */
    private function deliverLocal(array $channels, array $baseMessage, ?string $exceptSocketId = null): int
    {
        if ($this->server === null) {
            return 0;
        }

        $exceptFd = is_string($exceptSocketId) && $exceptSocketId !== ''
            ? $this->channels->fdForSocketId($exceptSocketId)
            : null;

        $delivered = 0;

        foreach ($channels as $channel) {
            $message = $baseMessage;
            $message['channel'] = $channel;

            $delivered += $this->channels->broadcast($this->server, $channel, $message, $exceptFd);
        }

        return $delivered;
    }

    /** Append the normalized broadcast payload to the shared Redis stream. */
    private function publishToRedis(array $channels, array $baseMessage, ?string $exceptSocketId = null): void
    {
        try {
            RedisStreams::add(
                $this->redisClient(),
                $this->broadcastStreamKey(),
                [
                    'origin_process_key' => $this->workerContext->currentProcessKey(),
                    'channels' => json_encode($channels, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'message' => json_encode($baseMessage, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'except_socket_id' => $exceptSocketId ?? '',
                ],
                $this->maxBroadcastEntries(),
            );
        } catch (\Throwable $e) {
            // The caller decides what a failed publish means, so the exception
            // still propagates; the connection is dropped first so the next
            // publish is not guaranteed to fail on the same dead handle.
            $this->discardRedisConnection();

            throw $e;
        }
    }

    /**
     * Raw client for the relay connection.
     *
     * The stream helper needs the underlying phpredis/Predis handle to pick its
     * calling convention, so the relay cannot go through Laravel's connection
     * wrapper, and that wrapper is exactly what rebuilds a client after a
     * dropped socket. Every call site therefore pairs this with
     * discardRedisConnection() on failure: that is the relay's own substitute
     * for the reconnect logic it opts out of by taking the raw handle.
     */
    private function redisClient(): mixed
    {
        return Redis::connection($this->redisConnection())->client();
    }

    /**
     * Drop the cached connection so the next call resolves a fresh client.
     *
     * A raw handle whose socket died stays dead forever: phpredis keeps failing
     * on it and nothing in this process rebuilds it. Redis::purge() removes the
     * connection from the manager's cache, so the next Redis::connection() call
     * runs the connector again and hands back a new client against a Redis that
     * may since have come back. Without this a single blip kills cross-worker
     * broadcast until the server is restarted.
     */
    private function discardRedisConnection(): void
    {
        try {
            Redis::purge($this->redisConnection());
        } catch (\Throwable) {
            // Purging is best-effort cleanup: if the manager itself is
            // unavailable there is nothing better to do than retry next tick.
        }
    }

    /** Read peer-worker broadcasts and replay them against local subscribers. */
    private function drainBroadcasts(): void
    {
        if ($this->server === null || !$this->enabled()) {
            return;
        }

        try {
            $streamEntries = RedisStreams::read(
                $this->redisClient(),
                $this->broadcastStreamKey(),
                $this->lastBroadcastId,
                (int) $this->config->get('lightspeed.relay.read_count', 100),
            );
        } catch (\Throwable $e) {
            $this->noteReadFailure($e);
            return;
        }

        $this->noteReadSuccess();

        if ($streamEntries === []) {
            return;
        }

        foreach ($streamEntries as $entry) {
            $entryId = $entry[0] ?? null;
            if (!is_string($entryId) || $entryId === '') {
                continue;
            }

            $this->lastBroadcastId = $entryId;

            // Delivering one entry must not be able to end the poll. This runs
            // inside a Swoole timer callback, where an escaping exception is
            // fatal to the worker rather than merely logged, and it runs with
            // enable_coroutine defaulting to FALSE, so there is no coroutine to
            // contain it either. One malformed entry, one socket that died
            // between the read and the push, would otherwise cost this worker
            // every broadcast, presence event and revocation from then on.
            try {
                $this->deliverEntry($entry);
            } catch (\Throwable $e) {
                Log::warning('Lightspeed relay could not deliver a stream entry', [
                    'entry' => $entryId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Fan one already-read stream entry out locally, or hand it to a listener. */
    private function deliverEntry(array $entry): void
    {
        $fields = RedisStreams::normalizeFields(is_array($entry[1] ?? null) ? $entry[1] : []);

        // A worker never replays its own publishes back to itself.
        if (($fields['origin_process_key'] ?? null) === $this->workerContext->currentProcessKey()) {
            return;
        }

        $control = $fields['control'] ?? null;
        if (is_string($control) && $control !== '') {
            $this->deliverControl(
                $control,
                (string) ($fields['payload'] ?? '{}'),
                $fields['signature'] ?? null,
            );

            return;
        }

        try {
            $channels = json_decode((string) ($fields['channels'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $message = json_decode((string) ($fields['message'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!is_array($channels) || !is_array($message)) {
            return;
        }

        $exceptSocketId = (string) ($fields['except_socket_id'] ?? '');
        $this->deliverLocal($channels, $message, $exceptSocketId !== '' ? $exceptSocketId : null);
    }

    /**
     * Hand one control entry to its listener, if it proves who wrote it.
     *
     * A throwing listener must not stop the drain: the entries after it in the
     * batch include ordinary broadcasts, and dropping those to a bad control
     * payload would turn one module's bug into silent message loss.
     *
     * THE SIGNATURE IS CHECKED FIRST. A control entry is a command (the
     * revocation one force-unsubscribes every connection carrying a tag), and
     * without this, anything that could reach Redis could issue it. It can only
     * ever DENY (a dropped connection re-subscribes through the application's
     * own authorization, so no control entry can grant anything), which makes
     * an unsigned one a targeted denial of service rather than a bypass. That
     * is still a command from an unauthenticated source being obeyed, and
     * everything entitled to issue one already shares the app secret.
     */
    private function deliverControl(string $type, string $encodedPayload, mixed $signature): void
    {
        $listener = $this->controlListeners[$type] ?? null;

        if ($listener === null) {
            return;
        }

        if (!is_string($signature) || !hash_equals($this->controlSignature($type, $encodedPayload), $signature)) {
            // Loud: either something is writing to this stream that should not
            // be, or a deployment is running two different app secrets, and the
            // second one silently breaks revocation's receive direction on every
            // worker that does not match.
            Log::warning('Lightspeed relay refused an unsigned or badly signed control entry', [
                'control' => $type,
                'signed' => is_string($signature),
            ]);

            return;
        }

        try {
            $payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!is_array($payload)) {
            return;
        }

        try {
            $listener($payload);
        } catch (\Throwable $e) {
            Log::warning('Lightspeed relay control listener failed', [
                'control' => $type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a failed poll and log at most one warning per backoff step.
     *
     * A Redis outage fails every poll, and the poll runs on a timer: at the
     * default 25ms cadence that is 40 identical warnings a second, per worker,
     * for as long as the outage lasts. So the first failure is logged straight
     * away with its cause, and after that a warning only escapes when the cause
     * changes or the consecutive-failure count doubles.
     *
     * The doubling stops at MAX_READ_FAILURE_LOG_STEP. Pure doubling makes the
     * warnings exponentially rarer exactly as an outage becomes permanent, so
     * the log reads as if the problem is settling down while cross-worker
     * broadcast is in fact still dead. Capping the step at 1000 failures (about
     * 25 seconds at the default cadence, so a couple of lines a minute per
     * worker) keeps a permanent outage permanently visible while a multi-hour
     * one still costs kilobytes rather than gigabytes.
     */
    private function noteReadFailure(\Throwable $e): void
    {
        $cause = $e->getMessage();
        $this->readFailures++;

        // The handle is dead until proven otherwise, so drop it: the next tick
        // resolves a fresh client and the loop can recover on its own once
        // Redis is back. This is the whole reason an outage self-heals.
        $this->discardRedisConnection();

        // A new cause is new information, never a duplicate: log it even
        // mid-backoff, otherwise a second fault during an outage stays hidden.
        if ($cause === $this->readFailureCause && $this->readFailures < $this->nextReadFailureLog) {
            return;
        }

        Log::warning('Lightspeed relay read failed', [
            'message' => $cause,
            'consecutive_failures' => $this->readFailures,
        ]);

        $this->readFailureCause = $cause;
        $this->nextReadFailureLog = $this->readFailures
            + min($this->readFailures, self::MAX_READ_FAILURE_LOG_STEP);
    }

    /** Log recovery once, so an operator sees the outage end as well as begin. */
    private function noteReadSuccess(): void
    {
        if ($this->readFailures === 0) {
            return;
        }

        Log::info('Lightspeed relay read recovered', [
            'failed_reads' => $this->readFailures,
        ]);

        $this->resetReadFailures();
    }

    private function resetReadFailures(): void
    {
        $this->readFailures = 0;
        $this->nextReadFailureLog = 1;
        $this->readFailureCause = null;
    }

    private function latestBroadcastId(): string
    {
        try {
            $entries = $this->redisClient()
                ->xrevrange($this->broadcastStreamKey(), '+', '-', 1);
        } catch (\Throwable) {
            // Starting from '0-0' is the safe fallback (replay rather than
            // silently skip), but the dead connection must not be kept: this
            // runs at worker boot, and a stale handle here would be the very
            // handle the poll loop then inherits.
            $this->discardRedisConnection();

            return '0-0';
        }

        $latestId = array_key_first($entries);

        return is_string($latestId) ? $latestId : '0-0';
    }

    /**
     * The bytes that prove a control entry came from this application.
     *
     * The type is inside the signature as well as the payload, so an entry
     * cannot be replayed under a different control type: a signature that
     * covered only the payload would let one control's arguments be presented
     * as another's the moment a second control type exists.
     *
     * Deliberately NOT covering the origin process key: a peer legitimately
     * rewrites nothing, but that field is the relay's own routing, not part of
     * the command, and binding it would make every worker's signature different
     * for the same instruction.
     *
     * Only control entries are signed. Ordinary broadcasts carry no
     * authorization decision; they are the payloads this relay has always
     * moved, and their contents are already whatever the application published.
     */
    private function controlSignature(string $type, string $encodedPayload): string
    {
        return hash_hmac('sha256', $type."\0".$encodedPayload, $this->appSecret());
    }

    private function appSecret(): string
    {
        return (string) $this->config->get('lightspeed.reverb_compat.app_secret', '');
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('lightspeed.relay.enabled', true);
    }

    private function redisConnection(): string
    {
        return (string) $this->config->get('lightspeed.relay.redis_connection', 'default');
    }

    private function broadcastStreamKey(): string
    {
        return (string) $this->config->get('lightspeed.relay.broadcast_stream', 'lightspeed:broadcasts');
    }

    private function maxBroadcastEntries(): int
    {
        return (int) $this->config->get('lightspeed.relay.max_entries', 10000);
    }
}
