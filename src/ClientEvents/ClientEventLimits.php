<?php

namespace Lightspeed\ClientEvents;

/**
 * The bound on how much one client event may weigh.
 *
 * A `client-*` frame is the only path that carries an application payload UP a
 * Pusher socket (the local diagnostic protocol has verbs of its own, behind its
 * own gates), and it is the payload the server does the most with: it crosses
 * into the booted application as a ClientEvent, and when no handler answers it
 * is fanned back out to every other subscriber on the channel. Nothing bounded
 * it, so one authorized connection could hand every peer on a channel a
 * megabyte, once per frame, and the server would carry it all the way through.
 *
 * The limit is Pusher's own 10KB, deliberately, because the client libraries
 * were written against it: an application whose client events already work
 * against Pusher or Reverb is unaffected, which is the property that makes
 * enforcing this safe to add.
 *
 * It is measured on the FRAME rather than on the decoded `data` member Pusher
 * states its own limit against. The envelope is the event name and the channel
 * name, so the two differ by a couple of hundred bytes, and the frame's length
 * is an integer the router already holds: measuring the payload instead would
 * mean re-encoding it on the hot path to learn a number that is already known.
 *
 * Deliberately does not own: what a refusal looks like on the wire, nor the
 * bound Swoole applies before any of this runs
 * (`lightspeed.server.package_max_length`, which is also the largest HTTP
 * upload this process accepts and so cannot be this number).
 */
class ClientEventLimits
{
    /** Pusher's own limit, which Reverb matches. */
    public const MAX_FRAME_BYTES = 10240;

    /**
     * The largest client-event frame this server will accept.
     *
     * A configured value that is not a usable size, zero, negative, or a string
     * that is not a number, falls back to the SHIPPED DEFAULT rather than to a
     * floor of one byte. One byte is not a smaller limit, it is an outage: the
     * smallest real client-event frame is around forty bytes, so a cap of 1
     * refuses every client event on the server, which is this package's headline
     * feature turned off. And the way to get there is mundane, not exotic:
     * `LIGHTSPEED_MAX_CLIENT_EVENT_BYTES=` with nothing after it reads as the
     * empty string, and `(int) ''` is 0. A typo should leave an operator with
     * the default, never with a server that refuses everything.
     */
    public static function maxFrameBytes(): int
    {
        $configured = (int) config('lightspeed.client_events.max_frame_bytes', self::MAX_FRAME_BYTES);

        return $configured > 0 ? $configured : self::MAX_FRAME_BYTES;
    }

    /**
     * The client-facing half of the refusal.
     *
     * Says what the limit is, because a client that cannot see the number has
     * no way to decide what to send instead. The payload is never echoed back.
     */
    public static function tooLargeMessage(): string
    {
        return sprintf('Client events are limited to %d bytes.', self::maxFrameBytes());
    }
}
