<?php

namespace Lightspeed\Owner;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lightspeed\Relay\RedisStreams;
use Lightspeed\Workers\WorkerContext;
use Swoole\Coroutine;
use Swoole\Timer;
use Swoole\WebSocket\Server;

/**
 * Cross-process owner command transport backed by Redis streams.
 *
 * When a resource is owned by another worker, callers can enqueue a command
 * for that process and wait for a short-lived response key. Each worker also
 * runs a local stream consumer that dispatches accepted commands, and executes
 * them under the resource write lease so a resource is mutated once at a time.
 *
 *   caller worker                     owning worker
 *   ─────────────                     ─────────────
 *   XADD command  ──────────────────> drain (timer tick)
 *   poll response key                 acquire write lease
 *          ▲                          dispatch to handler
 *          └───────── SETEX response  release lease (finally)
 *
 * Or they do not wait, which is the other half of this class.
 * forwardWithoutReply() writes the same signed entry and returns; the owner
 * runs it and writes nothing back:
 *
 *   caller worker                     owning worker
 *   ─────────────                     ─────────────
 *   XADD command  ──────────────────> drain (timer tick)
 *   return                            dispatch to handler
 *
 * The two paths differ in what the caller is promised, never in what the owner
 * is willing to execute: both entries are signed, addressed, bounded by the
 * freshness window and spendable once. A no-reply command takes no write lease,
 * because a lease can refuse and a refusal has no caller left to reach; see
 * forwardWithoutReply() and executeCommand().
 *
 * What the write lease actually guarantees, and its bound:
 *
 * The lease is a single Redis key with a fixed TTL
 * (`lightspeed.resources.write_lease_ttl_seconds`) and it is never renewed
 * while a handler runs. Mutations of one resource are therefore serialized
 * only for as long as the lease is held: if a handler runs longer than the
 * TTL, Redis drops the key mid-execution and another worker can acquire the
 * same resource's lease and execute concurrently. The guarantee is "one writer
 * per resource at a time, provided every mutation finishes within the lease
 * TTL": nothing weaker, nothing stronger.
 *
 * The package does not prevent an overrun, but it does not hide one either.
 * The release is a compare-and-delete, so it can tell whether this command
 * still owned the key at the end. When it did not, the command is reported to
 * the caller as a structured failure and logged with the resource id, because
 * a mutation that lost its lease mid-flight ran without the serialization this
 * class promises and only the caller can decide what to do about that. The
 * detection is after the fact: the handler has already run.
 *
 * BOTH DIRECTIONS ARE SIGNED, and this is the most consequential signature in
 * the package. A drained entry becomes a call into the application's own
 * OwnerCommandHandler with the command string and payload array the entry
 * carried, under the resource write lease, so a forged one is arbitrary
 * application write execution cleanly serialized against every real mutation.
 * Nothing secret is needed to address it: the stream is named after a process
 * key that sits in plaintext in `lightspeed:resource-owner:*` and is SCAN-able,
 * so Redis write access alone used to be enough. That is categorically worse
 * than the broadcast injection SECURITY.md admits to: a forged broadcast puts
 * a message on a socket; a forged command runs the application's write path.
 *
 * The response is signed by the same mechanism, because the request id an
 * attacker would need to forge one is not a secret either: it is a field of the
 * command entry they can already read. See OwnerCommandSigner.
 *
 * A SIGNATURE OVER THE INSTRUCTION ALONE IS NOT ENOUGH, and the first cut of
 * this bus was exactly that. Covering only the words leaves every signed byte
 * sequence reusable somewhere it was never meant to go, and an attacker who can
 * read and write Redis does not have to forge anything to use that: it copies
 * valid bytes. Three attacks came out of the one gap:
 *
 *   - a captured response envelope re-served under a DIFFERENT request id, so
 *     the caller is told a mutation succeeded that never ran. Captures are
 *     free: a caller only DELs the response key if it reads it, so every
 *     command slower than `blocking_wait_timeout_ms` leaves a validly signed
 *     orphan behind for `response_ttl_seconds`.
 *   - a captured command entry re-appended to the same stream and executed
 *     AGAIN, as many times as it is appended.
 *   - a captured command entry appended to ANOTHER process's stream and
 *     executed there, by a worker it was never addressed to.
 *
 * So the addressing is inside the signature now, on both directions:
 *
 *   command   target_process_key + issued_at inside the signed payload; the
 *             drain refuses anything not addressed to its own process key,
 *             anything outside the freshness window, and any request id it has
 *             already spent (`lightspeed:owner-command-seen:*`, claimed SET NX).
 *   response  the request id and an issued_at are signed alongside the result,
 *             so an envelope is valid for one request, once, briefly.
 *
 * OwnerCommandSigner holds that scheme: the prefixes, the signing string, the
 * freshness window and the spent-id claim. Everything here decides what to do
 * about its answers.
 */
class OwnerCommandBus
{
    private ?Server $server = null;

    private ?int $timerId = null;

    private string $lastCommandId = '0-0';

