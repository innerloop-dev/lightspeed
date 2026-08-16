<?php

namespace Lightspeed\Protocol;

use Lightspeed\Channels\ChannelName;
use Lightspeed\Channels\SubscribeLimits;
use Lightspeed\Channels\Subscriptions;
use Lightspeed\ClientEvents\ClientEventGate;
use Lightspeed\Diagnostics\DiagnosticProtocol;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Read one frame and hand it to the thing that answers it.
 *
 * The hot path. Everything here is a decode, a log line and a match: no Redis,
 * no disk, no container resolution, and nothing that allocates per frame beyond
 * the decoded payload itself. The work a frame causes belongs to whoever it is
 * routed to.
 *
 * The protocol split is enforced here and is one-way. A connection that opened
 * as a Pusher connection can only ever reach the Pusher handlers, whatever
 * shape its frames take: the diagnostic verbs are behind the eligibility
 * recorded at open (Diagnostics\DiagnosticSockets), never behind a test of the
 * frame in hand.
 */
class FrameRouter
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly ClientEventGate $clientEvents,
        private readonly DiagnosticProtocol $diagnostics,
        private readonly DiagnosticSockets $diagnosticSockets,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    public function receiveFrame(SwooleServer $server, Frame $frame): void
    {
        $payload = $this->decodePayload($frame->data);

        $label = is_array($payload)
            ? ($payload['event'] ?? $payload['type'] ?? 'json')
            : gettype($payload);

        $frameBytes = strlen($frame->data);

        $messageFields = [
            'fd' => $frame->fd,
            'opcode' => $frame->opcode,
            'label' => $label,
            'bytes' => $frameBytes,
        ];

        if (is_array($payload)) {
            // Truncated, because this runs BEFORE the channel name has been
            // validated: a name too long to accept is also too long to write to
            // the server's disk once per frame. A valid name is under the limit
            // and so is never cut.
            $messageFields['channel'] = is_string($payload['channel'] ?? null)
                ? ChannelName::forDisplay($payload['channel'], SubscribeLimits::maxChannelNameLength())
                : null;
            $messageFields['request'] = is_string($payload['requestId'] ?? null) ? $payload['requestId'] : null;
            $messageFields['payload'] = $this->runtimeLogger->maybePayloadSnippet($payload);
        }

        $this->runtimeLogger->logWebsocket('message', $messageFields);

        if (is_array($payload) && is_string($payload['event'] ?? null)) {
            $this->handlePusherMessage($server, $frame->fd, $payload, $frameBytes);
            return;
        }

        // Only connections that opened as diagnostic connections may speak
        // the diagnostic protocol. A Pusher client that sends a non-Pusher
        // frame gets a Pusher-shaped error, never the unauthorized verbs.
        if (!$this->diagnosticSockets->allows($frame->fd)) {
            $this->delivery->pushPusherError($server, $frame->fd, 'invalid-message', 'Unsupported message shape.');
            return;
        }

        // The only place a `{"type": ...}` envelope is written to a socket,
        // and it is inside the check above by construction. That matters
        // because pusher-js drops an unknown envelope without surfacing
        // anything: a Pusher connection answered this way would see nothing
        // at all. Everything a Pusher connection can reach uses
        // pushPusherError instead.
        $this->delivery->push($server, $frame->fd, $this->diagnostics->handle($frame->fd, $payload));
    }

    /**
     * @param int $frameBytes how big the frame carrying this payload was on the
     *                        wire. Only the client-event path reads it, and it
     *                        is carried rather than recomputed because the
     *                        decoded payload is no longer the bytes that
     *                        arrived: re-encoding it to measure would put an
     *                        allocation on the hot path for a number the caller
     *                        already has.
     */
    public function handlePusherMessage(SwooleServer $server, int $fd, array $payload, int $frameBytes): void
    {
        $event = $payload['event'] ?? null;
        $channel = is_string($payload['channel'] ?? null) ? $payload['channel'] : null;
        $data = $this->decodeEventData($payload['data'] ?? null);

        if (!is_string($event) || $event === '') {
            $this->delivery->pushPusherError($server, $fd, 'invalid-event', 'Missing Pusher event name.');
            return;
        }

        match (true) {
            $event === 'pusher:ping' => $this->delivery->pushPusherEvent($server, $fd, 'pusher:pong', null, new \stdClass()),
            $event === 'pusher:pong' => null,
            $event === 'pusher:subscribe' => $this->subscriptions->handlePusherSubscribe($server, $fd, $data),
            $event === 'pusher:unsubscribe' => $this->subscriptions->handlePusherUnsubscribe($server, $fd, $data),
            str_starts_with($event, 'client-') => $this->clientEvents->handlePusherClientEvent($server, $fd, $channel, $event, $data, $frameBytes),
            default => $this->delivery->pushPusherError($server, $fd, 'unsupported-event', "Unsupported realtime event [{$event}]."),
        };
    }

    private function decodePayload(string $payload): mixed
    {
        if ($payload === '') {
            return null;
        }

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $payload;
        }
    }

    private function decodeEventData(mixed $data): mixed
    {
        if (!is_string($data)) {
            return $data;
        }

        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $data;
        }
    }
}
