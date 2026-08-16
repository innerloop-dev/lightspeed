<?php

namespace Lightspeed\Diagnostics;

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Channels\ChannelName;
use Lightspeed\Protocol\Frames;
use Lightspeed\Workers\WorkerContext;

/**
 * The local diagnostic protocol: the `{"type": ...}` verbs a probe uses to
 * inspect a running node from the box it runs on.
 *
 * It exists so an operator can subscribe, whisper, broadcast and ask "who am
 * I" against a live process without an application, an app key, or a channel
 * authorization round trip, which is exactly why it must never be reachable
 * by a Pusher client. This class performs NO authorization of its own and is
 * not the gate: eligibility is decided once per connection, in Server's `open`
 * handler (loopback + `lightspeed.diagnostics.enabled` + no forwarding
 * headers), recorded in `$diagnosticFds`, and re-checked there for every frame
 * before this class is reached. Read it as: everything here is already
 * trusted, because Server decided so.
 *
 * It deliberately does NOT own delivery either. Every verb answers with
 * exactly one frame, so handle() returns that frame and Server pushes it. That
 * keeps the diagnostic envelope structurally unable to reach a Pusher socket
 * (there is only one call site, and it sits inside the `$diagnosticFds` check)
 * and it makes every verb testable without a listening socket.
 *
 *   {"type": "subscribe"}   -> {"type": "subscribed", ...}
 *   {"type": "unsubscribe"} -> {"type": "unsubscribed", ...}
 *   {"type": "whisper"}     -> fan out, then {"type": "whispered", ...}
 *   {"type": "send"}        -> ping | whoami | broadcast-test -> {"type": "response", ...}
 *   anything else           -> {"type": "error", ...}
 */