    /**
     * Refusal reasons already logged by this worker.
     *
     * A refused entry costs the SERVER a log write, and the attacker nothing:
     * `read_count` is 100 and `poll_interval_ms` is 25, so an unthrottled
     * warning per entry is roughly four thousand blocking log writes a second
     * onto the single event loop this worker serves every connection from. The
     * signature refuses the entry correctly; refusing must not cost more than
     * accepting. Deduplicated on the REASON rather than the entry, exactly as
     * Server::reportCallbackFailure() dedupes on class:file:line rather than on
     * a message carrying a per-connection detail: the first of a flood says
     * everything an operator needs, and the ten thousandth says it again.
     *
     * @var array<string, true>
     */
    private array $refusalsLogged = [];

    /**
     * When this worker last reported something about a no-reply command.
     *
     * Size: bounded by the number of distinct keys that reported inside one
     * rate-limit interval, because every lapsed entry is swept on the way in.
     * See reportNoReplyCommand(), which is where the keys are shaped and why
     * this is not $refusalsLogged.
     *
     * @var array<string, float>
     */
    private array $noReplyReportedAt = [];

    /** @var null|callable(OwnerCommand): ?array */
    private $executor = null;

    /**
     * Built here rather than injected, because a bus without its signing scheme
     * is not a thing a caller should be able to construct.
     */
    private readonly OwnerCommandSigner $signer;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ResourceRouter $resourceRouter,
        private readonly OwnerCommandDispatcher $dispatcher,
        private readonly WorkerContext $workerContext,
    ) {
        $this->signer = new OwnerCommandSigner($config);
    }

    /** Swap the container used by the configured owner-command handlers. */
    public function useApplicationContainer(Container $container): void
    {
        $this->dispatcher->useContainer($container);
    }

    /**
     * Override the dispatcher with a direct executor.
     *
     * Tests use this to exercise the bus without depending on container-bound
     * handlers, while production falls back to the normal dispatcher.
     */
    public function useExecutor(callable $executor): void
    {
        $this->executor = $executor;
    }

    /** Attach the bus to one worker and start polling its command stream. */
    public function bootWorker(Server $server, int $workerId): void
    {
        $this->shutdownWorker();

        $this->server = $server;
        $this->lastCommandId = $this->latestCommandId();

        if (!$this->enabled()) {
            return;
        }

        $intervalMs = max(10, (int) $this->config->get('lightspeed.owner_commands.poll_interval_ms', 25));
        $this->timerId = Timer::tick($intervalMs, function (): void {
            $this->tick();
        });
    }

    /**
     * One drain, with nothing allowed out of it.
     *
     * An exception escaping a Swoole timer callback is FATAL to the worker, and
     * `worker_num` defaults to 1. The drain was already wrapped; the reporting
     * was not, and reporting is what runs during exactly the outage that makes
     * the drain fail: a log stack that cannot write turns a Redis blip into a
     * dead server.
     */
    private function tick(): void
    {
        try {
            $this->drainCommands();
        } catch (\Throwable $e) {
            try {
                Log::warning('Lightspeed owner command bus drain failed', [
                    'process_key' => $this->workerContext->currentProcessKey(),
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // The logger is the failure now. The next tick drains again.
            }
        }
    }

    /** Stop polling and forget the local worker attachment. */
    public function shutdownWorker(): void
    {
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }

        $this->server = null;
        $this->lastCommandId = '0-0';
    }

    /**
     * Forward a resource command only when another process currently owns it.
     *
     * Returning null means the local process should handle the work directly.
     */
    public function forwardIfOwnedByAnotherProcess(string $resourceId, string $command, array $payload, string $reason = 'owner-command'): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $targetProcessKey = $this->remoteOwner($resourceId, $reason)['target_process_key'];

        // Local ownership and an owner nobody could resolve are the same answer
        // here, and always have been: there is no other process to forward to,
        // so the caller handles the work itself.
        if ($targetProcessKey === null) {
            return null;
        }

        $requestId = $this->appendCommand($targetProcessKey, $resourceId, $command, $payload, expectsReply: true);

        return $this->waitForResponse($requestId);
    }

    /**
     * Deliver a command to the owning worker without waiting for an answer.
     *
     * The forwarding path above is synchronous by construction: it writes the
     * command and then polls a response key, and with `enable_coroutine` off
     * that poll is a usleep() on the single event loop serving every connection
     * this worker holds. For a mutation whose result the caller needs, that cost
     * buys something. For a STREAM of commands that needs no result — input
     * frames, telemetry, anything whose only requirement is that the owner
     * applies it in order — it buys nothing and spends the worker's loop on it.
     *
     *   local owner  ──> dispatch to the handler now
     *   remote owner ──> XADD the signed entry ─> RETURN
     *
     * Same signature, same addressing, same freshness and spent-id protection as
     * a forwarded command: a no-reply command is exactly as trustworthy as a
     * forwarded one, and the drain applies every one of those gates to both.
     * What it does not do is wait, poll, sleep, or take the resource write
     * lease. The ordering guarantee a caller gets is the owner's own
     * single-threaded drain, in the order the entries were appended, and nothing
     * stronger — which is why the lease is not taken (see executeCommand): a
     * lease can refuse, and a refusal reaching a caller that has already
     * returned is a silently dropped command.
     *
     * WHEN NOBODY OWNS THE RESOURCE the command is dropped, and reported per
     * resource at a bounded rate (see reportNoReplyCommand). This is the
     * residual case only: resolving the owner is claimOwner(), which claims an
     * unowned resource for this process, so an unowned resource makes this
     * worker the owner and the command runs locally. A null here means the claim
     * lost every attempt AND could not read back an owner, i.e. Redis is not
     * answering usefully. There is no caller left to fail, so dropping is the
     * only thing left to do; the log line is what stops it being silent.
     *
     * IT CAN STILL THROW, in one case and for the same reason the forwarding
     * path can: with Redis unreachable the ownership lookup or the stream write
     * raises, and that reaches the caller. "Without reply" means nobody waits on
     * the owner, not that the call cannot fail before it gets there.
     */
    public function forwardWithoutReply(string $resourceId, string $command, array $payload, string $reason = 'owner-command'): void
    {
        // Disabled means there is no cross-process routing at all, so this
        // process is the only place the command can run. That is the same
        // answer forwardIfOwnedByAnotherProcess() gives its caller by returning
        // null; forwardWithoutReply() has no return value to say it with, so it
        // acts on it.
        if (!$this->enabled()) {
            $this->executeLocallyWithoutReply($resourceId, $command, $payload);

            return;
        }

        $route = $this->remoteOwner($resourceId, $reason);

        if ($route['local']) {
            $this->executeLocallyWithoutReply($resourceId, $command, $payload);

            return;
        }

        if ($route['target_process_key'] === null) {
            $this->reportNoReplyCommand(
                'Lightspeed dropped a no-reply owner command: no resolvable owner',
                "no-owner:{$resourceId}",
                [
                    'resource_id' => $resourceId,
                    'command' => $command,
                    'note' => 'The command was dropped. forwardWithoutReply() has no caller to fail, so an unroutable command cannot be returned as an error.',
                ],
            );

            return;
        }

        $this->appendCommand($route['target_process_key'], $resourceId, $command, $payload, expectsReply: false);
    }

    /**
     * Run a no-reply command here, the way the owning worker's drain would.
     *
     * Through executeCommand() rather than straight to the dispatcher, so the
     * two ways a no-reply command can reach a handler stay ONE path with one
     * set of gates: whatever is added there later applies to a socket that
     * landed on the owner as well as to one that did not. executeCommand()
     * skips the write lease for a no-reply command by itself, so nothing here
     * is stricter than the remote side.
     *
     * A THROWING HANDLER MUST NOT REACH THE CALLER, and this is the half of
     * that which is easy to miss. On the remote path the drain catches, so the
     * caller never sees a handler's exception. If this path let one out, the
     * same failing handler would be either silent or fatal depending on which
     * worker Swoole happened to hand the socket to, which is not something an
     * application can write code against. So it is caught here and reported in
     * exactly the shape the drain reports it.
     */
    private function executeLocallyWithoutReply(string $resourceId, string $command, array $payload): void
    {
        try {
            $this->executeCommand($this->localCommand($resourceId, $command, $payload));
        } catch (\Throwable $e) {
            $this->reportNoReplyFailure($resourceId, $command, $e->getMessage());
        }
    }

    /**
     * Which process a command for this resource belongs to.
     *
     * `local` is true when this process owns the resource and should run the
     * command itself. `target_process_key` is the other process to write to, and
     * null whenever there is nobody to write to: either because this process is
     * the owner, or because no owner could be resolved at all. Callers that care
     * about the difference read `local`.
     *
     * @return array{local: bool, target_process_key: ?string}
     */
    private function remoteOwner(string $resourceId, string $reason): array
    {
        $ownerClaim = $this->resourceRouter->claimOwner($resourceId, $reason);
        $owner = $ownerClaim['owner'] ?? null;

        if ($ownerClaim['local'] ?? false) {
            return ['local' => true, 'target_process_key' => null];
        }

        if (!is_array($owner)) {
            return ['local' => false, 'target_process_key' => null];
        }

        $targetProcessKey = $owner['process_key'] ?? null;

        if (!is_string($targetProcessKey) || $targetProcessKey === '') {
            return ['local' => false, 'target_process_key' => null];
        }

        if ($targetProcessKey === $this->workerContext->currentProcessKey()) {
            return ['local' => true, 'target_process_key' => null];
        }

        return ['local' => false, 'target_process_key' => $targetProcessKey];
    }

    /**
     * One command executed here rather than sent, addressed to this process.
     *
     * A locally executed send is still an OwnerCommand, so the handler sees the
     * same object whichever worker the socket happened to land on. It carries
     * the same expectsReply: false, so nothing downstream can decide to answer a
     * caller that has already returned.
     */
    private function localCommand(string $resourceId, string $command, array $payload): OwnerCommand
    {
        return new OwnerCommand(
            requestId: (string) Str::uuid(),
            resourceId: $resourceId,
            command: $command,
            payload: $payload,
            originProcessKey: $this->workerContext->currentProcessKey(),
            expectsReply: false,
        );
    }

    /**
     * Sign one command onto the owning process's stream and return its id.
     *
     * Shared by both delivery paths on purpose. Everything a drain checks before
     * it calls the application's handler is decided here — the signature, the
     * addressing, the freshness — so the two paths cannot drift into one being
     * weaker than the other.
     */
    private function appendCommand(string $targetProcessKey, string $resourceId, string $command, array $payload, bool $expectsReply): string
    {
        $requestId = (string) Str::uuid();
        $commandPayload = [
            'request_id' => $requestId,
            'resource_id' => $resourceId,
            'command' => $command,
            'payload' => $payload,
            'origin_process_key' => $this->workerContext->currentProcessKey(),

            // The addressing, inside the signed bytes. The stream key names the
            // process this command is for, and the key is not covered by the
            // signature, so the name goes in the message, where it is, and the
            // drain refuses anything not addressed to itself. Without this a
            // command signed for the real owner executes verbatim on any other
            // worker's stream.
            'target_process_key' => $targetProcessKey,

            // And when it was written, so a captured entry stops being
            // deliverable. On its own a timestamp only bounds the replay
            // window; the drain also SPENDS the request id (see claimRequestId)
            // so that within the window the entry is deliverable exactly once.
            'issued_at' => time(),

            // Whether anyone is waiting. Inside the signed bytes like everything
            // else, so it cannot be flipped in transit: turning a no-reply command
            // into one that expects a reply would make the owner write a
            // response envelope nobody asked for, for every entry in a stream.
            // Written only when false, so the bytes of a forwarded command are
            // exactly what they were before this field existed and a fleet
            // mid-deploy keeps verifying them.
            ...($expectsReply ? [] : ['expects_reply' => false]),
        ];

        $connection = Redis::connection($this->redisConnection());
        $streamKey = $this->commandStreamKey($targetProcessKey);
        $encodedCommand = json_encode($commandPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        RedisStreams::add(
            $connection->client(),
            $streamKey,
            [
                'message' => $encodedCommand,
                'signature' => $this->signer->commandSignature(OwnerCommandSigner::COMMAND_SIGNING_PREFIX, [$encodedCommand]),
            ],
            $this->maxStreamEntries(),
        );

        // Command streams are per-process and every server start mints a new
        // process key, so without a TTL each restart abandons a stream that
        // lives until the Redis instance is flushed. The TTL is refreshed on
        // every append: a stream that is still receiving commands never
        // expires, and only the streams of processes that are gone age out.
        //
        // An idle-but-alive owner can outlive its own stream key. That is
        // harmless: the key simply disappears while empty, and the next
        // append gets a fresh id from Redis' millisecond clock, which is
        // always greater than the consumer's last-seen id.
        $connection->expire($streamKey, $this->streamTtlSeconds());

        return $requestId;
    }

    /**
     * Poll for the response payload written by the owning worker.
     *
     * The wait is a poll rather than a blocking read because the response is a
     * plain key, not a stream: the owner writes it whenever it finishes, and
     * there is nothing to BLPOP on. See sleepMicroseconds() for what this costs
     * the worker while it waits, and waitTimeoutMs() for why the answer to that
     * decides how long the wait is allowed to be.
     */
    private function waitForResponse(string $requestId): array
    {
        $timeoutMs = $this->waitTimeoutMs();
        $waitUs = (int) $this->config->get('lightspeed.owner_commands.wait_interval_us', 10000);
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $raw = Redis::connection($this->redisConnection())->get($this->responseKey($requestId));
            if (is_string($raw) && $raw !== '') {
                Redis::connection($this->redisConnection())->del($this->responseKey($requestId));

                try {
                    $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    return [
                        'ok' => false,
                        'error' => "Owner command response decode failed: {$e->getMessage()}",
                    ];
                }

                $encodedResult = is_array($envelope) ? ($envelope['result'] ?? null) : null;
                $issuedAt = is_array($envelope) ? ($envelope['issued_at'] ?? null) : null;

                // The request id is not a secret (it travels in the command
                // entry any reader of the stream can see), so whoever could
                // forge a command can also race the real owner to this key and
                // tell the caller a mutation succeeded that never ran.
                //
                // WHICH REQUEST the result answers is therefore part of what is
                // signed, not just the result. Signing the body alone left a
                // validly signed envelope reusable at any other request's key,
                // and the caller cannot tell an envelope written for it from
                // one copied onto its key by looking at the body: every field
                // it would compare came from the envelope. The issued_at is
                // signed with it so the same id cannot be answered by a
                // long-held capture either.
                if (!is_string($encodedResult)
                    || !is_int($issuedAt)
                    || !$this->signer->hasValidSignature(
                        OwnerCommandSigner::RESPONSE_SIGNING_PREFIX,
                        [$requestId, (string) $issuedAt, $encodedResult],
                        is_array($envelope) ? ($envelope['signature'] ?? null) : null,
                    )) {
                    return [
                        'ok' => false,
                        'error' => 'Owner command response was not signed by this application for this request.',
                    ];
                }

                if (!$this->signer->isFresh($issuedAt)) {
                    return [
                        'ok' => false,
                        'error' => 'Owner command response was signed outside the freshness window.',
                    ];
                }

                try {
                    $decoded = json_decode($encodedResult, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    return [
                        'ok' => false,
                        'error' => "Owner command response decode failed: {$e->getMessage()}",
                    ];
                }

                return is_array($decoded) ? $decoded : [
                    'ok' => false,
                    'error' => 'Owner command returned an invalid response shape.',
                ];
            }

            $this->sleepMicroseconds($waitUs);
        }

        return [
            'ok' => false,
            'error' => $this->canYield()
                ? "Owner command timed out waiting for a response after {$timeoutMs}ms."
                : "Owner command timed out waiting for a response after {$timeoutMs}ms. This worker cannot yield while it waits "
                    . '(`lightspeed.server.enable_coroutine` is off), so the wait is bounded by '
                    . '`lightspeed.owner_commands.blocking_wait_timeout_ms` rather than by `wait_timeout_ms`.',
        ];
    }

    /**
     * How long a caller may wait for the owning worker, given what waiting costs.
     *
     * Two numbers, because the wait is two different things. Inside a coroutine
     * it is a yield: the worker keeps serving every other connection, and
     * `wait_timeout_ms` can afford to be as generous as the slowest mutation
     * the application has. Outside one there is no scheduler to yield to, so
     * the poll loop is a usleep() on the single event loop that serves every
     * connection this worker holds, and `enable_coroutine` ships FALSE, so
     * that is the SHIPPED case, not the exotic one. Ten seconds of it is not a
     * slow request, it is ten seconds in which this server answers nothing at
     * all: no frames, no HTTP, no grant sweep, no relay drain.
     *
     * So the blocking case gets its own, much smaller ceiling. The cost is
     * real and is stated here rather than hidden: on a default install a
     * forwarded owner command whose owner takes longer than
     * `blocking_wait_timeout_ms` now FAILS, where before it froze the server
     * and then usually succeeded. That trade is deliberate: a structured
     * failure the caller can retry is recoverable, and a frozen server is not.
     * An application that genuinely needs the longer wait has two supported
     * ways to get it, and both are named in the timeout message: raise the
     * blocking ceiling knowing what it costs, or turn `enable_coroutine` on so
     * the wait becomes a yield and `wait_timeout_ms` applies in full.
     */
    private function waitTimeoutMs(): int
    {
        $timeoutMs = (int) $this->config->get('lightspeed.owner_commands.wait_timeout_ms', 10000);

        if ($this->canYield()) {
            return $timeoutMs;
        }

        // min(), not the cap outright: a caller that asked for less than the
        // ceiling meant it, and a cap must never lengthen a wait.
        return min($timeoutMs, max(0, (int) $this->config->get('lightspeed.owner_commands.blocking_wait_timeout_ms', 250)));
    }

    /** Whether a sleep here yields to a scheduler instead of stopping the worker. */
    private function canYield(): bool
    {
        return class_exists(Coroutine::class) && Coroutine::getCid() > 0;
    }

    /** Drain queued commands for the attached worker process. */
    private function drainCommands(): void
    {
        if ($this->server === null || !$this->enabled()) {
            return;
        }

        $streamEntries = RedisStreams::read(
            Redis::connection($this->redisConnection())->client(),
            $this->commandStreamKey($this->workerContext->currentProcessKey()),
            $this->lastCommandId,
            (int) $this->config->get('lightspeed.owner_commands.read_count', 100),
        );
        if ($streamEntries === []) {
            return;
        }

        foreach ($streamEntries as $entry) {
            $entryId = $entry[0] ?? null;
            if (!is_string($entryId) || $entryId === '') {
                continue;
            }

            $this->lastCommandId = $entryId;
            $fields = RedisStreams::normalizeFields(is_array($entry[1] ?? null) ? $entry[1] : []);
            $encodedMessage = $fields['message'] ?? null;

            if (!is_string($encodedMessage) || $encodedMessage === '') {
                continue;
            }

            // BEFORE ANYTHING READS THE MESSAGE. Everything past this point
            // ends in a call to the application's own command handler, so this
            // comparison is the only thing separating that handler from anyone
            // who can write one Redis key. A refused entry is skipped entirely
            // and writes NO response: answering would confirm to whoever wrote
            // it that the stream key was right.
            if (!$this->signer->hasValidSignature(OwnerCommandSigner::COMMAND_SIGNING_PREFIX, [$encodedMessage], $fields['signature'] ?? null)) {
                // Loud, but once: either something is writing to this stream
                // that should not be, or a deployment is running two different
                // app secrets, and the second one silently breaks owner routing
                // between the processes that disagree.
                $this->reportRefusal('unsigned or badly signed', [
                    'entry' => $entryId,
                    'signed' => is_string($fields['signature'] ?? null),
                ]);

                continue;
            }

            try {
                $decodedMessage = json_decode($encodedMessage, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                continue;
            }

            if (!is_array($decodedMessage)) {
                continue;
            }

            $requestId = (string) ($decodedMessage['request_id'] ?? '');
            if ($requestId === '') {
                continue;
            }

            // ADDRESSING, checked against this process rather than trusted.
            // The signature says the application wrote these bytes; it does not
            // say they were written for THIS worker, and a command copied onto
            // another process's stream is a mutation executed by a worker that
            // does not own the resource. The drain always reads its own
            // currentProcessKey(), so it can require the signed target to be
            // exactly that. The old docblock's worry that binding the target
            // would make a signature unverifiable by its legitimate reader was
            // simply wrong about which name that reader uses.
            $targetProcessKey = $decodedMessage['target_process_key'] ?? null;
            if (!is_string($targetProcessKey) || !hash_equals($this->workerContext->currentProcessKey(), $targetProcessKey)) {
                $this->reportRefusal('addressed to another process', [
                    'entry' => $entryId,
                    'request_id' => $requestId,
                ]);

                continue;
            }

            // FRESHNESS, then UNIQUENESS. Neither alone is replay protection:
            // a window still admits every copy inside it, and a spent-id set
            // alone would have to remember every id ever seen, forever. Together
            // the entry is deliverable during a short window and exactly once
            // within it, which is all a command needs: it is drained a poll
            // interval after it is written or not at all.
            $issuedAt = $decodedMessage['issued_at'] ?? null;
            if (!is_int($issuedAt) || !$this->signer->isFresh($issuedAt)) {
                $this->reportRefusal('outside the freshness window', [
                    'entry' => $entryId,
                    'request_id' => $requestId,
                ]);

                continue;
            }

            if (!$this->signer->claimRequestId($requestId)) {
                $this->reportRefusal('already executed', [
                    'entry' => $entryId,
                    'request_id' => $requestId,
                ]);

                continue;
            }

            // Absent means true. A worker running an older build writes no such
            // field, and the caller behind that entry IS polling a response
            // key, so anything other than an explicit false must be answered.
            $expectsReply = ($decodedMessage['expects_reply'] ?? true) !== false;

            // Read off the decoded entry, not off $command: the catch below can
            // be reached with $command never assigned, and a report about a
            // command that failed has to be able to name it.
            $entryResourceId = (string) ($decodedMessage['resource_id'] ?? '');
            $entryCommand = (string) ($decodedMessage['command'] ?? '');
            $failure = null;

            try {
                $command = new OwnerCommand(
                    requestId: $requestId,
                    resourceId: $entryResourceId,
                    command: $entryCommand,
                    payload: is_array($decodedMessage['payload'] ?? null) ? $decodedMessage['payload'] : [],
                    originProcessKey: is_string($decodedMessage['origin_process_key'] ?? null) ? $decodedMessage['origin_process_key'] : null,
                    expectsReply: $expectsReply,
                );
                $result = $this->executeCommand($command);

                if ($result === null) {
                    $result = [
                        'ok' => false,
                        'error' => 'No owner command handler accepted the message.',
                    ];
                }
            } catch (\Throwable $e) {
                $failure = $e->getMessage();
                $result = [
                    'ok' => false,
                    'error' => $failure,
                ];
            }

            // NOBODY IS WAITING for a no-reply command: forwardWithoutReply() returned the moment
            // the entry was written. Writing a response envelope anyway would
            // put one short-lived Redis key per entry behind a stream whose
            // whole point is volume, and no reader would ever delete one, so
            // they would sit there for `response_ttl_seconds` apiece.
            if (!$expectsReply) {
                // The response key IS the failure report for a forwarded
                // command. A no-reply one has no such key and no caller, so a
                // handler that threw would leave nothing at all behind: an
                // application whose owner handler is broken would see its
                // commands silently do nothing, on the path built to carry the
                // most of them. Rate-limited, because a broken handler fails at
                // the rate the stream arrives.
                if ($failure !== null) {
                    $this->reportNoReplyFailure($entryResourceId, $entryCommand, $failure);
                }

                continue;
            }

            // Inside the loop's own error handling, not outside it. A handler
            // result carrying invalid UTF-8 makes this throw, and a throw here
            // used to escape past the loop with no response written at all: the
            // caller then sat out its entire timeout for a failure the owner
            // already knew about. A structured failure is the honest answer.
            try {
                $encodedResult = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $encodedResult = json_encode([
                    'ok' => false,
                    'error' => 'Owner command result could not be encoded for the caller: '.$e->getMessage(),
                ], JSON_UNESCAPED_SLASHES);
            }

            $issuedAt = time();

            Redis::connection($this->redisConnection())->setex(
                $this->responseKey($requestId),
                (int) $this->config->get('lightspeed.owner_commands.response_ttl_seconds', 30),
                json_encode([
                    'result' => $encodedResult,
                    'issued_at' => $issuedAt,

                    // The request id is signed WITH the result, so this envelope
                    // proves what it answers and not merely that the application
                    // wrote it. The response key is addressing the signature
                    // cannot see; the id inside it is the same addressing where
                    // the signature can.
                    'signature' => $this->signer->commandSignature(
                        OwnerCommandSigner::RESPONSE_SIGNING_PREFIX,
                        [$requestId, (string) $issuedAt, $encodedResult],
                    ),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        }
    }

    private function latestCommandId(): string
    {
        try {
            $entries = Redis::connection($this->redisConnection())
                ->client()
                ->xrevrange($this->commandStreamKey($this->workerContext->currentProcessKey()), '+', '-', 1);
        } catch (\Throwable) {
            return '0-0';
        }

        $latestId = array_key_first($entries);

        return is_string($latestId) ? $latestId : '0-0';
    }

    private function commandStreamKey(string $processKey): string
    {
        return "lightspeed:owner-commands:{$processKey}";
    }

    private function responseKey(string $requestId): string
    {
        return "lightspeed:owner-command-response:{$requestId}";
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('lightspeed.owner_commands.enabled', true);
    }

    /**
     * Whether drained commands execute under the resource write lease.
     *
     * On by default: without it the owning worker can run two commands for the
     * same resource concurrently, which is precisely what the lease prevents.
     */
    private function writeLeaseEnabled(): bool
    {
        return (bool) $this->config->get('lightspeed.owner_commands.write_lease', true);
    }

    /**
     * Approximate cap on entries kept in a per-process command stream.
     *
     * Commands are consumed within a poll interval, so a healthy stream holds a
     * handful of entries at most; the cap only bounds the backlog left behind
     * when a consumer stalls or dies. It is deliberately far above any live
     * in-flight set, and MAXLEN `~` lets Redis trim on node boundaries only, so
     * the trim cannot silently drop a command that is still being waited on.
     */
    private function maxStreamEntries(): int
    {
        return (int) $this->config->get('lightspeed.owner_commands.max_entries', 10000);
    }

    /**
     * Lifetime of an untouched per-process command stream, in seconds.
     *
     * Only has to outlive the gap between appends to the same stream, which is
     * bounded by the caller's own wait timeout (seconds). An hour leaves orders
     * of magnitude of headroom while still reclaiming the streams of processes
     * that never came back.
     */
    private function streamTtlSeconds(): int
    {
        return (int) $this->config->get('lightspeed.owner_commands.stream_ttl_seconds', 3600);
    }

    /**
     * Sleep for one poll interval.
     *
     * ON THE SHIPPED CONFIGURATION THIS BLOCKS. `enable_coroutine` defaults to
     * FALSE, so there is normally no scheduler to yield to, and a Swoole worker
     * serves every one of its connections from a single event loop: this
     * usleep() does not delay one caller, it freezes every websocket frame,
     * every HTTP request, and every timer on the worker for its duration.
     *
     * That is why this method is not the fix for it and never could be: there
     * is nothing better than usleep() to do here. The fix is that the CALLER
     * bounds how many of these it is willing to sit through when it cannot
     * yield; see waitTimeoutMs().
     *
     * Inside a coroutine (`Coroutine::getCid() > 0`, i.e. `enable_coroutine`
     * on) Coroutine::sleep() yields to the scheduler and the loop keeps
     * serving, which is the case the longer timeouts are sized for.
     */
    private function sleepMicroseconds(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if ($this->canYield()) {
            // Swoole timers have millisecond resolution and reject anything
            // shorter, so a sub-millisecond interval sleeps for 1ms.
            Coroutine::sleep(max(0.001, $microseconds / 1_000_000));

            return;
        }

        usleep($microseconds);
    }

    /**
     * Execute one drained command on the owning worker, under the write lease.
     *
     * This is what makes owner-routed mutations run once and in order: the
     * routing layer already guarantees a single owner worker, and the lease
     * closes the remaining gap by serializing the owner's own concurrent
     * executions for the same resource against each other and against any local
     * mutation path that takes the same lease.
     *
     *   drain ─> acquire lease ─ ok ───> dispatch ─> release ─ still held ─> result
     *                          │                            └ lost ───────> error
     *                          └ busy ─> structured error, nothing executes
     *
     * The lease is reentrant per holder, so a handler that acquires the same
     * lease itself nests instead of deadlocking. A command with no resource id
     * has nothing to serialize on and runs unleased.
     *
     * A handler that outruns the lease TTL loses the key to Redis expiry while
     * it is still executing, which is the one way this class can fail to
     * serialize a resource (see the class docblock). The release reports it,
     * and a lost lease turns a handler result into a failure: the mutation ran,
     * but it ran unserialized, and returning it as a success would tell the
     * caller something the package cannot back up.
     */
    private function executeCommand(OwnerCommand $command): ?array
    {
        // A command with no resource id has nothing to serialize on.
        //
        // A SENT command has nobody to tell. The lease can refuse — that is the
        // answer it exists to be able to give — and every other caller on this
        // bus gets that refusal back as a structured failure it can retry.
        // forwardWithoutReply() has already returned, so a refusal here would be a command
        // dropped in silence, at exactly the rate the caller chose forwardWithoutReply() for.
        // What it gets instead is what forwardWithoutReply() promises and no more: the owner's
        // own single-threaded drain, one command at a time, in the order the
        // entries were appended.
        if (!$this->writeLeaseEnabled() || $command->resourceId === '' || !$command->expectsReply) {
            return $this->dispatchCommand($command);
        }

        $leaseToken = $this->resourceRouter->acquireWriteLease($command->resourceId);

        // Executing without the lease would be the exact double-write the lease
        // exists to prevent, so report the contention instead. The caller sees
        // the same failure shape as any other owner-command error.
        if (!is_string($leaseToken) || $leaseToken === '') {
            return [
                'ok' => false,
                'error' => 'Owner command could not acquire the resource write lease.',
            ];
        }

        try {
            $result = $this->dispatchCommand($command);
        } finally {
            // In a finally so a throwing handler cannot strand the lease and
            // block the resource until its TTL lapses. The compare-and-delete
            // result is the only evidence that the lease lapsed mid-handler, so
            // it is kept rather than discarded, and logged either way it is
            // reached, including when the handler is on its way out with an
            // exception, where the operator still wants to see the lost lease.
            $leaseHeldThroughout = $this->resourceRouter->releaseWriteLease($command->resourceId, $leaseToken);

            if (!$leaseHeldThroughout) {
                Log::warning('Lightspeed owner command lost its resource write lease', [
                    'process_key' => $this->workerContext->currentProcessKey(),
                    'resource_id' => $command->resourceId,
                    'command' => $command->command,
                    'request_id' => $command->requestId,
                ]);
            }
        }

        if (!$leaseHeldThroughout) {
            return [
                'ok' => false,
                'error' => 'Owner command lost the resource write lease before it finished; the mutation ran without the guarantee that it was the only writer.',
            ];
        }

        return $result;
    }

    private function dispatchCommand(OwnerCommand $command): ?array
    {
        if (is_callable($this->executor)) {
            return ($this->executor)($command);
        }

        return $this->dispatcher->dispatch($command);
    }

    /**
     * Log one refusal reason once per worker.
     *
     * See $refusalsLogged for why this is deduplicated at all. The entry id and
     * request id travel in the context of the line that does get written, so
     * the first refusal is as informative as it ever was; what is dropped is
     * the ten thousand identical lines behind it, which is the part an attacker
     * with Redis write access was choosing the volume of.
     */
    private function reportRefusal(string $reason, array $context = []): void
    {
        if (isset($this->refusalsLogged[$reason])) {
            return;
        }

        $this->refusalsLogged[$reason] = true;

        Log::warning("Lightspeed refused an owner command: {$reason}", [
            'process_key' => $this->workerContext->currentProcessKey(),
            'reason' => $reason,
            'note' => 'Further refusals for this reason are not logged by this worker.',
            ...$context,
        ]);
    }

    /**
     * Report that a no-reply command failed on the worker that was to run it.
     *
     * Keyed on the resource and the command, so a broken handler for one
     * resource does not hide a broken handler for another.
     */
    private function reportNoReplyFailure(string $resourceId, string $command, string $error): void
    {
        $this->reportNoReplyCommand(
            'Lightspeed no-reply owner command failed on the owning worker',
            "failed:{$resourceId}:{$command}",
            [
                'resource_id' => $resourceId,
                'command' => $command,
                'error' => $error,
                'note' => 'A no-reply command has no caller and no response key, so this line is the only report of the failure.',
            ],
        );
    }

    /**
     * Log one thing that happened to a no-reply command, at most once per
     * interval per key.
     *
     * DELIBERATELY NOT reportRefusal(). That one dedupes on a REASON and keeps
     * it forever, which is right for a refusal: the reasons are a closed set of
     * four, an attacker chooses the volume, and the first line says everything
     * about the class of problem. Neither half fits here. A drop or a handler
     * failure is per RESOURCE, so "refused: no resolvable owner" logged once
     * would have let arena 9 going dark hide behind arena 7's line an hour
     * earlier. And these are not refusals at all: the bus decided nothing about
     * them, they are work it could not deliver or could not complete, so they
     * get their own headline rather than borrowing "refused".
     *
     * Rate-limited rather than once-forever for the same reason: a resource that
     * recovers and breaks again tomorrow has to be able to say so. The window
     * also bounds the map, which is keyed on caller-supplied resource ids and
     * would otherwise grow with them: every entry older than the interval is
     * swept on the way in, so the map only ever holds the keys that reported
     * inside one window.
     */
    private function reportNoReplyCommand(string $headline, string $key, array $context): void
    {
        $now = microtime(true);
        $interval = max(1.0, (float) $this->config->get('lightspeed.owner_commands.no_reply_report_interval_seconds', 60));

        foreach ($this->noReplyReportedAt as $seen => $reportedAt) {
            if ($now - $reportedAt >= $interval) {
                unset($this->noReplyReportedAt[$seen]);
            }
        }

        if (isset($this->noReplyReportedAt[$key])) {
            return;
        }

        $this->noReplyReportedAt[$key] = $now;

        Log::warning($headline, [
            'process_key' => $this->workerContext->currentProcessKey(),
            'rate_limit' => "At most one line per {$interval}s for this resource and command.",
            ...$context,
        ]);
    }

    private function redisConnection(): string
    {
        return (string) $this->config->get('lightspeed.owner_commands.redis_connection', 'default');
    }
}
