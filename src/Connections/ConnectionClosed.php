<?php

namespace Lightspeed\Connections;

/**
 * Immutable package-level record of a connection that is no longer there.
 *
 * The browsers on a presence channel are told a member left, by
 * `pusher_internal:member_removed`. This is the same news, addressed to the
 * application's own PHP, so a handler can drop a lock, release a claim or
 * write a last-seen row without waiting for a lease to lapse.
 *
 * ONLY AUTHORIZED CONNECTIONS REACH IT. Protocol\Teardown builds a 'closed'
 * event only for a connection that held at least one channel subscription or
 * at least one grant. Anything that reached the port with the public app key
 * and hung up again without joining anything is handled entirely inside the
 * package, exactly as it was before this hook existed: a handler here only ever
 * sees a connection that passed the application's own channel authorization.
 *
 * BEST EFFORT, AND THE SHAPE OF THE EVENT SAYS WHICH KIND. `reason` is the one
 * field to read first:
 *
 *   'closed'  Protocol\Teardown ran. The connection is fully described: its
 *             socket id, the channels it held, the presence members it took
 *             with it, and the tags and payloads of the grants it carried.
 *
 *   'swept'   Presence\PresenceSweeper reaped an abandoned member, which is
 *             what happens when the worker holding the connection was killed
 *             and never ran a teardown at all. Nobody in the fleet knows what
 *             that connection was, so the event is THIN: one channel and a user
 *             id, no socket id, no tags, no payloads, no user_info. It also
 *             arrives late, up to `lightspeed.presence.connection_ttl_seconds`
 *             (LIGHTSPEED_PRESENCE_CONNECTION_TTL_SECONDS, default 60) after
 *             the process died, and a connection that was on N presence
 *             channels produces N of these, one per channel, with nothing tying
 *             them together.
 *
 * A worker that is SIGKILLed runs neither path for a connection that was on no
 * presence channel, so an application may not treat "no event" as "still
 * connected". Correctness belongs to TTLs and leases; this hook is the fast
 * path that makes them rarely the thing anyone waits for.
 *
 * `presenceLeaves` carries the memberships the connection actually ENDED: a
 * user with two tabs on one channel produces a leave for the second tab only,
 * exactly as `pusher_internal:member_removed` is only sent when the last of a
 * member's connections goes. The channels it was subscribed to either way are
 * in `channels`.
 *
 * `tags` and `authPayloads` come from the grants the connection was carrying,
 * which the teardown drops on the way through, so they are captured before that
 * happens rather than looked up afterwards. `tags` is the deduplicated union
 * across every channel the connection was granted on. `authPayloads` is NOT a
 * union: merging two applications' payloads would invent data, so it is a map
 * of channel name to that channel's own payload, with one entry per granted
 * channel and no entry at all for a connection the application never tagged.
 */
final class ConnectionClosed
{
    /** An orderly teardown ran in the worker that held the connection. */
    public const REASON_CLOSED = 'closed';

    /** A presence membership was reaped after the holding worker went away. */
    public const REASON_SWEPT = 'swept';

    /**
     * @param  list<string>  $channels
     * @param  list<array{channel: string, user_id: ?string, user_info: ?array}>  $presenceLeaves
     * @param  list<string>  $tags
     * @param  array<array-key, array<array-key, mixed>>  $authPayloads  channel => payload; opaque, never read here
     * @param  self::REASON_*  $reason
     */
    public function __construct(
        public readonly ?string $socketId,
        public readonly array $channels,
        public readonly array $presenceLeaves,
        public readonly array $tags,
        public readonly array $authPayloads,
        public readonly string $reason,
    ) {
    }

    /**
     * The full event, from the worker that was holding the connection.
     *
     * @param  list<string>  $channels
     * @param  list<array{channel: string, user_id: ?string, user_info: ?array}>  $presenceLeaves
     * @param  list<string>  $tags
     * @param  array<array-key, array<array-key, mixed>>  $authPayloads
     */
    public static function closed(
        ?string $socketId,
        array $channels,
        array $presenceLeaves,
        array $tags,
        array $authPayloads,
    ): self {
        return new self($socketId, $channels, $presenceLeaves, $tags, $authPayloads, self::REASON_CLOSED);
    }

    /**
     * The thin event, for a membership nobody was left to tear down.
     *
     * Everything a teardown would have carried belonged to a process that is
     * gone. What survived is what Redis knew: which channel, and which user.
     */
    public static function swept(string $channel, string $userId): self
    {
        return new self(
            socketId: null,
            channels: [$channel],
            presenceLeaves: [['channel' => $channel, 'user_id' => $userId, 'user_info' => null]],
            tags: [],
            authPayloads: [],
            reason: self::REASON_SWEPT,
        );
    }
}