class DiagnosticProtocol
{
    /**
     * Every message type a client may SEND. The hello frame advertises this
     * list as the protocol's documentation, so it lives here, next to the
     * match that enforces it: adversarial review found the greeting advertising two
     * server-to-client types (`response`, `broadcast`) that the match refused
     * as unsupported.
     *
     * @var list<string>
     */
    public const CLIENT_TYPES = ['subscribe', 'unsubscribe', 'whisper', 'send'];

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly BroadcastBridge $broadcastBridge,
        private readonly WorkerContext $workerContext,
    ) {
    }

    /**
     * Handle one frame of the diagnostic protocol.
     *
     * Never call this for a Pusher socket: this protocol has no channel
     * authorization, and callers reach it only after passing the loopback +
     * enabled gate in Server's `open` handler.
     *
     * @param  mixed  $payload  The decoded frame, which may be anything a
     *                          client sent, including a non-array.
     * @return array<string, mixed> The single frame to send back.
     */
    public function handle(int $fd, mixed $payload): array
    {
        if (!is_array($payload)) {
            return Frames::diagnosticError('invalid-message', 'Expected a JSON object payload.');
        }

        $type = $payload['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return Frames::diagnosticError('invalid-message', 'Missing message type.');
        }

        return match ($type) {
            'subscribe' => $this->subscribe($fd, $payload),
            'unsubscribe' => $this->unsubscribe($fd, $payload),
            'whisper' => $this->whisper($fd, $payload),
            'send' => $this->send($fd, $payload),
            // Adding an arm here means adding it to CLIENT_TYPES, which is
            // what the hello frame advertises; the greeting and this match
            // may never disagree.
            default => Frames::diagnosticError('unsupported-type', "Unsupported message type [{$type}]."),
        };
    }

    /** @return array<string, mixed> */
    private function subscribe(int $fd, array $payload): array
    {
        $channel = $this->requireChannel($payload);
        if ($channel === null) {
            return Frames::diagnosticError('invalid-channel', 'Subscribe requires a channel string.');
        }

        // The same name bound the Pusher path enforces. This surface is
        // loopback-only and ships disabled, so it is not the exposure that
        // motivated the limit, but it reaches the identical allocation, and a
        // bound that one of two callers skips is a bound with a hole in it.
        $refusal = ChannelName::rejection($channel);

        if ($refusal !== null) {
            return Frames::diagnosticError($refusal, 'Subscribe channel name is not a valid channel name.');
        }

        $this->channels->subscribe($fd, $channel);

        return [
            'type' => 'subscribed',
            'channel' => $channel,
            'members' => $this->channels->members($channel),
        ];
    }

    /** @return array<string, mixed> */
    private function unsubscribe(int $fd, array $payload): array
    {
        $channel = $this->requireChannel($payload);
        if ($channel === null) {
            return Frames::diagnosticError('invalid-channel', 'Unsubscribe requires a channel string.');
        }

        $this->channels->unsubscribe($fd, $channel);

        return [
            'type' => 'unsubscribed',
            'channel' => $channel,
        ];
    }

    /** @return array<string, mixed> */
    private function whisper(int $fd, array $payload): array
    {
        $channel = $this->requireChannel($payload);
        if ($channel === null) {
            return Frames::diagnosticError('invalid-channel', 'Whisper requires a channel string.');
        }

        if (!$this->channels->isSubscribed($fd, $channel)) {
            return Frames::diagnosticError('not-subscribed', "Connection is not subscribed to [{$channel}].");
        }

        $delivered = $this->broadcastBridge->fanOutMessage(
            [$channel],
            [
                'type' => 'whisper',
                'from' => ['id' => (string) $fd],
                'data' => $payload['data'] ?? null,
            ],
            $this->exclusionIdFor($fd),
        );

        return [
            'type' => 'whispered',
            'channel' => $channel,
            'delivered' => $delivered,
        ];
    }

    /**
     * The id a whisper excludes itself by.
     *
     * A diagnostic connection has no socket id, and that is by design: socket
     * ids are a Pusher-protocol concept, minted on the handshake path, and
     * Server never runs that path for a diagnostic connection. Fan-out
     * exclusion, however, is keyed by socket id the whole way down (the relay
     * turns it back into a local fd with ChannelManager::fdForSocketId()), so a
     * null exclusion is no exclusion at all, and the whisperer hears its own
     * whisper come back.
     *
     * What is actually there is the fd, so the connection is registered under
     * an id built from it, scoped by process key. The scope matters because the
     * exclusion travels: the relay puts it on the Redis stream verbatim and
     * every consuming process resolves it against its own fd map. A bare fd is
     * unique within one server but not across a cluster, so two instances could
     * each hold a diagnostic connection numbered 3 and silently exclude each
     * other's. Prefixing with the process key makes the id meaningless anywhere
     * but the process that minted it, which is exactly right: an fd means
     * nothing off-process, and a whisperer is always local.
     *
     * The shape also cannot collide with a real Pusher socket id, which is
     * always `{fd}.{random}`.
     *
     * Registering is idempotent and only ever happens for a connection that has
     * no socket id, so a Pusher socket reaching this code keeps its real one.
     */
    private function exclusionIdFor(int $fd): string
    {
        $socketId = $this->channels->socketIdFor($fd);

        if ($socketId !== null && $socketId !== '') {
            return $socketId;
        }

        $exclusionId = $this->workerContext->currentProcessKey().':fd:'.$fd;

        $this->channels->connect($fd, $exclusionId, $this->channels->connectionContext($fd));

        return $exclusionId;
    }

    /** @return array<string, mixed> */
    private function send(int $fd, array $payload): array
    {
        $id = $payload['id'] ?? null;
        $action = $payload['action'] ?? null;

        if (!is_string($id) || $id === '') {
            return Frames::diagnosticError('invalid-id', 'Send requires a string id.');
        }

        if (!is_string($action) || $action === '') {
            return Frames::diagnosticResponse($id, false, null, 'Send requires an action.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return match ($action) {
            'ping' => Frames::diagnosticResponse($id, true, [
                'pong' => true,
                'echo' => $data,
            ]),
            'whoami' => Frames::diagnosticResponse($id, true, [
                'fd' => $fd,
                'channels' => $this->channels->channelsFor($fd),
                'workerId' => $this->workerContext->workerId(),
                'instanceId' => $this->workerContext->instanceId(),
                'processKey' => $this->workerContext->currentProcessKey(),
            ]),
            'broadcast-test' => $this->broadcastTest($id, $data),
            default => Frames::diagnosticResponse($id, false, null, "Unsupported action [{$action}]."),
        };
    }

    /** @return array<string, mixed> */
    private function broadcastTest(string $id, array $data): array
    {
        $channel = $data['channel'] ?? null;
        $event = $data['event'] ?? null;

        if (!is_string($channel) || $channel === '') {
            return Frames::diagnosticResponse($id, false, null, 'broadcast-test requires a channel.');
        }

        if (!is_string($event) || $event === '') {
            return Frames::diagnosticResponse($id, false, null, 'broadcast-test requires an event.');
        }

        $broadcastPayload = [
            'type' => 'broadcast',
            'channel' => $channel,
            'event' => $event,
            'data' => $data['payload'] ?? null,
        ];

        $delivered = $this->broadcastBridge->fanOutMessage([$channel], $broadcastPayload);

        return Frames::diagnosticResponse($id, true, [
            'channel' => $channel,
            'event' => $event,
            'delivered' => $delivered,
        ]);
    }

    private function requireChannel(array $payload): ?string
    {
        $channel = $payload['channel'] ?? null;

        return is_string($channel) && $channel !== '' ? $channel : null;
    }
}
