<?php

namespace Lightspeed\Owner;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Workers\WorkerContext;
use Swoole\Coroutine;

/**
 * Redis-backed routing metadata for resource-scoped write ownership.
 *
 * The package uses this to keep one worker authoritative for a routed resource
 * at a time and to serialize local writes behind a short write lease.
 *
 * The lease is a Redis key with a fixed TTL
 * (`lightspeed.resources.write_lease_ttl_seconds`) and is never renewed while
 * the holder works, so it serializes writers only while it is held: a mutation
 * that runs longer than the TTL loses the key to Redis expiry and another
 * context can acquire the same resource's lease and run alongside it. The
 * guarantee is "one writer per resource, provided every mutation finishes
 * within the lease TTL", and callers must size the TTL above their slowest
 * mutation. releaseWriteLease() returns false when the lease was already gone,
 * which is the caller's after-the-fact signal that this happened.
 *
 * The write lease is reentrant for the same holder. Its job is to serialize
 * writes to one resource, and an execution context that already holds the lease
 * for resource R is by definition not racing itself, but two *different*
 * contexts must never share one lease. Reentrancy is therefore keyed to the
 * holder, never to the resource alone: a nested acquire from the same holder
 * reuses the token, while an acquire from any other context takes the normal
 * Redis path and contends exactly as a second worker would.
 *
 *   acquire(R) ─ held by me? ─ yes ──> depth++, same token       (no Redis)
 *              │
 *              └─ no ──> SET NX ─ ok ───> record depth=1         (Redis)
 *                               └ busy ─> retry, then null
 *
 *   release(R) ─ depth > 1 ──> depth--                           (no Redis)
 *              └ depth == 1 ─> drop entry, Lua compare-and-delete (Redis)
 */
class ResourceRouter
{
    /**
     * Renew an owner claim ONLY if this process still owns it.
     *
     * The refresh used to be a plain SET, which is a write that trusts a read
     * taken a round trip earlier. The owner key carries a TTL, so between the
     * two the key can expire and another worker can win a clean SET NX; the
     * unconditional SET then overwrote a claim it had never looked at, and both
     * processes were told they owned the resource. Both then answered null from
     * forwardIfOwnedByAnotherProcess() and wrote locally, which is exactly the
     * single-writer guarantee this class exists to provide.
     *
     * Compare-and-set closes the window because Redis runs the script
     * atomically: nothing can expire or claim the key between the GET and the
     * SET. It compares the PROCESS KEY rather than the whole payload because
     * the payload legitimately changes on every claim (`reason` and
     * `claimed_at` are part of it), so a byte comparison would refuse every
     * real refresh. `pcall` because a value this process did not write is a
     * value it must not own: anything that does not decode is left alone and
     * the caller contends for the key again.
     */
    private const LUA_REFRESH_OWNER_IF_SAME_PROCESS_SCRIPT = <<<LUA
    local current = redis.call('GET', KEYS[1])
    if not current then
        return 0
    end
    local ok, decoded = pcall(cjson.decode, current)
    if not ok or type(decoded) ~= 'table' or decoded['process_key'] ~= ARGV[2] then
        return 0
    end
    redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[3])
    return 1
LUA;

    private const LUA_RELEASE_LEASE_IF_OWNER_SCRIPT = <<<LUA
    if redis.call('GET', KEYS[1]) == ARGV[1] then
        return redis.call('DEL', KEYS[1])
    end
    return 0
