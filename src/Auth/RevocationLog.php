<?php

namespace Lightspeed\Auth;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Relay\RedisRelay;

/**
 * When each tag was last revoked, in Redis, read on every message.
 *
 * One key per tag that has actually been revoked, holding the microsecond at
 * which it happened. A grant is refused when any of its tags was revoked after
 * the grant was issued. A tag nobody has revoked has no key, reads as never,
 * and costs nothing.
 *
 * THIS READ IS NEVER CACHED. Not in a Swoole table, not in a per-worker mirror,
 * not for one message. One MGET per inbound message, always authoritative.
 *
 * That is a deliberate reversal. The reverted build mirrored these counters
 * into shared memory over the relay so the per-message check was a memory read,
 * and the mirror then WAS the enforcement: a table that failed to allocate, a
 * row that would not fit, a control entry trimmed out of the stream, or a Redis
 * blip the relay never recovered from each turned into a connection nobody
 * re-checked, permanently and silently. Every one of those holes existed to
 * save this round trip.
 *
 * So the round trip stays, and the failure mode inverts: a Redis this cannot
 * reach REFUSES the message. It fails closed by default rather than by careful
 * arrangement, which is the only property that survives being wrong about
 * something else.
 *
 * The relay still carries revocations, but only as an optimization now (see
 * onRevoked). Lose it entirely and the user still cannot send, because this
 * read is what decides, and is still dropped when the grant's lifetime runs
 * out, by the sweeper each worker runs on a timer (GrantSweeper::sweepExpiredGrants),
 * which is what actually makes that second half true. It was asserted here
 * before that sweeper existed, and it was false: nothing evaluated a grant's
 * expiry unless its owner sent something, so a connection that only listened
 * was never dropped at all and a lost relay entry was permanent. A relay
 * failure is a delay, bounded by the grant lifetime, never a hole.
 *
 * CLOCKS. Both the revocation timestamp and a grant's issuedAt are taken from
 * Redis's own clock, via TIME, and never from the calling process's. The
 * comparison "was this tag revoked after this grant was issued" spans two
 * different processes on possibly two different machines, and the answer must
 * not depend on how well their clocks agree.
 *
 * Owns: the Redis key layout, the clock, and the two questions asked of it.
 * Deliberately does not own: what a tag means, which connections carry one
 * (ConnectionGrants), or how a revoked connection is dropped (Server).
 */
class RevocationLog
{
    /** Relay control type carrying one revocation to peer workers. */
    public const CONTROL_TYPE = 'lightspeed-tag-revoked';

    /**
     * The longest `grant_lifetime_seconds` this package will run with.
     *
     * A year, which is far past the point where a backstop is a backstop, and
     * is comfortably inside the expiry range Redis accepts.
     *
     * The number lives here, on the class that reads the dial and hands it to
     * Redis, rather than on Server. That is not filing: `Server::serve()` is
     * reached only by `lightspeed:serve`, while mint() runs in php-fpm on
     * /broadcasting/auth and revoke() runs in queue workers, artisan commands
     * and the scheduler. A ceiling enforced only in serve() therefore let every
     * one of those tiers 500 on a value Redis rejects with nothing said
     * anywhere, which is the exact failure the ceiling was added to prevent.
     */
    public const MAX_GRANT_LIFETIME_SECONDS = 31_536_000;

