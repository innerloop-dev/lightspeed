<?php

namespace Lightspeed\Relay;

/**
 * Client-agnostic Redis stream operations.
 *
 * Why this file exists: Laravel hands back whichever raw Redis client the
 * host app configured, phpredis (\Redis) or Predis, and the two disagree
 * on XADD/XREAD argument order and on the shape XREAD returns. The relay and
 * the owner-command bus talk to streams through this helper so they never
 * depend on a client-specific calling convention.
 *
 * Owns: XADD/XREAD argument mapping, entry-shape normalization, and field
 * decoding.
 * Deliberately does not own: stream keys, polling cadence, or payload
 * encoding; those stay with the callers.
 *
 * Shapes normalized here:
 *
 *   phpredis xadd(key, id, values, maxlen, approx)   predis xadd(key, dict, id, options)
 *   phpredis xread(streams, count, block)            predis xread(count, block, streams, id)
 *   phpredis returns [stream => [id => fields]]      predis returns [stream => [[id, fields], ...]]
 *   phpredis entry fields [key => value]             predis entry fields [key, value, ...]
 *
 * read() always returns [[entryId, fields], ...] regardless of client, and
 * normalizeFields() turns either field shape into a plain string-keyed map.
 * The two are separate calls because callers decide per entry whether the
 * fields are worth decoding at all.
 */
class RedisStreams
{
    /** Append fields to a stream, optionally trimming to an approximate max length. */
    public static function add(mixed $client, string $key, array $fields, ?int $approxMaxLen = null): void
    {
        if ($client instanceof \Redis) {
            if ($approxMaxLen !== null) {
                $client->xadd($key, '*', $fields, $approxMaxLen, true);
            } else {
                $client->xadd($key, '*', $fields);
            }

            return;
        }

        $options = $approxMaxLen !== null ? ['trim' => ['MAXLEN', '~', $approxMaxLen]] : [];
        $client->xadd($key, $fields, '*', $options);
    }

    /**
     * Non-blocking XREAD of one stream after $lastId.
     *
     * A FAILED READ THROWS, and that is the whole reason this method is not
     * three lines. Its caller is a poll loop whose only recovery (log the
     * outage, drop the dead handle so the next tick resolves a fresh one) is
     * reachable through an exception and through nothing else. So a `false`
     * reply taken for "nothing new yet" is not a missing entry: it is a worker
     * that has silently and permanently stopped receiving broadcasts, presence
     * events and revocations, while the server carries on serving and the log
     * stays quiet.
     *
     * That was observed on a live two-worker server. One worker recovered from
     * a twenty-second Redis outage and the other never did, and the giveaway
     * was that the dead one logged NOTHING, which only a non-throwing failure
     * can produce, because every path that raises gets logged. It is also the
     * same shape as the "Redis blipped, the relay never reconnected" failure
     * that helped kill the reverted authorization design.
     *
     * The discriminator is the client's own view of its socket, not the shape
     * of the reply, because the reply is ambiguous ACROSS VERSIONS: phpredis 6
     * answers an empty stream with an empty array and throws when the handle is
     * dead, while older ones answer false in both cases. Asking whether the
     * client is still connected separates them without depending on which
     * version is installed.
     *
     * @return array<int, array{0: string, 1: array}> entries as [entryId, fields]
     *
     * @throws \RuntimeException when the read FAILED rather than found nothing
     */
    public static function read(mixed $client, string $key, string $lastId, int $count): array
    {
        // Replies are keyed by the stream name AS REDIS SAW IT: when the
        // Laravel connection applies a key prefix, that is the prefixed name,
        // not the $key we asked for. Only one stream is ever requested here,
        // so take the first reply entry instead of indexing by name.
        if ($client instanceof \Redis) {
            $entries = $client->xread([$key => $lastId], $count);

            if ($entries === false) {
                static::refuseDeadHandle($client);

                // Connected, so this is one of the clients that answers an idle
                // stream with false rather than with an empty array.
                return [];
            }

            $streamEntries = is_array($entries) && $entries !== [] ? (array) reset($entries) : [];

            $normalized = [];
            foreach ($streamEntries as $entryId => $fields) {
                $normalized[] = [(string) $entryId, is_array($fields) ? $fields : []];
            }

            return $normalized;
        }

        $entries = $client->xread($count, null, [$key], $lastId);

        // Predis answers an idle stream with null; false is a failure.
        if ($entries === false) {
            static::refuseDeadHandle($client);

            return [];
        }

        $streamEntries = is_array($entries) && $entries !== [] ? (array) reset($entries) : [];

        $normalized = [];
        foreach ($streamEntries as $entry) {
            if (is_array($entry) && isset($entry[0])) {
                $normalized[] = [(string) $entry[0], is_array($entry[1] ?? null) ? $entry[1] : []];
            }
        }

        return $normalized;
    }

    /**
     * Throw if the client that just answered `false` has lost its socket.
     *
     * Both clients expose isConnected(), and both set it false once the
     * connection is gone: phpredis flips it when a command detects the peer
     * has vanished, Predis when its connection resource is closed. A client
     * that cannot answer the question at all is treated as dead, because the
     * cost of being wrong that way is one discarded handle and one log line,
     * where the cost of being wrong the other way is a worker that never
     * receives anything again.
     */
    private static function refuseDeadHandle(mixed $client): void
    {
        $connected = false;

        try {
            $connected = method_exists($client, 'isConnected') && $client->isConnected() === true;
        } catch (\Throwable) {
            $connected = false;
        }

        if ($connected) {
            return;
        }

        $detail = null;

        try {
            $detail = method_exists($client, 'getLastError') ? $client->getLastError() : null;
        } catch (\Throwable) {
            // A client too broken to report its own error is still broken.
        }

        throw new \RuntimeException(sprintf(
            'Lightspeed stream read failed and the Redis handle is not connected: %s',
            is_string($detail) && $detail !== '' ? $detail : 'no error reported',
        ));
    }

    /**
     * Decode one entry's fields into a string-keyed map.
     *
     * phpredis hands back an associative map, Predis a flat [key, value, ...]
     * list; both arrive here as the second element of a read() entry. Non-string
     * values become null so callers can treat "absent" and "not a scalar string"
     * the same way, which is all a JSON-payload reader needs.
     *
     * @return array<string, string|null>
     */
    public static function normalizeFields(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $normalized = [];

        if (!array_is_list($fields)) {
            foreach ($fields as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = is_string($value) ? $value : null;
                }
            }

            return $normalized;
        }

        $count = count($fields);

        for ($i = 0; $i < $count; $i += 2) {
            $key = $fields[$i] ?? null;

            if (is_string($key)) {
                $value = $fields[$i + 1] ?? null;
                $normalized[$key] = is_string($value) ? $value : null;
            }
        }

        return $normalized;
    }
}