LUA;

    /**
     * Holder id used when no coroutine scheduler is running.
     *
     * Without coroutines a worker runs one call stack at a time, so the whole
     * process is a single execution context and one constant identifies it.
     * Deliberately a string so it can never collide with a coroutine id.
     */
    private const PLAIN_CONTEXT_HOLDER = 'process';

    /**
     * Write leases currently held by this process, keyed by resource id.
     *
     * Size: at most one entry per lease this process is currently holding.
     * Releasing a token removes its entry, and any acquire also sweeps entries
     * whose TTL has passed, so a caller that drops a token without releasing
     * costs one entry until the next acquire rather than one for the life of
     * the process. See acquireWriteLease().
     *
     * @var array<string, array{token: string, holder: int|string, depth: int, expires_at: float}>
     */
    private array $heldLeases = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly WorkerContext $workerContext,
    ) {
    }

    public function claimOwner(string $resourceId, string $reason = 'unknown'): array
    {
        $ownerKey = $this->ownerKey($resourceId);
        $ttl = $this->ownerTtlSeconds();
        $identity = $this->workerContext->currentIdentity($reason);
        $payload = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (Redis::connection($this->redisConnection())->set($ownerKey, $payload, 'EX', $ttl, 'NX')) {
                return [
                    'claimed' => true,
                    'local' => true,
                    'owner' => $identity,
                ];
            }

            $current = $this->currentOwner($resourceId);
            if ($current === null) {
                continue;
            }

            if (($current['process_key'] ?? null) === $identity['process_key']) {
                // Compare-and-set, not SET: see
                // LUA_REFRESH_OWNER_IF_SAME_PROCESS_SCRIPT. A zero means the
                // key stopped being this process's between the read above and
                // the script, so the read is stale and the loop takes the
                // claim from the top rather than writing over whoever owns it
                // now.
                $refreshed = Redis::connection($this->redisConnection())->eval(
                    self::LUA_REFRESH_OWNER_IF_SAME_PROCESS_SCRIPT,
                    1,
                    $ownerKey,
                    $payload,
                    $identity['process_key'],
                    (string) $ttl,
                );

                if ((int) $refreshed !== 1) {
                    continue;
                }

                return [
                    'claimed' => true,
                    'local' => true,
                    'owner' => $identity,
                ];
            }

            return [
                'claimed' => false,
                'local' => false,
                'owner' => $current,
            ];
        }

        return [
            'claimed' => false,
            'local' => false,
            'owner' => $this->currentOwner($resourceId),
        ];
    }

    /** Convenience helper for channel names that encode a resource id. */
    public function claimOwnerForChannel(string $channel, string $reason = 'channel-subscribe'): ?array
    {
        $resourceId = $this->resourceIdFromChannel($channel);
        if ($resourceId === null) {
            return null;
        }

        return $this->claimOwner($resourceId, $reason);
    }

    public function currentOwner(string $resourceId): ?array
    {
        $raw = Redis::connection($this->redisConnection())->get($this->ownerKey($resourceId));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Acquire the short-lived write lease that guards mutation work.
     *
     * Returns null when the lease is still held elsewhere after every retry, so
     * callers can surface "busy, retry" instead of mutating without it. A caller
     * that already holds the lease in this execution context gets the same token
     * back without touching Redis, so nesting an inner mutation inside an outer
     * one cannot deadlock against itself.
     *
     * Every returned token must be handed back to releaseWriteLease(), including
     * on the throwing path, or the local depth never unwinds.
     */
    public function acquireWriteLease(string $resourceId): ?string
    {
        $ttlSeconds = (int) $this->config->get('lightspeed.resources.write_lease_ttl_seconds', 10);
        $maxRetries = $this->writeLeaseRetries();
        $waitUs = (int) $this->config->get('lightspeed.resources.write_lease_wait_us', 20000);

        $holder = $this->currentHolderId();
        $held = $this->heldLeases[$resourceId] ?? null;

        // A local entry that has outlived its own Redis TTL is not evidence of
        // ownership: Redis dropped the key and another context may hold it now.
        // Nothing here can repair that: work that runs longer than the lease TTL
        // has already lost its guarantee, and the only signal available is the
        // false that its release will return, but this map must not make it
        // worse by handing out a token for a lease the process no longer holds.
        // So a lapsed entry is discarded and the acquire contends for real,
        // which also stops a recycled coroutine id from inheriting a stale
        // holder match.
        if ($held !== null && $this->leaseHasLapsed($held)) {
            unset($this->heldLeases[$resourceId]);
            $held = null;
        }

        // Pruning only this resource would leave the map growing without limit
        // for a caller that touches many distinct resources and drops their
        // tokens without releasing. Every entry in the map has already expired
        // in Redis once it lapses, so sweeping them here costs a walk of a map
        // that is only ever as large as the leases this process is really
        // holding, and makes the bound real rather than a matter of caller
        // discipline.
        $this->forgetLapsedLeases();

        if ($held !== null && $held['holder'] === $holder) {
            $this->heldLeases[$resourceId]['depth']++;

            return $held['token'];
        }

        // Held by a different context, or not held here at all: take the normal
        // Redis path, which contends correctly against this process and every
        // other worker alike.
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $token = bin2hex(random_bytes(16));
            if (Redis::connection($this->redisConnection())->set($this->writeLeaseKey($resourceId), $token, 'EX', $ttlSeconds, 'NX')) {
                $this->heldLeases[$resourceId] = [
                    'token' => $token,
                    'holder' => $holder,
                    'depth' => 1,
                    'expires_at' => microtime(true) + $ttlSeconds,
                ];

                return $token;
            }

            $this->sleepMicroseconds($waitUs);
        }

        return null;
    }

    /**
     * How many times to contend for a lease, given what contending costs.
     *
     * Inside a coroutine each retry is a yield, so `write_lease_retries` can be
     * as patient as the application's slowest writer: the worker keeps serving
     * everything else while it waits. Outside one each retry is a real usleep()
     * on the single event loop, and `enable_coroutine` ships FALSE, so the
     * shipped default of 50 retries at 20ms was up to a full second in which
     * this server answered nothing at all.
     *
     * The blocking ceiling is therefore its own setting. What it costs is that
     * a heavily contended resource now reports "busy, retry" on a default
     * install where it would previously have frozen the worker and then won the
     * lease, which is the better half of the trade, because "busy" is exactly
     * the answer acquireWriteLease() exists to be able to give.
     */
    private function writeLeaseRetries(): int
    {
        $retries = (int) $this->config->get('lightspeed.resources.write_lease_retries', 50);

        if ($this->currentCoroutineId() !== null) {
            return $retries;
        }

        // min(), never max(): a caller that asked for fewer retries meant it.
        return min($retries, max(1, (int) $this->config->get('lightspeed.resources.blocking_write_lease_retries', 12)));
    }

    /** @param array{token: string, holder: int|string, depth: int, expires_at: float} $held */
    private function leaseHasLapsed(array $held): bool
    {
        return microtime(true) >= $held['expires_at'];
    }

    /**
     * Drop every locally tracked lease whose TTL has already passed.
     *
     * A lapsed entry is dead weight in every sense: Redis has expired the key,
     * so the entry can neither grant reentrancy nor be released successfully.
     * Sweeping on acquire keeps the map bounded by the leases this process is
     * actually holding, rather than by whether callers remembered to release.
     */
    private function forgetLapsedLeases(): void
    {
        foreach ($this->heldLeases as $resourceId => $held) {
            if ($this->leaseHasLapsed($held)) {
                unset($this->heldLeases[$resourceId]);
            }
        }
    }

    /**
     * Identify the execution context that holds a lease.
     *
     * Each coroutine is an independent context (it can be suspended mid-write
     * while another one runs), so the coroutine id is the right identity when a
     * scheduler is present. Without one, the process is the context.
     */
    private function currentHolderId(): int|string
    {
        return $this->currentCoroutineId() ?? self::PLAIN_CONTEXT_HOLDER;
    }

    /** Current coroutine id, or null when not running inside a coroutine. */
    private function currentCoroutineId(): ?int
    {
        if (!class_exists(Coroutine::class)) {
            return null;
        }

        $coroutineId = Coroutine::getCid();

        return $coroutineId > 0 ? $coroutineId : null;
    }

    /**
     * Sleep for one lease retry interval.
     *
     * ON THE SHIPPED CONFIGURATION THIS BLOCKS. `enable_coroutine` defaults to
     * FALSE, so there is normally no scheduler to yield to, and a Swoole worker
     * serves all of its connections from one event loop: this usleep() freezes
     * every other websocket frame, HTTP request and timer on the worker.
     *
     * Nothing better than usleep() is available here, which is why the fix
     * lives in the caller instead: writeLeaseRetries() bounds how many of
     * these a non-yielding context is willing to sit through.
     *
     * Inside a coroutine (`Coroutine::getCid() > 0`) Coroutine::sleep() yields
     * to the scheduler and the loop keeps serving, which is the case the
     * larger retry count is sized for.
     */
    private function sleepMicroseconds(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if ($this->currentCoroutineId() !== null) {
            // Swoole timers have millisecond resolution and reject anything
            // shorter, so a sub-millisecond interval sleeps for 1ms.
            Coroutine::sleep(max(0.001, $microseconds / 1_000_000));

            return;
        }

        usleep($microseconds);
    }

    /**
     * Release one level of the write lease.
     *
     * Only the outermost holder reaches Redis; inner levels just unwind the
     * local depth. The local entry is dropped before the Redis call, so a
     * throwing release cannot leave this process believing it still holds the
     * lease; the key itself stays bounded by its TTL.
     *
     * @return bool true when this token still owned the lease and it was
     *              released (or an inner level was unwound). False means the
     *              key was gone or owned by someone else, i.e. the lease
     *              lapsed while the caller was working and the work was not
     *              serialized after all. Callers are expected to treat that as
     *              a failure rather than ignore it.
     */
    public function releaseWriteLease(string $resourceId, string $leaseToken): bool
    {
        $held = $this->heldLeases[$resourceId] ?? null;

        if ($held !== null && $held['token'] === $leaseToken) {
            if ($held['depth'] > 1) {
                $this->heldLeases[$resourceId]['depth']--;

                return true;
            }

            unset($this->heldLeases[$resourceId]);
        }

        // Unknown or mismatched tokens still reach the script: it is a
        // compare-and-delete, so it does nothing unless Redis agrees this token
        // owns the key.
        $released = Redis::connection($this->redisConnection())->eval(
            self::LUA_RELEASE_LEASE_IF_OWNER_SCRIPT,
            1,
            $this->writeLeaseKey($resourceId),
            $leaseToken,
        );

        return (int) $released === 1;
    }

    /**
     * Extract the routed resource id from the package resource-channel shape.
     *
     * The channel noun is wire protocol, not package vocabulary: browser
     * clients subscribe to these exact names, so it comes from config
     * (`lightspeed.resources.channel_prefix`) instead of being hard-coded.
     * An app whose clients already speak `private-document.42` sets the prefix
     * to `document`, and nothing on the wire changes.
     */
    public function resourceIdFromChannel(string $channel): ?string
    {
        $prefix = (string) $this->config->get('lightspeed.resources.channel_prefix', 'resource');

        if ($prefix === '') {
            return null;
        }

        $pattern = '/^(?:private|presence)-'.preg_quote($prefix, '/').'\.(.+)$/';

        if (!preg_match($pattern, $channel, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function ownerKey(string $resourceId): string
    {
        return "lightspeed:resource-owner:{$resourceId}";
    }

    private function writeLeaseKey(string $resourceId): string
    {
        return "lightspeed:resource-write:{$resourceId}:lease";
    }

    private function ownerTtlSeconds(): int
    {
        return (int) $this->config->get('lightspeed.resources.owner_ttl_seconds', 30);
    }

    private function redisConnection(): string
    {
        return (string) $this->config->get('lightspeed.resources.redis_connection', 'default');
    }
}
