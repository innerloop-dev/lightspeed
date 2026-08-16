<?php

namespace Lightspeed\Channels;

/**
 * The bound on what one anonymous client can make this server allocate.
 *
 * The bound that was missing entirely. Public channels are unauthorized by
 * design and the app key is public by design, so a subscribe frame is the one
 * path on this surface that an anonymous client can make the server ALLOCATE
 * on without proving anything: the channel name becomes an array key in
 * ChannelManager, twice, held for the life of the connection. Nothing capped
 * the name's length, its character set, or how many distinct names one
 * connection could accumulate, so a client looping subscribes with junk names
 * grew the worker until it was OOM-killed, and `worker_num` defaults to 1, so
 * that is the server.
 *
 * Both limits are configurable and both REFUSE rather than truncate or
 * silently drop: a client whose subscribe vanished waits forever for a
 * subscription_succeeded that is never coming, which is indistinguishable from
 * a broken server.
 */
class SubscribeLimits
{
    public function __construct(private readonly ChannelManager $channels)
    {
    }

    /**
     * Why this connection may not subscribe to this channel, or null if it may.
     *
     * The per-connection cap deliberately does not count a re-subscribe to a
     * channel the connection already holds: that allocates nothing, and
     * refusing it would break the ordinary re-subscribe that a `lightspeed:stale`
     * frame asks for at exactly the moment a connection is at its cap.
     *
     * @return ?string a pusher:error code, or null to proceed
     */
    public function subscribeRefusal(int $fd, string $channel): ?string
    {
        $refusal = ChannelName::rejection($channel, self::maxChannelNameLength());

        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->channels->isSubscribed($fd, $channel)) {
            return null;
        }

        return count($this->channels->channelsFor($fd)) >= self::maxChannelsPerConnection()
            ? 'channel-limit-reached'
            : null;
    }

    /**
     * The client-facing half of a refusal.
     *
     * Says which limit was hit and what the limit is, because the alternative
     * ("invalid channel") sends an application developer looking at their
     * authorization callback for a problem that is in their channel naming.
     * The offending name is never echoed back; see ChannelName::forDisplay().
     */
    public function subscribeRefusalMessage(string $refusal): string
    {
        return match ($refusal) {
            'channel-name-too-long' => sprintf(
                'Channel names must be between 1 and %d characters.',
                self::maxChannelNameLength(),
            ),
            'channel-limit-reached' => sprintf(
                'This connection is already subscribed to the maximum of %d channels.',
                self::maxChannelsPerConnection(),
            ),
            default => 'Channel names may only contain letters, numbers, and the characters _-=@,.;',
        };
    }

    /**
     * The longest channel name this server will accept.
     *
     * Static because the frame router needs it BEFORE a subscribe exists, to
     * truncate the name it writes to the log: a name too long to accept is also
     * too long to write to the server's disk once per frame.
     */
    public static function maxChannelNameLength(): int
    {
        return max(1, (int) config('lightspeed.channels.max_name_length', ChannelName::MAX_LENGTH));
    }

    public static function maxChannelsPerConnection(): int
    {
        return max(1, (int) config('lightspeed.channels.max_per_connection', 100));
    }
}