    /**
     * Stamp a tag as revoked at Redis's own current instant, never earlier than
     * what is already stored, and return the instant that now stands.
     *
     * KEYS[1] the revocation key      ARGV[1] this process's grant lifetime
     * KEYS[2] the lifetime high-water ARGV[2] the retention floor, in seconds
     *
     * The TTL is decided INSIDE the script, from the longest grant lifetime any
     * process has recently minted under, because the calling process's own
     * config is not evidence about the grants other processes are holding; see
     * revoke() and noteGrantLifetime().
     *
     * Everything is done in string form on the way out because Lua numbers are
     * doubles: %.0f prints the microsecond instant exactly (it is around 1.8e15,
     * well inside the 2^53 a double holds exactly) where tostring() would render
     * it in scientific notation and store a value nothing could parse back.
     *
     * TIME is allowed here because Redis replicates script EFFECTS rather than
     * the script itself; the write below is what reaches a replica or the AOF.
     *
     * A single client-agnostic call: Laravel's Redis connection normalizes
     * eval() across phpredis and Predis (which do disagree, in the same way
     * Relay\RedisStreams papers over for XADD/XREAD), and both clients apply
     * the connection's key prefix to a script's KEYS, which is what keeps this
     * key identical to the one isRevoked() reads with MGET.
     *
     * The reply is `{instant}:{1 if the mark was there, 0 if it was not}`. The
     * second field is not used for the decision (the decision has already been
     * made and written by then); it is there so that a mark which has been
     * flushed or evicted is REPORTED rather than silently shrinking the window
     * back to the floor. See revoke().
     */
    private const REVOKE_SCRIPT = <<<'LUA'
        local now = redis.call('TIME')
        local at = now[1] * 1000000 + now[2]
        local existing = tonumber(redis.call('GET', KEYS[1]))

        if existing and existing > at then
            at = existing
        end

        local lifetime = tonumber(ARGV[1])
        local mark = tonumber(redis.call('GET', KEYS[2]))

        if mark and mark > lifetime then
            lifetime = mark
        end

        local ttl = lifetime * 2
        local floor = tonumber(ARGV[2])

        if ttl < floor then
            ttl = floor
        end

        local stamp = string.format('%.0f', at)
        redis.call('SET', KEYS[1], stamp, 'EX', ttl)

        if mark then
            return stamp .. ':1'
        end

        return stamp .. ':0'
        LUA;

    /**
     * Record that a grant is being minted with this lifetime, and return the
     * instant it is being minted at. One round trip, the same one the mint path
     * already made for the clock.
     *
     * KEYS[1] the lifetime high-water   ARGV[1] this grant's lifetime, seconds
     *
     * The mark's own TTL is the lifetime it records, refreshed whenever a grant
     * that long is minted again, so it decays on its own: stop issuing hour-long
     * grants and an hour later the mark is gone, and revocations go back to
     * being kept for as long as the grants that actually exist.
     *
     * `>=` rather than `>` so that re-minting at the CURRENT high-water extends
     * its life. With `>` the mark would expire while grants of exactly that
     * length were still being issued, which is the same bug one level up.
     */
    private const MINT_SCRIPT = <<<'LUA'
        local now = redis.call('TIME')
        local lifetime = tonumber(ARGV[1])
        local mark = tonumber(redis.call('GET', KEYS[1]))

        if not mark or lifetime >= mark then
            redis.call('SET', KEYS[1], string.format('%.0f', lifetime), 'EX', lifetime)
        end

        return string.format('%.0f', now[1] * 1000000 + now[2])
        LUA;

