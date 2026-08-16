<?php

namespace Lightspeed\Channels;

/**
 * What a channel name is allowed to be, as a function of the name alone.
 *
 * Why a bound exists at all: public channels need no authorization by design
 * and the app key is public by design, so the subscribe frame is the one
 * allocating path in this server that an anonymous client can drive without
 * proving anything. The name becomes an array key in ChannelManager (two
 * hashtable entries per distinct name, held for the life of the connection),
 * so an unbounded name is an unauthenticated memory-exhaustion primitive:
 * `{"event":"pusher:subscribe","data":{"channel":"<64KB of junk>"}}` in a loop
 * grows the worker until it is OOM-killed, and `worker_num` defaults to 1.
 *
 * The limits are Pusher's and Reverb's, deliberately, because the client
 * libraries were written against them: 164 characters from a restricted ASCII
 * set. An app whose channel names already work against Pusher is unaffected,
 * which is the property that makes enforcing this safe to add.
 *
 * Deliberately does not own: how many channels one connection may hold (that
 * is a property of the connection, not the name; see Server), nor what a
 * refusal looks like on the wire.
 */
class ChannelName
{
    /** Pusher's own limit, which Reverb matches. */
    public const MAX_LENGTH = 164;

    /**
     * The Pusher channel charset.
     *
     * Every character a Pusher channel name may contain, and nothing else.
     * Note what is absent: whitespace, control bytes, quotes, and every
     * non-ASCII byte, none of which any Pusher client can produce, and all of
     * which make a name that ends up in logs and Redis keys.
     */
    private const ALLOWED = '/^[A-Za-z0-9_\-=@,.;]+$/';

    /**
     * Why this name may not be subscribed to, or null if it may.
     *
     * Length is checked FIRST so that the pathological input (the megabyte of
     * junk this class exists for) is refused by one strlen() rather than by
     * running a regex over it.
     *
     * @return ?string 'channel-name-too-long', 'channel-name-invalid', or null
     */
    public static function rejection(string $channel, int $maxLength = self::MAX_LENGTH): ?string
    {
        if ($channel === '' || strlen($channel) > max(1, $maxLength)) {
            return 'channel-name-too-long';
        }

        if (preg_match(self::ALLOWED, $channel) !== 1) {
            return 'channel-name-invalid';
        }

        return null;
    }

    /**
     * A name cut down to something safe to put in a log line or an error.
     *
     * A refusal must never echo the input back at full length: the log is a
     * file on the server's disk and the error frame is a write to the socket,
     * so repeating a megabyte turns a rejected allocation into two accepted
     * ones.
     */
    public static function forDisplay(string $channel, int $limit = 32): string
    {
        return strlen($channel) <= $limit
            ? $channel
            : substr($channel, 0, $limit).'...';
    }
}
