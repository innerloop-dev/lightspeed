<?php

namespace Lightspeed\Channels;

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\GrantCheck;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\Frames;
use Lightspeed\Protocol\PusherApp;
use Lightspeed\Protocol\SubscriptionAuthorizer;
use Lightspeed\Logging\OperatorLog;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Put one connection on a channel, or take it off one.
 *
 * The gate every subscription passes: the cheap refusals first, then the
 * signature, then the grant, and only then the allocation. The order is the
 * point and is documented at each step, an anonymous client must not be able
 * to make this server spend anything before it has proved something.
 *
 * dropSubscription() is the single way OFF a channel, and everything that has
 * to remove a connection uses it: an unsubscribe frame, an expired grant, a
 * revocation. A drop that skipped the presence half would leave a member in
 * every later snapshot who is not there, with nobody told it left.
 */
class Subscriptions
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ConnectionGrants $connectionGrants,
        private readonly PresenceStore $presenceStore,
        private readonly ResourceRouter $resourceRouter,
        private readonly SubscribeLimits $limits,
        private readonly GrantCheck $grantCheck,
        private readonly ConnectionId $connectionId,
        private readonly PusherApp $pusherApp,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
        private readonly OperatorLog $operatorLog,
    ) {
    }

    public function handlePusherSubscribe(SwooleServer $server, int $fd, mixed $data): void
    {
        if (!is_array($data)) {
            $this->delivery->pushPusherError($server, $fd, 'invalid-subscribe', 'Subscribe payload must be a JSON object.');
            return;
        }

        $channel = $this->requireChannel($data);
        if ($channel === null) {
            $this->delivery->pushPusherError($server, $fd, 'invalid-channel', 'Subscribe payload must include a channel.');
            return;
        }

        // BEFORE anything that allocates, and before the signature check, which
        // is the expensive half of this path. A public channel needs no
        // authorization at all, so this is the only gate between an anonymous
        // client and two hashtable entries per frame. See
        // SubscribeLimits::subscribeRefusal().
        $refusal = $this->limits->subscribeRefusal($fd, $channel);

        if ($refusal !== null) {
            $this->runtimeLogger->logWebsocket('subscription-error', [
                'fd' => $fd,
                // Truncated: repeating the input at full length would turn a
                // refused allocation into an accepted one, on disk.
                'channel' => ChannelName::forDisplay($channel),
                'reason' => $refusal,
            ]);

            $this->delivery->pushPusherError($server, $fd, $refusal, $this->limits->subscribeRefusalMessage($refusal));
            return;
        }

        // The socket id is the server's, not the client's: it was minted at
        // handshake and is signed into the auth string, so a client cannot
        // present an auth issued for some other connection.
        $decision = $this->subscriptionAuthorizer()->authorize(
            $this->channels->socketIdFor($fd),
            $channel,
            $data['auth'] ?? null,
            $data['channel_data'] ?? null,
        );

        if ($decision->denied()) {
            $this->runtimeLogger->logWebsocket('subscription-error', [
                'fd' => $fd,
                'channel' => $channel,
                'reason' => 'auth-failed',
            ]);

            $this->delivery->push($server, $fd, Frames::subscriptionError(
                $channel,
                401,
                'AuthError',
                'Subscription authorization failed.',
            ));
            return;
        }

        // Past the gate. The decision carries the verified presence identity,
        // which is null for every channel that is not a presence channel, and
        // the verified grant, which is null only when the application never
        // called Lightspeed::tag() for this channel.
        $presenceMember = $decision->presenceMember;

        // Recorded before the subscription, so a channel the application put a
        // grant on can never end up subscribed without it. Note what did NOT
        // happen to get here: no Redis read, no lookup, no handoff key. The
        // grant arrived inside the auth string the client had to present, and
        // was verified by the same HMAC that verified the socket and channel.
        if ($decision->grant !== null) {
            // The one Redis call on this path, and it happens ONLY when the
            // application signed a grant, so an untagged application still
            // subscribes without touching Redis at all, which is the opt-in
            // property, and a public channel still returns above without
            // reading a byte of the auth string.
            //
            // Why a granted subscribe is worth a round trip: everything else
            // about this path is a pure function of the auth string, so while
            // Redis was down an already-connected socket could replay a
            // still-unexpired auth string, land in the fan-out of a protected
            // channel, and then be neither revocable (the revocation cannot be
            // read) nor droppable by revoke (no control entry can arrive). It
            // was refused the moment it tried to SEND anything, and dropped when
            // its grant ran out, but until then it received everything said on
            // the channel. Proven on a live server.
            //
            // grantRefusal() is the same fail-closed answer the message path
            // gives, including refusing outright when Redis cannot answer.
            $refusal = $this->grantCheck->grantRefusal($decision->grant);

            if ($refusal !== null) {
                $this->runtimeLogger->logWebsocket('subscription-error', [
                    'fd' => $fd,
                    'channel' => $channel,
                    'reason' => $refusal,
                ]);

                $this->delivery->push($server, $fd, Frames::subscriptionError(
                    $channel,
                    401,
                    'AuthError',
                    'Subscription authorization failed.',
                ));
                return;
            }

            $this->connectionGrants->mint($fd, $channel, $decision->grant);
        } else {
            // No grant in this auth string means the application is not tagging
            // this channel any more: a deploy that removed the tag() call, say.
            // Any grant held from an earlier subscribe has to go with it, or the
            // connection stays behind a grant that will expire and then refuse
            // every message forever, with re-subscribing unable to clear it.
            //
            // This is not a way out of a grant. The auth string is signed by the
            // application, so a client cannot produce an ungranted one for a
            // channel the application tags.
            //
            // It is, however, a silent CHANGE OF MODE, which is why it is said
            // out loud. Dropping the held grant moves this connection onto the
            // untagged path, where nothing is re-checked per message ever
            // again. That is a supported opt-out when it is deliberate and a
            // trap when it is a deploy that lost a tag() call, and the two are
            // indistinguishable from here.
            if ($this->connectionGrants->grantFor($fd, $channel) !== null) {
                $this->operatorLog->reportGrantDropped($channel);
            }

            $this->connectionGrants->forget($fd, $channel);
        }

        $result = $this->channels->subscribe(
            $fd,
            $channel,
            is_array($presenceMember) ? $presenceMember : null,
        );

        $this->resourceRouter->claimOwnerForChannel($channel, 'channel-subscribe');

        $presenceJoin = null;
        if (str_starts_with($channel, 'presence-') && is_array($presenceMember)) {
            $presenceJoin = $result['new_subscription']
                ? $this->presenceStore->join($channel, $this->connectionId->presenceConnectionId($fd), $presenceMember)
                : [
                    'broadcast_member_added' => false,
                    'snapshot' => $this->presenceStore->snapshot($channel),
                ];
        }

        $payload = str_starts_with($channel, 'presence-')
            ? ['presence' => $presenceJoin['snapshot'] ?? $this->presenceStore->snapshot($channel)]
            : new \stdClass();

        $this->delivery->pushPusherEvent($server, $fd, 'pusher_internal:subscription_succeeded', $channel, $payload);

        if (($presenceJoin['broadcast_member_added'] ?? false) && is_array($presenceMember)) {
            $memberAdded = is_array($presenceJoin['member'] ?? null)
                ? $presenceJoin['member']
                : [
                    'user_id' => (string) ($presenceMember['user_id'] ?? ''),
                    'user_info' => $presenceMember['user_info'] ?? new \stdClass(),
                ];

            $this->delivery->broadcastPusherEvent(
                $channel,
                'pusher_internal:member_added',
                $memberAdded,
                $fd,
            );
        }
    }

    public function handlePusherUnsubscribe(SwooleServer $server, int $fd, mixed $data): void
    {
        if (!is_array($data)) {
            $this->delivery->pushPusherError($server, $fd, 'invalid-unsubscribe', 'Unsubscribe payload must be a JSON object.');
            return;
        }

        $channel = $this->requireChannel($data);
        if ($channel === null) {
            $this->delivery->pushPusherError($server, $fd, 'invalid-channel', 'Unsubscribe payload must include a channel.');
            return;
        }

        $this->dropSubscription($fd, $channel);
    }

    /**
     * Take one connection off one channel, completely.
     *
     * Shared rather than reimplemented, because revocation and grant expiry
     * need exactly this: a connection dropped without its presence membership
     * going with it stays in every later presence snapshot as a member who is
     * not there, and nobody watching the channel is told it left.
     *
     * The grant goes too. It has to: leaving it behind would let the next
     * subscribe find a stale grant already recorded for the channel, and the
     * connection is no longer in the fan-out that the grant described.
     */
    public function dropSubscription(int $fd, string $channel): void
    {
        $result = $this->channels->unsubscribe($fd, $channel);
        $this->connectionGrants->forget($fd, $channel);

        $presenceLeave = null;
        if (str_starts_with($channel, 'presence-') && is_array($result['presence_member'])) {
            $presenceLeave = $this->presenceStore->leave($channel, $this->connectionId->presenceConnectionId($fd));
        }

        if (($presenceLeave['broadcast_member_removed'] ?? false) && is_array($result['presence_member'])) {
            $this->delivery->broadcastPusherEvent(
                $channel,
                'pusher_internal:member_removed',
                [
                    'user_id' => (string) ($result['presence_member']['user_id'] ?? ''),
                ],
                $fd,
            );
        }
    }

    private function requireChannel(array $payload): ?string
    {
        $channel = $payload['channel'] ?? null;

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    /**
     * The subscription gate, built from this app's credentials.
     *
     * Constructed per call rather than held: the server object outlives config
     * changes across a worker restart, and the credentials are cheap to read.
     * The decision itself is pure; see SubscriptionAuthorizer.
     */
    private function subscriptionAuthorizer(): SubscriptionAuthorizer
    {
        return new SubscriptionAuthorizer(
            $this->pusherApp->key(),
            $this->pusherApp->secret(),
        );
    }
}