    /**
     * Listeners told about a revocation in THIS process, keyed by name.
     *
     * Keyed rather than appended because a worker restart runs the same boot
     * path again in the same process, and a list would grow a duplicate
     * listener on every cycle.
     *
     * @var array<string, callable(string, int): void>
     */
    private array $listeners = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RedisRelay $relay,
    ) {
        self::validateConfiguration($config);
    }

    /**
     * Refuse a `grant_lifetime_seconds` Redis will not accept as an expiry.
     *
     * Run from the constructor, so it fires the first time the grant machinery
     * is resolved on ANY tier: php-fpm serving /broadcasting/auth, a queue
     * worker calling `Lightspeed::revoke()`, an artisan command, the scheduler,
     * as well as `lightspeed:serve`, which calls it again explicitly so that a
     * misconfigured server aborts the command before it binds a port.
     *
     * Only the ceiling is checked, and only because it is the one thing about
     * these dials a single process can know is wrong. See
     * `Server::validateGrantLifetime()` for the floor check that used to live
     * beside it, why it was refusing safe configurations, and what the residual
     * it was aimed at actually is.
     *
     * Cheap enough to run in a constructor: two config reads and a comparison,
     * once per process, on a container singleton.
     */
    public static function validateConfiguration(ConfigRepository $config): void
    {
        $lifetime = (int) $config->get('lightspeed.auth.grant_lifetime_seconds', 300);

        if ($lifetime > self::MAX_GRANT_LIFETIME_SECONDS) {
            throw new \RuntimeException(
                "Lightspeed cannot run: [lightspeed.auth.grant_lifetime_seconds] is {$lifetime}, "
                .'which is longer than the '.self::MAX_GRANT_LIFETIME_SECONDS.' second ceiling. '
                .'Set LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS to a value in seconds. '
                .'It is handed to Redis as an expiry when a grant is minted, so a value Redis will '
                .'not accept fails every tagged /broadcasting/auth request instead of failing here.'
            );
        }
    }

    /**
     * Register the callback that drops the local connections carrying a tag.
     *
     * This is the optimization half of revocation, and it is what makes revoke
     * immediate in the RECEIVING direction as well as the sending one. Refusing
     * a revoked user's next message stops them writing; it does nothing about
     * what is broadcast to them, because a fan-out never consults a grant. So
     * every worker also unsubscribes the connections carrying the tag on the
     * spot, which takes them out of the subscriber list entirely.
     *
     * Nothing about correctness rests on this callback running. If the relay
     * entry is lost the connection stays subscribed, but its own next message
     * is still refused by the authoritative read, and it is still dropped when
     * its grant expires, by the per-worker sweeper, which needs no relay and
     * no frame from the client, and whose expiry DECISION needs no Redis
     * either (unwinding a presence subscription does, and a cleanup that fails
     * still tells the client). That bounded window is what
     * `grant_lifetime_seconds` is a dial for, and `sweep_interval_ms` is its
     * granularity.
     *
     * @param  callable(string $tag, int $revokedAtMs): void  $listener
     */
    public function onRevoked(string $name, callable $listener): void
    {
        $this->listeners[$name] = $listener;
    }

    /**
     * Revoke one tag: every grant issued before this instant becomes invalid.
     *
     * Order matters and is not negotiable. The Redis write is the revocation;
     * it happens first and its failure propagates, because a revoke that
     * silently did not take is the one failure this feature cannot have. Only
     * once it has landed are the local connections dropped and the peers told,
     * and neither of those is allowed to turn a completed revocation into an
     * exception; they are speed, not enforcement.
     *
     * @return int the instant of the revocation, in microseconds on Redis's clock
     */
    public function revoke(string $tag): int
    {
        if ($tag === '') {
            throw new \InvalidArgumentException('Lightspeed cannot revoke an empty tag.');
        }

        // ONE round trip, and it is atomic. Redis reads its own clock, compares
        // it against whatever is stored, and writes, all inside the script, so
        // nothing can interleave between the read and the write.
        //
        // It used to be three round trips: TIME, then GET, then SETEX. Two
        // revokes of the same tag could interleave between the GET and the
        // SETEX, and the one that finished last could store the EARLIER
        // instant (proven, going backwards by 5049us), which un-revokes every
        // grant issued in the window between them. The GET was there to stop
        // exactly that and could not, because reading and writing were separate
        // operations.
        //
        // Redis executes a script atomically and is single-threaded, so the
        // TIME inside a script that runs second is necessarily later than the
        // TIME inside one that ran first. The comparison below is therefore
        // belt to those braces rather than the mechanism: it also covers a
        // revocation written by an older build, or by hand.
        $reply = (string) Redis::connection($this->redisConnection())->eval(
            self::REVOKE_SCRIPT,
            2,
            $this->key($tag),
            $this->highWaterKey(),
            // The TTL is why nothing accumulates: once it has passed, this key
            // can only match grants issued after the revocation, which it does
            // not concern. What decides it is the LONGEST LIFETIME ANY GRANT
            // STILL OUT THERE COULD HAVE BEEN MINTED UNDER, not this process's
            // current setting, and the difference is a real hole rather than a
            // pedantic one.
            //
            // This used to pass `grantLifetimeSeconds() * 2` read here, in
            // whichever process happened to serve revoke(), and argued it was
            // safe because every grant it invalidates was issued before it.
            // That holds only if every outstanding grant was minted under the
            // SAME lifetime. Lower the dial (which the config actively
            // encourages, "Shorter is safer") or roll out a deploy so the
            // process serving revoke() has the new value while websocket
            // workers still hold grants minted under the old one, and the
            // revocation key expires while the grants it killed are alive.
            // Proven: a grant minted at 3600s, the dial lowered to 1, a
            // revocation key written with a 2 second TTL, the connection
            // correctly dropped, the key lapsing, and the client replaying the
            // IDENTICAL auth string back into the fan-out. Silent, and for the
            // rest of the hour.
            //
            // So the script takes the larger of this process's lifetime and the
            // high-water mark every mint records (noteGrantLifetime), and then
            // a floor under both, because a mark that was evicted, flushed, or
            // never written by this deployment must not quietly shrink the
            // window back down.
            $this->grantLifetimeSeconds(),
            $this->revocationRetentionFloorSeconds(),
        );

        [$stamp, $markWasPresent] = array_pad(explode(':', $reply, 2), 2, '0');
        $revokedAt = (int) $stamp;

        if ($revokedAt <= 0) {
            throw new \RuntimeException('Lightspeed could not write the revocation to Redis.');
        }

        // The mark is how one process learns what lifetime ANOTHER process
        // minted under, and its absence cannot be repaired from here: this
        // process only has its own dial. So the retention just fell back to
        // `max(this process's lifetime * 2, floor)`, which covers this
        // process's own grants unconditionally (the `* 2` does that on its own,
        // whatever the floor is) and cannot be shown to cover a peer's longer
        // ones. `lightspeed:doctor` reads the mark and reports the size of that
        // gap; nothing at boot can, because the fact lives in Redis.
        //
        // Said out loud because the failure is otherwise invisible: a flush or
        // an eviction under `maxmemory` produces a revocation that looks exactly
        // like a correct one and lapses early. Run this Redis with `noeviction`.
        if ($markWasPresent !== '1') {
            Log::warning('Lightspeed revoked a tag with no grant-lifetime high-water mark to read; retention fell back to this process\'s own dial and the floor', [
                'tag' => $tag,
                'lifetime_seconds' => $this->grantLifetimeSeconds(),
                'floor_seconds' => $this->revocationRetentionFloorSeconds(),
            ]);
        }

        $this->notifyLocal($tag, $revokedAt);
        $this->publishToPeers($tag, $revokedAt);

        return $revokedAt;
    }

    /**
     * Was any of this grant's tags revoked after the grant was issued?
     *
     * One MGET, whatever the tag count. Runs on every inbound message.
     *
     * @throws \Throwable when Redis cannot answer; the caller MUST refuse
     */
    public function isRevoked(Grant $grant): bool
    {
        if ($grant->tags === []) {
            // Grant::decode() refuses this, so it cannot arrive from the wire.
            // Answering "revoked" is still the right answer for a grant that
            // nothing could ever revoke.
            return true;
        }

        $keys = array_map(fn (string $tag): string => $this->key($tag), $grant->tags);

        $values = Redis::connection($this->redisConnection())->mget($keys);

        // Not a cast. phpredis answers a failed MGET with `false`, and
        // `(array) false` is `[false]`, which for a one-tag grant is the
        // right LENGTH: the short-reply guard below passed, is_numeric(false)
        // skipped it, and a failed read answered "not revoked". A reply that
        // is not an array is not an answer.
        if (!is_array($values)) {
            throw new \RuntimeException(
                'Lightspeed revocation read did not return an array; refusing to treat a failed read as "not revoked".'
            );
        }

        // A reply that does not answer every key is not an answer. Reading a
        // short array as "none of these were revoked" would turn a malformed or
        // truncated response into a silent allow, which is the shape of every
        // hole this design was written to remove.
        if (count($values) !== count($keys)) {
            throw new \RuntimeException(sprintf(
                'Lightspeed revocation read returned %d values for %d tags.',
                count($values),
                count($keys),
            ));
        }

        foreach ($values as $value) {
            if (!is_numeric($value)) {
                // No key: this tag has never been revoked, or the revocation is
                // older than one grant lifetime and so cannot concern a grant
                // that is still inside its own.
                continue;
            }

            // `>=` and not `>`: a revoke landing on the same microsecond tick
            // as a mint is genuinely unorderable, and the refusing answer is the
            // one that costs a re-subscribe rather than a leaked message.
            if ((int) $value >= $grant->issuedAt) {
                return true;
            }
        }

        return false;
    }

    /**
     * The current instant on Redis's clock, in microseconds.
     *
     * Microseconds and not milliseconds because the resolution IS the size of
     * the ambiguous window below: two instants that land on the same tick
     * cannot be ordered, and the safe answer for an unorderable pair costs a
     * re-subscribe. At millisecond resolution a user re-subscribing straight
     * after a revoke (which is exactly what a revoke provokes) collided with
     * it often enough to be refused on the first attempt. Redis reports
     * microseconds natively, so the finer unit is free.
     *
     * Taken from Redis rather than from PHP so that mint and revoke are ordered
     * by one clock. This is the only Redis call the mint path makes, and it
     * lands on /broadcasting/auth, which has just queried the application's
     * database to decide the same question.
     *
     * @throws \Throwable when Redis cannot answer; a grant with no trustworthy
     *                    issue time must not be minted
     */
    public function now(): int
    {
        $time = Redis::connection($this->redisConnection())->time();

        if (!is_array($time) || !is_numeric($time[0] ?? null) || !is_numeric($time[1] ?? null)) {
            throw new \RuntimeException('Lightspeed could not read the current time from Redis.');
        }

        return ((int) $time[0]) * 1_000_000 + (int) $time[1];
    }

    /**
     * The instant a grant of this lifetime is being minted at, recording the
     * lifetime so that revocations written later outlive it.
     *
     * This is the mint path's clock read (the same single round trip it always
     * made) with the high-water write folded into the same script. Nothing
     * about the cost of minting changes: one call, on /broadcasting/auth, which
     * has just queried the application's database to decide the same question.
     *
     * @throws \Throwable when Redis cannot answer; a grant with no trustworthy
     *                    issue time must not be minted
     */
    public function noteGrantLifetime(int $lifetimeSeconds): int
    {
        $issuedAt = (int) Redis::connection($this->redisConnection())->eval(
            self::MINT_SCRIPT,
            1,
            $this->highWaterKey(),
            max(1, $lifetimeSeconds),
        );

        if ($issuedAt <= 0) {
            throw new \RuntimeException('Lightspeed could not read the current time from Redis.');
        }

        return $issuedAt;
    }

    /** Apply a revocation that arrived over the relay from another process. */
    public function applyFromPeer(string $tag, int $revokedAt): void
    {
        if ($tag !== '') {
            $this->notifyLocal($tag, $revokedAt);
        }
    }

    public function grantLifetimeSeconds(): int
    {
        return max(1, (int) $this->config->get('lightspeed.auth.grant_lifetime_seconds', 300));
    }

    private function notifyLocal(string $tag, int $revokedAt): void
    {
        foreach ($this->listeners as $name => $listener) {
            try {
                $listener($tag, $revokedAt);
            } catch (\Throwable $e) {
                // A listener that throws must not stop the revocation, and must
                // not stop the other listeners either: dropping local sockets is
                // the optimization, and the authoritative refusal has already
                // been written to Redis by the time this runs.
                Log::warning('Lightspeed revocation listener failed', [
                    'listener' => $name,
                    'tag' => $tag,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function publishToPeers(string $tag, int $revokedAt): void
    {
        try {
            $this->relay->publishControl(self::CONTROL_TYPE, [
                'tag' => $tag,
                'revoked_at' => $revokedAt,
            ]);
        } catch (\Throwable $e) {
            // Loud, because a peer that never hears this keeps FANNING OUT to
            // the revoked connection until its grant expires. Not fatal, and
            // deliberately not rethrown: the revocation itself has landed, the
            // revoked user's own next message is already refused everywhere by
            // the authoritative read, and turning a completed revocation into
            // an exception would tempt callers to retry a thing that worked.
            Log::error('Lightspeed could not tell peer workers about a revocation; they will drop the connections when the grants expire', [
                'tag' => $tag,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The shortest time a revocation is kept, whatever anything else says.
     *
     * The high-water mark is the accurate answer and this is what stands in for
     * it when there is none to read: a flush, an eviction under `maxmemory`, or
     * simply the first revoke a fresh deployment performs. Keeping a revocation
     * for an hour costs one small string per tag that was actually revoked.
     *
     * WHAT IT COVERS, PRECISELY. Nothing this process mints depends on it. The
     * Lua keeps a revocation for `max(max(own dial, mark) * 2, floor)`, so a
     * process's own grants are covered by `own dial * 2` whatever this is set
     * to, and this is consulted only when it is LARGER than that. What it is
     * for is a PEER minting longer grants than this process does, with the mark
     * gone: that is the one case the doubling cannot reach, because the mark is
     * the only thing that ever carried the peer's dial.
     *
     * So this is an operator DECLARATION, not a derived value: set it to twice
     * the longest `grant_lifetime_seconds` any process in the fleet mints under,
     * including the version still draining during a rolling deploy. One process
     * cannot check that at boot, which is why the boot refusal that used to
     * claim to was removed. See Server::validateGrantLifetime() for the
     * measurement, and `lightspeed:doctor`, which reads the mark and reports
     * the gap against real evidence.
     *
     * revoke() logs a warning whenever it had no mark to read, which is the
     * moment the residual goes live.
     */
    public function revocationRetentionFloorSeconds(): int
    {
        return max(2, (int) $this->config->get('lightspeed.auth.revocation_retention_floor_seconds', 3600));
    }

    private function key(string $tag): string
    {
        return 'lightspeed:revoked:'.$tag;
    }

    /** The longest grant lifetime anything has recently minted under. */
    private function highWaterKey(): string
    {
        return self::HIGH_WATER_KEY;
    }

    /**
     * Public so `lightspeed:doctor` can READ the mark and say whether the
     * retention floor would cover the fleet's longest grants if it were lost.
     * That is the only check about the floor that is true, and it needs
     * evidence from Redis rather than a relationship between two config values.
     */
    public const HIGH_WATER_KEY = 'lightspeed:grant-lifetime-high-water';

    /**
     * Public for the same reason: the diagnostic has to ask the connection the
     * application will actually use, and duplicating this fallback chain is how
     * a diagnostic ends up reporting on a Redis nothing writes to.
     */
    public static function connectionNameFor(ConfigRepository $config): string
    {
        return (string) $config->get(
            'lightspeed.auth.redis_connection',
            $config->get('lightspeed.relay.redis_connection', 'default'),
        );
    }

    private function redisConnection(): string
    {
        return self::connectionNameFor($this->config);
    }
}
