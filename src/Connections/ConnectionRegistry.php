<?php

namespace Lightspeed\Connections;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Workers\WorkerContext;

/**
 * Redis-backed metadata registry for active socket ids.
 *
 * It answers "which worker, in which process, is this socket id connected to
 * right now?", the same question WorkerContext describes locally, published so
 * that another process (a probe, a relay consumer, an operator) can ask it
 * about a socket it does not own.
 *
 * Owns: the socket-id keyspace and the one retry that hides a dropped Redis
 * link from the connecting client.
 * Deliberately does not own: when a socket is remembered or forgotten (the
 * server's connect/close handlers decide that), or what happens when Redis is
 * genuinely down: the exception propagates and the server answers with the
 * error frame it already sends.
 */
class ConnectionRegistry
{
    /** Attempts per operation: the first, plus one after a dropped link. */
    private const ATTEMPTS = 2;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly WorkerContext $workerContext,
    ) {
    }

    public function remember(string $socketId): void
    {
        if ($socketId === '') {
            return;
        }

        $payload = json_encode($this->workerContext->currentIdentity('socket-connect'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->command(fn ($connection) => $connection->setex(
            $this->connectionKey($socketId),
            $this->ttlSeconds(),
            $payload,
        ));
    }

    /**
     * Say again, for socket ids this process is still serving, that it is.
     *
     * The entry remember() writes carries a TTL, so it is a CLAIM WITH AN
     * EXPIRY DATE rather than a record: it says "this process was holding this
     * socket recently enough to still be believed". Written once at connect and
     * never renewed, that claim lapsed under connections that were still open,
     * and a lapsed entry is indistinguishable from the deleted one forget()
     * leaves behind, so every consumer concluded a live socket was gone. This is
     * the renewal, called by Connections\ConnectionSweeper on a timer.
     *
     * SETEX rather than EXPIRE, deliberately, and this is the case the whole
     * mechanism turns on: EXPIRE on a missing key does nothing at all, and the
     * key is missing in precisely the situation a renewal exists for. A worker
     * that missed enough ticks (a garbage collection pause, a blocked event
     * loop, a Redis outage that swallowed several sweeps) comes back to an entry
     * Redis has already dropped, and with EXPIRE there would be nothing left to
     * extend, so those connections would stay unfindable for as long as the
     * worker went on holding them. They are demonstrably still here, so the
     * claim is rewritten rather than extended.
     *
     * Pipelined for the same reason PresenceStore::heartbeatConnections() is:
     * this runs for every connection the worker holds, on the single event loop
     * that serves all of them, with `enable_coroutine` off by default, so a
     * round trip each would cost more than the expiry it repairs. How many go
     * into one pipeline is the caller's decision.
     *
     * @param  list<string>  $socketIds
     * @return int how many entries were rewritten
     */
    public function refresh(array $socketIds): int
    {
        $keys = [];

        foreach ($socketIds as $socketId) {
            if (is_string($socketId) && $socketId !== '') {
                $keys[] = $this->connectionKey($socketId);
            }
        }

        if ($keys === []) {
            return 0;
        }

        // Taken fresh rather than carried over from connect: a renewal is this
        // process vouching for the socket NOW, and `reason` is what tells a
        // reader which of the two writes it is looking at.
        $payload = json_encode($this->workerContext->currentIdentity('socket-sweep'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ttl = $this->ttlSeconds();

        $this->command(fn ($connection) => $connection->pipeline(
            static function ($pipe) use ($keys, $ttl, $payload): void {
                foreach ($keys as $key) {
                    // setex() rather than set(..., ['EX' => ...]): inside a
                    // pipeline the calls reach the raw phpredis client, whose
                    // set() takes at most three arguments and throws on the
                    // options form.
                    $pipe->setex($key, $ttl, $payload);
                }
            }
        ));

        return count($keys);
    }

    /** Remove one socket id from the registry. */
    public function forget(?string $socketId): void
    {
        if (!is_string($socketId) || $socketId === '') {
            return;
        }

        $this->command(fn ($connection) => $connection->del($this->connectionKey($socketId)));
    }

    /** Read the last registered metadata for one socket id. */
    public function metadata(string $socketId): ?array
    {
        $raw = $this->command(fn ($connection) => $connection->get($this->connectionKey($socketId)));
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
     * Run one registry command, retrying once if the Redis link had died.
     *
     * Every registry write sits on the websocket handshake path, so a Redis
     * blip used to cost exactly one client its connection: Laravel's
     * PhpRedisConnection notices a dead socket, rebuilds its client, and still
     * rethrows that first failure, which reaches the server as a refused
     * handshake. The rebuilt client is ready by then, so a second attempt
     * succeeds and the client never learns anything happened.
     *
     *   attempt 1 -> RedisException (link died, wrapper rebuilds client)
     *     attempt 2 -> new client -> OK          <- the blip stays invisible
     *     attempt 2 -> RedisException (really down) -> throw -> error frame
     *
     * Only link-level failures retry: a RedisException from phpredis, or a
     * Predis connection exception when that client is installed. Anything else
     * (a bad command, a JSON failure, an auth error) propagates untouched.
     * Retrying is safe because every operation here is idempotent (SETEX, DEL,
     * GET on one key), and it is capped at ATTEMPTS, so a Redis that is
     * genuinely down still fails the handshake instead of hanging on it.
     *
     * @template TValue
     *
     * @param  callable(\Illuminate\Redis\Connections\Connection): TValue  $command
     * @return TValue
     */
    private function command(callable $command): mixed
    {
        return retry(
            self::ATTEMPTS,
            fn () => $command(Redis::connection($this->redisConnection())),
            0,
            static fn (\Throwable $e) => $e instanceof \RedisException
                || $e instanceof \Predis\Connection\ConnectionException,
        );
    }

    private function connectionKey(string $socketId): string
    {
        return "lightspeed:connection:{$socketId}";
    }

    private function redisConnection(): string
    {
        return (string) $this->config->get('lightspeed.connections.redis_connection', 'default');
    }

    /**
     * How long one entry is believed without this process saying so again.
     *
     * Floored at three sweep intervals, exactly as the presence marker TTL is,
     * and for the same reason: an entry that can lapse between two ticks of a
     * HEALTHY worker is the original bug with a smaller number on it, and it
     * fails the same silent way. Nothing throws, nothing logs, and a live
     * connection simply stops being findable by anything outside its process.
     * The relationship between the two settings is therefore enforced here
     * rather than left to whoever edits the config.
     */
    private function ttlSeconds(): int
    {
        $sweepIntervalSeconds = (int) ceil(
            max(100, (int) $this->config->get('lightspeed.connections.sweep_interval_ms', 300000)) / 1000
        );

        return max(
            $sweepIntervalSeconds * 3,
            (int) $this->config->get('lightspeed.connections.ttl_seconds', 3600),
        );
    }
}
