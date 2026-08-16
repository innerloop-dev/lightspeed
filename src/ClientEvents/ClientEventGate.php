<?php

namespace Lightspeed\ClientEvents;

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\GrantCheck;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\Frames;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * What a `client-*` frame passes through before the application sees it, and
 * how the application's answer gets back.
 *
 * Six refusals and one handoff. The refusals are ordered cheapest first, and
 * the last of them is the whole per-message security claim: a channel the
 * application put a grant on is re-checked against Redis on EVERY frame, and a
 * refusal is a `lightspeed:stale` frame rather than a dropped socket, so the
 * client re-authorizes instead of guessing.
 *
 * An application that never called Lightspeed::tag() pays for none of it: the
 * grant lookup is the array read a null check always was, and the Redis round
 * trip only happens when a grant is there to check.
 */
class ClientEventGate
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ConnectionGrants $connectionGrants,
        private readonly GrantCheck $grantCheck,
        private readonly OctaneWorker $httpWorker,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * @param int $frameBytes the size of the frame this event arrived in, as it
     *                        came off the wire. Passed in rather than derived
     *                        here: the router already holds it, and deriving it
     *                        would mean re-encoding the decoded payload on the
     *                        hot path to learn a number that is already known.
     */
    public function handlePusherClientEvent(SwooleServer $server, int $fd, ?string $channel, string $event, mixed $data, int $frameBytes): void
    {
        // First because it is one integer comparison, and because everything
        // below it is work done on the client's behalf: the payload crosses
        // into the application, and an unanswered event is fanned back out to
        // every other subscriber on the channel.
        $maxFrameBytes = ClientEventLimits::maxFrameBytes();

        if ($frameBytes > $maxFrameBytes) {
            // Neither the channel nor the event name is logged: both arrived in
            // this frame, neither has been validated yet, and an oversized
            // frame is exactly the one whose unvalidated strings should not be
            // written to the server's disk. The fd and the two numbers are what
            // an operator needs to tell a client that is too chatty from a
            // limit that is set too low.
            $this->runtimeLogger->logWebsocket('too-large', [
                'fd' => $fd,
                'bytes' => $frameBytes,
                'limit' => $maxFrameBytes,
            ]);

            $this->delivery->pushPusherError(
                $server,
                $fd,
                'client-event-too-large',
                ClientEventLimits::tooLargeMessage(),
            );

            return;
        }

        if (!is_string($channel) || $channel === '') {
            $this->delivery->pushPusherError($server, $fd, 'invalid-channel', 'Client events must include a channel.');
            return;
        }

        if (!$this->channels->isSubscribed($fd, $channel)) {
            $this->delivery->pushPusherError($server, $fd, 'not-subscribed', "Connection is not subscribed to [{$channel}].");
            return;
        }

        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            $this->delivery->pushPusherError($server, $fd, 'invalid-channel', 'Client events are limited to private and presence channels.');
            return;
        }

        if (!$this->httpWorker->isBooted()) {
            $this->delivery->pushPusherError($server, $fd, 'worker-unavailable', 'Lightspeed HTTP worker is not ready.');
            return;
        }

        // The re-check, and the reason this is not just subscribe-time auth.
        //
        // A grant exists only if the application signed one, so an application
        // that did not is not slowed down and not refused. That is the opt-in,
        // and it is the same array lookup a null check always was.
        $grant = $this->connectionGrants->grantFor($fd, $channel);

        if ($grant !== null) {
            $refusal = $this->grantCheck->grantRefusal($grant);

            if ($refusal !== null) {
                $this->runtimeLogger->logWebsocket('stale', [
                    'fd' => $fd,
                    'channel' => $channel,
                    'event' => $event,
                    'reason' => $refusal,
                ]);

                $this->delivery->pushStale($server, $fd, $channel, $refusal);
                return;
            }
        }

        try {
            // The handler runs inside the application container, on the worker
            // that serves HTTP, so a client event sees exactly the services an
            // HTTP request would. A handler that throws comes back out of
            // runTask as its original exception, and is caught below.
            $result = $this->httpWorker->runTask(function () use ($fd, $channel, $event, $data, $grant) {
                // The presence member was recorded when this socket's
                // subscription passed the application's own channel
                // authorization, so it is an approved identity rather than
                // something the client asserted in this frame.
                $presenceMember = $this->channels->presenceMember($fd, $channel);

                return app(ClientEventDispatcher::class)->dispatch(new ClientEvent(
                    fd: $fd,
                    channel: $channel,
                    event: $event,
                    data: $data,
                    socketId: $this->channels->socketIdFor($fd),
                    userId: isset($presenceMember['user_id']) ? (string) $presenceMember['user_id'] : null,
                    userInfo: isset($presenceMember['user_info']) && is_array($presenceMember['user_info'])
                        ? $presenceMember['user_info']
                        : null,
                    connectionContext: $this->channels->connectionContext($fd),
                    // Carried through untouched: the package signs this
                    // payload, verifies it, and hands it back. It never reads
                    // a key of it.
                    auth: $grant?->payload,
                ));
            });
        } catch (\Throwable $e) {
            // The detail (message, class names, file positions) stays on the
            // server: a handler failure is an operator's problem, and the
            // client has no use for internals it cannot act on.
            $this->runtimeLogger->logWebsocket('error', [
                'fd' => $fd,
                'event' => $event,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()).':'.$e->getLine(),
            ]);
            $this->delivery->pushPusherError($server, $fd, 'client-event-error', 'Client event could not be handled.');
            return;
        }

        if ($result !== null) {
            if ($result->errorCode !== null && $result->errorMessage !== null) {
                $this->delivery->pushPusherError($server, $fd, $result->errorCode, $result->errorMessage);
                return;
            }

            if ($result->requestId !== null && is_array($result->response)) {
                $this->delivery->push($server, $fd, Frames::lightspeedResponse(
                    $result->requestId,
                    $result->response,
                    $result->status,
                    $channel,
                ));
            }

            return;
        }

        $this->delivery->broadcastPusherEvent($channel, $event, $data, $fd);
    }
}
