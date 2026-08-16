<?php

namespace Lightspeed\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Arr;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Protocol\SubscriptionAuthorizer;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Laravel broadcast driver that delivers events through Lightspeed.
 *
 * Extends PusherBroadcaster so this instance carries the application's
 * channel-auth callbacks itself: Laravel registers Broadcast::channel()
 * callbacks on the DEFAULT connection's broadcaster instance, so this design
 * only works when the app's default broadcast connection has `lightspeed` as
 * its DRIVER. The connection's NAME is free, `'realtime' => ['driver' =>
 * 'lightspeed']` with `BROADCAST_CONNECTION=realtime` resolves this class and
 * works identically. This used to be stated as "the app must set
 * BROADCAST_CONNECTION=lightspeed", which is false and is why a doctor check
 * once failed a working setup.
 *
 * auth() and validAuthenticationResponse() are inherited: channel verification
 * runs against this instance's own registry and signatures come from the
 * injected Pusher client (local HMAC only, it never talks to a Pusher server).
 *
 * The credentials that client holds are NOT validated when it is built; see
 * assertCanSign() for where the refusal lives instead, and why.
 *
 * Three methods are overridden.
 *
 *   broadcast()                   event delivery bypasses the Pusher HTTP API
 *                                 entirely and flows through the package
 *                                 relay/broadcast bridge.
 *   auth()                        the start of one authorization attempt, and
 *                                 therefore where any grant left over from a
 *                                 previous one is discarded.
 *   validAuthenticationResponse() the only point in the request where all the
 *                                 facts needed to sign a grant are known: which
 *                                 socket and channel, whether the application
 *                                 said yes, and what it asked to attach.
 *
 * The ordering between the last two is the security property. Laravel calls
 * validAuthenticationResponse() only on the path where authorization PASSED, so
 * a channel callback that calls `Lightspeed::tag()` and then returns false, or
 * throws, signs nothing.
 */
class LightspeedBroadcaster extends PusherBroadcaster
{
    public function __construct(
        private readonly BroadcastBridge $bridge,
        private readonly PendingGrants $pending,
        private readonly RevocationLog $revocations,
        \Pusher\Pusher $pusher,
    ) {
        parent::__construct($pusher);
    }

    /**
     * Run the application's channel authorization.
     *
     * Three things are added. The signing credentials are checked, because this
     * is the first point in an authorization where they are needed and the
     * cheapest place to say so; see assertCanSign(). This request's pending-grant
     * slot is cleared before
     * the callback runs, so an authorization can never inherit a grant
     * described by an earlier one that was refused. And the socket id is
     * checked for SHAPE before anything signs anything with it.
     *
     * The shape check is not cosmetic. `socket_id` and `channel_name` both
     * arrive in this request body, and the untagged signature Pusher computes
     * covers `{socket_id}:{channel_name}` with nothing escaped, so a caller
     * who can choose both can choose where that join lands, and get the
     * application to sign a string that is byte-identical to a GRANTED signing
     * string for some other socket, carrying tags and permissions of their
     * choosing. Requiring `{integer}.{integer}`, which is what this server
     * mints and what every Pusher client sends, removes the choice.
     *
     * DEFENCE IN DEPTH, not a hole that was standing open. pusher-php-server
     * ^7.2 (a dependency of this package) already validates socket ids with
     * `/\A\d+\.\d+\z/` and channel names with a pattern containing no colon,
     * inside authorizeChannel(), which the inherited
     * validAuthenticationResponse() calls. So the collision was not reachable
     * through this broadcaster. The check stays because a package should not
     * rest a security property on a dependency's validation continuing to
     * exist, and it is stated here as duplication rather than as a fix.
     *
     * Nothing else about the normal contract changes: the parent's return value
     * is passed straight back, so `true` for a private channel and the member
     * array for a presence channel behave exactly as they always did.
     */
    public function auth($request)
    {
        $this->assertCanSign();

        $this->pending->begin();

        $socketId = $request->socket_id ?? null;

        // UNCONDITIONAL, like the two places that consume the result:
        // SubscriptionAuthorizer::signingString() and ::authorize() both refuse
        // anything that is not the shape, with no exemption for absent. The
        // tempting form, `is_string($socketId) && $socketId !== '' && !valid(...)`,
        // would let a missing, empty or non-string id skip the check entirely,
        // while docs/authorization.md states the refusal with no exception.
        // Nothing legitimate is lost: every Pusher client sends `socket_id` on
        // every channel-auth request, and a signature computed over an absent
        // one binds the grant to no connection at all.
        if (!is_string($socketId) || !SubscriptionAuthorizer::isValidSocketId($socketId)) {
            throw new AccessDeniedHttpException(
                'Lightspeed refuses a socket id that is not `{integer}.{integer}`.'
            );
        }

        // THE SAME REFUSAL SubscriptionAuthorizer MAKES, said where it can be
        // read. A `private-encrypted-` channel is refused because this server
        // does not implement the encryption its name promises. The websocket
        // side answers a refusal with a deliberately uninformative
        // subscription_error, which is correct for a security gate and useless
        // to the developer who wrote `Echo.encryptedPrivate(...)`; this request
        // is the one their browser makes first, and it has room for a sentence.
        // See SubscriptionAuthorizer::isEncryptedChannel().
        $channelName = $request->channel_name ?? null;

        if (is_string($channelName) && SubscriptionAuthorizer::isEncryptedChannel($channelName)) {
            throw new AccessDeniedHttpException(
                'Lightspeed does not implement end-to-end encrypted channels, so it refuses '
                .'`private-encrypted-` channels rather than relaying their payloads in clear '
                .'text. Use a `private-` channel (Echo.private) if payloads this server can '
                .'see are acceptable for this data.'
            );
        }

        return parent::auth($request);
    }

    /**
     * Build the auth response, folding in the grant the application described.
     *
     * The grant is not stored anywhere. It is encoded, appended to the string
     * the signature covers, and appended to the auth string itself:
     *
     *   key:signature          untagged, byte for byte what Pusher specifies
     *   key:signature:grant    tagged
     *
     * pusher-js treats `auth` as opaque and echoes it back untouched, so this
     * needs no client change and no protocol change, and because the grant is
     * inside the signature, a client that strips or edits it is refused by the
     * same comparison that catches a forged signature. See
     * Protocol\SubscriptionAuthorizer for the verifying half.
     *
     * An application that never calls `Lightspeed::tag()` takes the first
     * return below and never touches Redis, this class's extra fields, or
     * anything else this feature added.
     */
    public function validAuthenticationResponse($request, $result)
    {
        // Again, not only in auth(). This method is public, Laravel's
        // Broadcaster::verifyUserCanAccessChannel() reaches it, and it is the
        // point where the bytes are actually produced. The untagged form by
        // the parent below, the tagged form by the hash_hmac at the end. A
        // check that only guarded the entry point would be a check that a
        // caller can walk around. See assertCanSign().
        $this->assertCanSign();

        $response = parent::validAuthenticationResponse($request, $result);

        $pending = $this->pending->take();

        if ($pending === null) {
            return $response;
        }

        // The `callback` (JSONP) form of the parent's response is a Response
        // object rather than the array this needs to re-sign. Refusing is the
        // only safe answer: returning the parent's response unchanged would
        // hand back an UNGRANTED auth string for a channel the application
        // asked to protect, which is precisely the silent fail-open this design
        // exists to make impossible. JSONP channel auth is a legacy pusher-js
        // transport and no current client uses it.
        if (!is_array($response) || !is_string($response['auth'] ?? null)) {
            throw new BroadcastException(
                'Lightspeed cannot attach a grant to this channel-auth response. '
                .'`Lightspeed::tag()` is not supported with JSONP (`callback`) channel authorization.'
            );
        }

        $socketId = $request->socket_id ?? null;
        $channel = $request->channel_name ?? null;

        if (!is_string($socketId) || $socketId === '' || !is_string($channel) || $channel === '') {
            throw new BroadcastException(
                'Lightspeed cannot attach a grant without a socket_id and channel_name on the request.'
            );
        }

        // Redis's clock, not this process's. A grant's issue time is compared
        // against revocation times written by other processes on other
        // machines, and that comparison must not depend on how well their
        // clocks agree. A Redis this cannot reach throws, and the auth request
        // fails, which refuses the subscription rather than issuing a grant
        // whose age nobody can judge.
        //
        // The lifetime is declared to the revocation log in the same round trip
        // as the clock read, because a revocation has to outlive every grant it
        // invalidates and this is the only place that knows how long THIS grant
        // will live. A process that lowers the dial, or a rolling deploy where
        // the revoking process has the new value while the websocket workers
        // hold grants minted under the old one, would otherwise write a
        // revocation that lapses first, and a lapsed revocation readmits the
        // identical auth string, silently. See RevocationLog::revoke().
        $lifetimeSeconds = $this->revocations->grantLifetimeSeconds();
        $issuedAt = $this->revocations->noteGrantLifetime($lifetimeSeconds);

        $grant = new Grant(
            tags: $pending->tags(),
            payload: $pending->payload(),
            issuedAt: $issuedAt,
            expiresAt: $issuedAt + $lifetimeSeconds * 1_000_000,
        );

        $encodedGrant = $grant->encode();

        $channelData = isset($response['channel_data']) && is_string($response['channel_data'])
            ? $response['channel_data']
            : null;

        $settings = $this->pusher->getSettings();

        $response['auth'] = $settings['auth_key'].':'.hash_hmac(
            'sha256',
            SubscriptionAuthorizer::signingString($socketId, $channel, $channelData, $encodedGrant),
            $settings['secret'],
        ).':'.$encodedGrant;

        return $response;
    }

    /**
     * Refuse to sign anything with credentials that are not configured.
     *
     * WHY IT IS HERE AND NOT WHERE THE CLIENT IS BUILT. The provider used to
     * throw while constructing the Pusher client, inside the
     * `Broadcast::extend('lightspeed')` closure. That closure runs whenever the
     * broadcaster is RESOLVED, and `Broadcast::channel()` resolves it (it is not
     * a BroadcastManager method, so it falls through `__call` to `driver()`),
     * and Laravel 11 loads `routes/channels.php` during provider boot. So an
     * empty `LIGHTSPEED_APP_SECRET` did not break realtime, it killed the host
     * application at boot, every artisan command and every web request,
     * including in applications that had not started using realtime yet.
     *
     * Construction is not what needs credentials; signing is. `Pusher::__construct`
     * assigns its arguments into `$this->settings` and validates none of them,
     * so a client built from empty strings is a client that exists and cannot
     * sign. Which is exactly the state this method names.
     *
     * MOVING THE CHECK IS NOT WEAKENING IT. An empty secret makes every private
     * and presence auth string computable by anyone, so it must never produce
     * one. Both public methods that lead to a signature call this first, which
     * is strictly more of the signing surface than the old provider throw
     * covered: that one was skipped entirely by any code path holding a
     * broadcaster it built itself.
     *
     * KEY AND SECRET, NOT APP ID. The auth string is `key:hmac(..., secret)`;
     * the app id appears nowhere in it. The old provider check demanded all
     * three, so an app id alone could take down an application over a value
     * signing never reads. This is the same pair, for the same reason, as
     * `BootValidation::validateCredentials()`.
     */
    private function assertCanSign(): void
    {
        $settings = $this->pusher->getSettings();

        $missing = [];

        if ((string) ($settings['auth_key'] ?? '') === '') {
            $missing[] = 'app_key (LIGHTSPEED_APP_KEY, or REVERB_APP_KEY)';
        }

        if ((string) ($settings['secret'] ?? '') === '') {
            $missing[] = 'app_secret (LIGHTSPEED_APP_SECRET, or REVERB_APP_SECRET)';
        }

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(
            'Lightspeed cannot authorize a channel: '
            .implode(' and ', $missing).' is not configured. '
            .'Set it in [lightspeed.reverb_compat]. Without it every private and presence '
            .'channel signature can be computed by anyone, so Lightspeed refuses to issue one.'
        );
    }

    /**
     * Deliver an event.
     *
     * NO CREDENTIAL GUARD HERE, and that is a decision rather than an
     * oversight. This path signs nothing and verifies nothing: it hands the
     * channels and payload to the bridge, which publishes them through
     * `RedisRelay::publishMessage()`. Only relay CONTROL entries are signed
     * (`RedisRelay::controlSignature()`, whose own docblock says so), and
     * ordinary broadcasts carry no authorization decision. Guarding here would
     * refuse work that the missing credentials do not endanger. And the server
     * that would have to be running for this to reach a socket cannot have
     * started without them anyway (`BootValidation::validateCredentials()`).
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        $socketId = Arr::pull($payload, 'socket');

        try {
            $this->bridge->broadcast(
                $this->formatChannels($channels),
                (string) $event,
                $payload,
                is_string($socketId) ? $socketId : null,
            );
        } catch (\Throwable $e) {
            throw new BroadcastException(sprintf('Lightspeed error: %s.', $e->getMessage()));
        }
    }
}
