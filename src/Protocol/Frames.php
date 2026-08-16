<?php

namespace Lightspeed\Protocol;

/**
 * Every frame envelope the server can put on a websocket, built as a plain
 * array and nothing else.
 *
 * Why this file exists: the envelope is a contract with client libraries that
 * were not written here. pusher-js silently drops a frame it does not
 * recognise, so a wrong key or a wrong nesting level does not raise an error
 * anywhere: it produces a client that simply never fires a callback, which is
 * the hardest class of bug to find from the server side. Building the
 * envelopes in one pure place makes their exact shape assertable in a unit
 * test instead of only observable through a live browser.
 *
 * The two protocols that share the port keep their envelopes apart here too,
 * and the separation is not cosmetic; see the diagnostic* builders.
 *
 *   Pusher       {"event": "...", "channel": "...", "data": "<json string>"}
 *   diagnostic   {"type": "...", ...}
 *
 * Note the Pusher `data` member is a JSON *string*, not a nested object: that
 * is the protocol, and pusherEvent() is the single place that encoding
 * happens.
 *
 * Owns: frame shape, key names, and the data-as-string encoding.
 * Deliberately does not own: delivery. Nothing here touches a Swoole server,
 * knows a file descriptor is still established, or decides which connection is
 * entitled to which protocol; Server owns all three, and the protocol
 * decision in particular is made once per connection, at open.
 */
class Frames
{
    /**
     * The Pusher envelope. `channel` is omitted entirely for connection-level
     * frames rather than sent as null, which is what the protocol specifies.
     */
    public static function pusherEvent(string $event, ?string $channel, mixed $data): array
    {
        $payload = [
            'event' => $event,
            'data' => is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR),
        ];

        if ($channel !== null) {
            $payload['channel'] = $channel;
        }

        return $payload;
    }

    /**
     * The first frame of a successful Pusher handshake. A client that never
     * receives this sits in "connecting" forever, so it is also the reason a
     * failed handshake must send an error and disconnect rather than go quiet.
     */
    public static function connectionEstablished(string $socketId, int $activityTimeout): array
    {
        return static::pusherEvent('pusher:connection_established', null, [
            'socket_id' => $socketId,
            'activity_timeout' => $activityTimeout,
        ]);
    }

    /** A connection-level Pusher failure: bad app key, bad frame, dead handler. */
    public static function pusherError(string $code, string $message): array
    {
        return static::pusherEvent('pusher:error', null, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Report a rejected subscription on the channel the client asked for.
     *
     * A connection-level `pusher:error` is the wrong frame here: pusher-js
     * routes it to the connection, so the channel stays pending and an Echo
     * `.error()` callback never runs. `pusher_internal:subscription_error`
     * carrying status/type/error is what pusher-js turns into the channel's
     * `pusher:subscription_error` event, which is the failure a real Echo app
     * can actually see.
     */
    public static function subscriptionError(string $channel, int $status, string $type, string $message): array
    {
        return static::pusherEvent('pusher_internal:subscription_error', $channel, [
            'type' => $type,
            'error' => $message,
            'status' => $status,
        ]);
    }

    /**
     * The reply to a client event that a handler answered instead of
     * broadcasting. It rides the Pusher envelope because the connection that
     * asked is a Pusher connection; `requestId` is what correlates it with the
     * frame the client sent.
     */
    public static function lightspeedResponse(string $requestId, array $response, int $status = 200, ?string $channel = null): array
    {
        // The channel is not decoration. pusher-js hands a frame to a channel's
        // bindings only when the frame names that channel, so a channel-less
        // reply reaches nothing but a connection-level global binding. The
        // reply belongs to the channel the request arrived on, so it is stamped
        // with it and `channel.bind('lightspeed:response')` behaves the way
        // anyone reading the docs would expect.
        return static::pusherEvent('lightspeed:response', $channel, [
            'requestId' => $requestId,
            'status' => $status,
            'response' => $response,
        ]);
    }

    /**
     * Tell one client that its grant for a channel is no longer current, so the
     * message it just sent was refused and it should subscribe again.
     *
     * A refusal has to say something. Staying silent leaves a client that
     * believes it is still writing while nothing it sends lands, and closing
     * the socket would take down every other channel on a connection that has
     * only lost one. So the frame is scoped to the channel, and it names the
     * one thing the client can act on: re-subscribe, which re-runs the
     * application's real authorization and mints a current grant if the answer
     * is still yes.
     *
     * `retry_in_ms` is a jittered hint, not a schedule. Revoking a busy tag
     * refuses a message from every connection carrying it at once, and a client
     * library that reacted immediately would send that entire population
     * through /broadcasting/auth (and therefore the application's database)
     * in the same instant. The jitter is computed per frame, so the herd
     * spreads out.
     *
     * `reason` is the client's only way to tell the cases apart, and they want
     * different handling: `revoked` may well come back with fewer permissions,
     * `expired` is routine and will come back identical, and `unavailable`
     * means the server could not reach Redis and the client should back off
     * rather than hammer a re-subscribe.
     */
    public static function stale(string $channel, int $retryInMs, string $reason = 'revoked'): array
    {
        return static::pusherEvent('lightspeed:stale', $channel, [
            'channel' => $channel,
            'reason' => $reason,
            'retry_in_ms' => $retryInMs,
        ]);
    }

    /**
     * The diagnostic protocol's greeting, sent only to a connection that
     * already passed the loopback + opt-in gate in the `open` handler. It
     * advertises the verbs, so it is also the protocol's documentation.
     */
    public static function diagnosticHello(int $fd, string $path): array
    {
        return [
            'type' => 'hello',
            'server' => 'lightspeed',
            'fd' => $fd,
            'path' => $path,
            'protocol' => [
                'name' => 'lightspeed-diagnostic',
                'version' => '1',
                // What the client may send, read from the class whose match
                // enforces it, so the greeting cannot advertise a verb the
                // server refuses. Server-to-client types (hello, subscribed,
                // unsubscribed, whispered, response, broadcast, error) are
                // deliberately not in this list; it documents requests.
                'messages' => \Lightspeed\Diagnostics\DiagnosticProtocol::CLIENT_TYPES,
            ],
            'capabilities' => [
                'send' => ['ping', 'whoami', 'broadcast-test'],
            ],
        ];
    }

    /**
     * Diagnostic-protocol error frame.
     *
     * The `{"type": ...}` envelope belongs to the diagnostic protocol alone.
     * pusher-js drops an unknown envelope without surfacing anything, so a
     * Pusher connection answered this way would see nothing at all. Every
     * caller must already be inside a diagnostic-only path; anything a Pusher
     * connection can reach uses pusherError() instead.
     */
    public static function diagnosticError(string $code, string $message): array
    {
        return [
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * Diagnostic-protocol reply to a `send`. `data` and `error` are mutually
     * exclusive by shape: a caller reads `ok` and then exactly one of them.
     */
    public static function diagnosticResponse(string $id, bool $ok, mixed $data = null, ?string $error = null): array
    {
        $payload = [
            'type' => 'response',
            'id' => $id,
            'ok' => $ok,
        ];

        if ($ok) {
            $payload['data'] = $data;
        } else {
            $payload['error'] = $error ?? 'unknown-error';
        }

        return $payload;
    }
}
