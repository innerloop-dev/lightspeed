<?php

namespace Lightspeed\Protocol;

/**
 * The Pusher socket id a connection is given at handshake.
 *
 * Why this file exists: the socket id is not a cosmetic label. It is signed
 * into every subscription auth string and it is what a publisher names to
 * exclude itself from its own broadcast, so its format is a wire contract:
 * `{integer}.{integer}`, which is what pusher-js and the server-side Pusher
 * SDKs both expect to parse.
 *
 * Owns: the format, and the per-connection randomness that keeps an id from
 * being guessable from the file descriptor alone.
 * Deliberately does not own: the lifetime of an id. Who holds it, how it maps
 * back to a connection, and when it is forgotten all belong to ChannelManager
 * and the connection registry.
 */
class SocketId
{
    /**
     * The inclusive bounds of the random half.
     *
     * Both eighteen digits, so every minted id has the same shape, and the
     * space between them is just under 2^60. Which is the number this class's
     * "cannot predict another live connection's id" claim actually rests on.
     * They are constants rather than literals inside sprintf() because a test
     * has to be able to assert how large the space is; a guarantee written only
     * as an argument to a function call is a guarantee nothing can check.
     *
     * WHAT THIS REPLACED, AND WHY IT WAS NOT ENOUGH. The suffix was
     * `random_int(100000, 999999)`: nine hundred thousand values, under twenty
     * bits. File descriptors are small and reused, so the fd half is
     * effectively known, which left the whole unpredictability of a live
     * connection's socket id at "guess one of 900,000", cheap enough to
     * enumerate offline, and on a busy server cheap enough to collide with by
     * accident. A socket id is signed into every subscription auth string, so
     * an id somebody else can guess is a credential somebody else can ask an
     * application to sign for them.
     *
     * Both bounds stay inside `\d{1,20}`, which is what
     * SubscriptionAuthorizer::isValidSocketId() accepts and what pusher-js
     * parses, so nothing about the wire contract moves.
     */
    public const MIN_SUFFIX = 100000000000000000;

    public const MAX_SUFFIX = 999999999999999999;

    /**
     * Mint a socket id for a newly opened connection.
     *
     * The fd makes it unique within a worker; the random suffix means a client
     * cannot predict another live connection's id and sign an auth string for
     * it, since file descriptors are small and reused.
     *
     * `random_int` and not `mt_rand`: this is a security boundary, and the
     * cryptographically secure generator is the only one whose output does not
     * hand its own future to anyone who has watched enough of its past.
     */
    public static function generate(int $fd): string
    {
        return sprintf('%d.%d', $fd, random_int(static::MIN_SUFFIX, static::MAX_SUFFIX));
    }
}
