<?php

namespace Lightspeed\Protocol;

use Lightspeed\Auth\Grant;

/**
 * The gate on private and presence channels: does this client's `auth` string
 * prove that the host application authorized *this* socket for *this* channel?
 *
 * Why this file exists: this is the whole of channel security on the websocket
 * surface. The application's own auth endpoint signs a socket id and a channel
 * name (and, for presence, the member identity it is willing to publish) with
 * the shared app secret; the server's only job is to recompute that signature
 * and refuse anything that does not match, byte for byte. That is a pure
 * function of five values, and it deserves a test suite that can prove a wrong
 * secret, a stolen socket id, a swapped channel and a tampered member payload
 * are each rejected, none of which should need a running Swoole server.
 *
 * The signed string is Pusher's, and every part of it is load-bearing:
 *
 *   private-*   socketId:channel
 *   presence-*  socketId:channel:channelData
 *
 * The socket id binds the grant to one connection, the channel name binds it
 * to one channel, and channelData binds the presence member's claimed identity
 * to the signature: without it in the signed string, a client holding a valid
 * auth for its own presence channel could rewrite its user_id at will.
 *
 * PER-MESSAGE AUTHORIZATION RIDES IN THE SAME STRING. An application that calls
 * `Lightspeed::tag()` in its channel callback gets a third field appended:
 *
 *   key:signature                two fields: no grant, the untagged default
 *   key:signature:grant          three fields: the grant, base64url encoded
 *
 * and the signed string changes shape to match:
 *
 *   untagged   Pusher's own, byte for byte: socketId:channel[:channelData]
 *   granted    this package's own, every field length-prefixed; see
 *              signingString(), which explains why the granted form cannot
 *              reuse the untagged one's variable-arity join
 *
 * pusher-js treats `auth` as an opaque string and echoes it back untouched, so
 * this is not a protocol change and needs no client change. What it buys is the
 * property the reverted design could not have: the grant IS the credential.
 * Snip the third field and the presented signature no longer matches the
 * two-field signing string; edit a tag or a permission inside it and it no
 * longer matches the three-field one. Either way the subscription is refused by
 * the same comparison that catches a forged signature, and there is no state in
 * which a client holds a valid auth string whose grant has gone missing.
 *
 * A grant also carries its own expiry, which the bare Pusher auth string does
 * not. That closes the hole this replaces: an old auth string could be replayed
 * forever, and a backgrounded tab did it by accident.
 *
 * Owns: which channels are protected, which are refused outright as promising
 * an encryption this server does not implement (see isEncryptedChannel()), the
 * signing string, the HMAC comparison,
 * the decode and expiry of a grant, and the decode of a presence member out of
 * channel_data.
 * Deliberately does not own: where the socket id came from (ChannelManager
 * holds it), what happens to a rejected subscription on the wire (Server
 * answers it with a subscription_error frame), presence membership itself
 * (PresenceStore), or whether a verified grant has since been revoked
 * (Auth\RevocationLog, on every message).
 */
class SubscriptionAuthorizer
{
    /**
     * The version tag the granted signing string starts with.
     *
     * It is a version rather than a constant name so that changing the layout
     * later invalidates outstanding grants instead of silently reinterpreting
     * them; grants are short-lived by construction, so the cost of that is one
     * re-authorization per connection.
     */
    public const GRANT_SIGNING_PREFIX = 'lightspeed.grant.v1';

    /**
     * The socket id is a SHAPE, not a free string, and that is a security
     * property rather than tidiness.
     *
     * The untagged signing string is `{socketId}:{channel}` (two values joined
     * with a colon and nothing escaped), and on the signing side BOTH of them
     * arrive in the /broadcasting/auth request body. So an unconstrained socket
     * id lets a caller choose where that join lands: pick
     * `lightspeed.grant.v1:7:123.456` as the socket id and the right tail as
     * the channel name, and Pusher's own untagged signature covers a string
     * byte-identical to the GRANTED form for socket `123.456`: an arbitrary
     * grant, with tags and permissions of the caller's choosing, signed by the
     * application. It needs a channel pattern permissive enough to match the
     * crafted name, which is why it is a shape check and not a rewrite of the
     * protocol's signing string.
     *
     * Found while writing the mutation test for GRANT_SIGNING_PREFIX: the
     * version tag stops the two forms being confused for each other only if
     * nothing can put the version tag at the front of an untagged one.
     *
     * `{integer}.{integer}` is Protocol\SocketId's own format, and Pusher's:
     * pusher-js parses it, the server SDKs emit it, and no real client has ever
     * sent anything else.
     *
     * HOW MUCH THIS IS ACTUALLY LOAD-BEARING, honestly. pusher-php-server ^7.2
     * (this package's own dependency) already validates socket ids with
     * `/\A\d+\.\d+\z/`, and channel names with a pattern containing no colon,
     * inside authorizeChannel(), which the inherited
     * PusherBroadcaster::validAuthenticationResponse() calls. So the collision
     * above was NOT reachable through this broadcaster, and this is a duplicate
     * of an upstream guard. It stays because a package should not rest a
     * security property on a dependency's validation continuing to exist, and
     * because Protocol\SubscriptionAuthorizer is also reached from the websocket
     * side, where no Pusher SDK has looked at anything.
     *
     * `\A` and `\z`, not `^` and `$`: PCRE's `$` matches before a trailing
     * newline, so `"123.456\n"` used to satisfy a check that says it accepts
     * `{integer}.{integer}` and nothing else.
     */
    public static function isValidSocketId(string $socketId): bool
    {
        return preg_match('/\A\d{1,20}\.\d{1,20}\z/', $socketId) === 1;
    }

    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Does subscribing to this channel require an authorization signature?
     *
     * Public channels are open by design; everything private- or presence-
     * prefixed goes through authorize() and is refused on failure.
     *
     * THE DASH MATTERS, AND LARAVEL DISAGREES ABOUT IT. This is the Pusher
     * protocol's rule, which is what every Pusher client and server implements.
     * Laravel's own PusherBroadcaster guards with
     * `str_starts_with($name, 'private')`, no dash, so a channel called
     * `privateOrders` RUNS the application's `Broadcast::channel()` callback,
     * which is exactly the observation that makes an author believe the channel
     * is protected, while this server treats the name as public and lets anyone
     * subscribe with no auth string at all.
     *
     * Not changed, because following the protocol is the whole point of this
     * class and a name Echo cannot generate is not worth diverging for
     * (`Echo.private('orders')` produces `private-orders`). Documented instead,
     * here and in docs/authorization.md, and pinned by a test.
     */
    public static function isProtectedChannel(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    /**
     * Does this channel name promise end-to-end encryption?
     *
     * `private-encrypted-` is not a stricter flavour of `private-`. In the
     * Pusher protocol it means the payload is sealed with a secret shared
     * between the application and the subscribing clients, and that the server
     * relaying it never holds plaintext. Lightspeed implements no part of that:
     * it has no shared secret, no sealing, and every payload it fans out is the
     * clear text the publisher sent.
     *
     * The prefix is nonetheless a channel name like any other, so it used to
     * pass isProtectedChannel() on the strength of starting with `private-`,
     * authorize normally, and work. `Echo.encryptedPrivate('orders')` produced
     * a channel that subscribed, delivered, and gave the application precisely
     * the property it had chosen that API to avoid, with nothing anywhere
     * reporting the difference.
     *
     * WHY IT IS REFUSED RATHER THAN ACCEPTED, and why that is not a close call.
     * The two options are not symmetric. Refusing costs an application that
     * asked for encryption a failed subscription with an explanation, at the
     * first attempt, in development. Accepting costs it the security property
     * it believes it has, silently, in production, for as long as nobody
     * happens to read this file. A server may decline to implement a feature;
     * what it may not do is answer "yes" to a request for a guarantee it does
     * not provide. Naming the prefix here, rather than folding it into
     * isProtectedChannel(), keeps the two questions apart: this channel is
     * still protected, and it is separately undeliverable.
     *
     * Implementing the feature for real is the alternative, and it is a
     * genuinely larger piece of work (a shared-secret channel, key rotation,
     * and the publisher side sealing payloads). Refusing does not foreclose it:
     * an application whose channels are refused today is one whose channels
     * would start working the day it lands.
     */
    public static function isEncryptedChannel(string $channel): bool
    {
        return str_starts_with($channel, 'private-encrypted-');
    }

    /**
     * Decide a subscription request.
     *
     * The three outcomes are deliberately distinct, and SubscriptionDecision is
     * what keeps them distinct: "public channel, nothing to authorize" and
     * "protected channel, signature verified, no member to carry" both end up
     * holding no presence member, and a caller must not have to restate the
     * security question (is this channel protected?) to tell them apart. See
     * that class for the outcome table.
     *
     * @param  ?string  $socketId  the id this connection was given at handshake
     * @param  mixed  $auth  the client's claimed `{app_key}:{hmac}[:{grant}]` string
     * @param  mixed  $channelData  the client's claimed member JSON, presence only
     * @param  ?int  $nowMicros  the instant to judge a grant's expiry against,
     *                            in microseconds; defaults to this process's
     *                            clock, which is a backstop rather than the
     *                            ordering-critical comparison; see Grant
     */
    public function authorize(?string $socketId, string $channel, mixed $auth, mixed $channelData, ?int $nowMicros = null): SubscriptionDecision
    {
        // First, and before anything reads the auth string: a public channel is
        // open by design and this method must not do a byte of work for it. The
        // reverted build put a Redis round trip on this path, which let an
        // unauthenticated client stall the whole server by subscribing to
        // public channels in a loop.
        if (!static::isProtectedChannel($channel)) {
            return SubscriptionDecision::openChannel();
        }

        // Before the signature, because no signature can make this channel
        // deliverable: the name states a property this server does not
        // implement, so a correctly authorized one is refused exactly as a
        // forged one is. See isEncryptedChannel().
        if (static::isEncryptedChannel($channel)) {
            return SubscriptionDecision::refused();
        }

        // The shape as well as the presence. This server mints the id itself so
        // it always passes; what it rules out is any other route by which a
        // signature could have been computed over a socket id that is really a
        // fragment of some other signing string. See isValidSocketId().
        if (!is_string($socketId) || !static::isValidSocketId($socketId)) {
            return SubscriptionDecision::refused();
        }

        if (!is_string($auth) || $auth === '') {
            return SubscriptionDecision::refused();
        }

        $channelDataString = null;
        $presenceMember = null;

        if (str_starts_with($channel, 'presence-')) {
            if (!is_string($channelData) || $channelData === '') {
                return SubscriptionDecision::refused();
            }

            $channelDataString = $channelData;

            try {
                $presenceMember = json_decode($channelDataString, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return SubscriptionDecision::refused();
            }

            // The empty string is a string, and it is not an identity: a
            // member with user_id '' joined the fan-out while the presence
            // store silently wrote no row, so it appeared in nobody's
            // snapshot, including its own, and no member_added was ever sent.
            if (!is_array($presenceMember) || !is_string($presenceMember['user_id'] ?? null) || $presenceMember['user_id'] === '') {
                return SubscriptionDecision::refused();
            }
        }

        // Split off the grant, if the application signed one in. The count is
        // exact on purpose: two fields is the untagged form, three is the
        // granted form, and anything else is a string this server did not
        // write. A `presence-` channel's member JSON contains colons, but it
        // travels in channel_data rather than in `auth`, so it cannot inflate
        // this count.
        $fields = explode(':', $auth);
        $encodedGrant = null;

        if (count($fields) === 3) {
            $encodedGrant = $fields[2];

            // EQUIVALENT TODAY, and kept for what it stops depending on.
            //
            // Removing this line changes nothing observable, and TWO
            // independent things make that true rather than one. An earlier
            // version of this comment named only the second and called it "the
            // ONLY thing", which understated the guard it was defending:
            //
            //   1. The HMAC. An empty grant field puts this on the GRANTED
            //      signing string (see signingString()), whose fields are
            //      length-prefixed, so the grant signs as `:0:` and the whole
            //      payload is a different shape from the untagged Pusher one.
            //      A legitimately issued untagged credential therefore cannot
            //      be edited into `key:signature:`; producing one needs the app
            //      secret, and anyone holding that can mint whatever grant they
            //      like and has no use for an empty one.
            //   2. Grant::decode(''), which returns null and refuses, for
            //      anything that somehow got past the first.
            //
            // Neither is a reason to read a shape this class can see is wrong
            // and carry on. It refuses here, where the shape of the auth string
            // is what is being read, rather than resting on a signature
            // property that is easy to change and a guard three calls away in a
            // class with a different job.
            if ($encodedGrant === '') {
                return SubscriptionDecision::refused();
            }
        } elseif (count($fields) !== 2) {
            return SubscriptionDecision::refused();
        }

        $signaturePayload = static::signingString($socketId, $channel, $channelDataString, $encodedGrant);

        $expected = $this->appKey.':'.hash_hmac('sha256', $signaturePayload, $this->appSecret);

        if ($encodedGrant !== null) {
            $expected .= ':'.$encodedGrant;
        }

        if (!hash_equals($expected, $auth)) {
            return SubscriptionDecision::refused();
        }

        // Past the HMAC. Everything below only applies to a grant, so an
        // application that never called tag() reaches the same return it always
        // did, by the same path, having done the same work.
        if ($encodedGrant === null) {
            return SubscriptionDecision::granted($presenceMember);
        }

        $grant = Grant::decode($encodedGrant);

        if ($grant === null) {
            return SubscriptionDecision::refused();
        }

        // The grant's own expiry, which the bare Pusher auth string has no room
        // for. Without it an auth string is a permanent credential: hold a
        // socket, wait, replay it, and you are subscribed again with a grant
        // nobody can revoke because it predates every revocation that will ever
        // be written. That was proven on a running server against the design
        // this replaces.
        if ($grant->hasExpired($nowMicros ?? (int) (microtime(true) * 1_000_000))) {
            return SubscriptionDecision::refused();
        }

        return SubscriptionDecision::granted($presenceMember, $grant);
    }

    /**
     * The exact bytes an auth signature covers.
     *
     * Public and static because two places must agree on it byte for byte
     * (this class, verifying, and Broadcasting\LightspeedBroadcaster, signing),
     * and a signing string that exists in two copies is a signing string that
     * will eventually exist in two versions.
     *
     * TWO FORMS, AND THE SPLIT IS THE POINT.
     *
     * The untagged form is Pusher's, byte for byte, because that is what keeps
     * every existing client working: `socketId:channel`, plus `:channelData`
     * for presence. It joins a variable number of fields with ':' and escapes
     * nothing, which is ambiguous, but it is the protocol's, it is what every
     * other Pusher server computes, and changing it would break clients rather
     * than protect them.
     *
     * The granted form does NOT inherit that ambiguity, because it is this
     * package's own and nothing else has to be able to compute it. Every field
     * is length-prefixed, so no field can absorb another, whatever bytes an
     * application puts in a channel name or a client puts in channel_data.
     *
     * That ambiguity was real, not theoretical. Under the old variable-arity
     * join, a grant signed for `private-orders` produced the same signing
     * string as an ungranted auth for the channel literally named
     * `private-orders:<grant>`, so a client could snip the grant off its own
     * auth string and use the remainder to subscribe, ungranted, to a channel
     * the application never authorized. It could not reach the SAME channel
     * that way (the collision necessarily changes the channel name), but the
     * invariant "the signature binds the channel" was false, and an ambiguous
     * signing string is a hole waiting for the next field to be added to it.
     *
     * The two forms also cannot be confused for each other: the granted one is
     * prefixed with a version tag that the untagged one, which begins with a
     * socket id, can never produce.
     */
    public static function signingString(string $socketId, string $channel, ?string $channelData, ?string $encodedGrant): string
    {
        // Refused rather than escaped, because the untagged form is Pusher's
        // and cannot be escaped without breaking every client. See
        // isValidSocketId() for what an unconstrained one buys an attacker.
        if (!static::isValidSocketId($socketId)) {
            throw new \InvalidArgumentException(
                'Lightspeed will not sign for a socket id that is not `{integer}.{integer}`.'
            );
        }

        if ($encodedGrant === null) {
            // Pusher's, exactly.
            $payload = "{$socketId}:{$channel}";

            if ($channelData !== null) {
                $payload .= ":{$channelData}";
            }

            return $payload;
        }

        return self::GRANT_SIGNING_PREFIX
            .self::field($socketId)
            .self::field($channel)
            .self::field($channelData)
            .self::field($encodedGrant);
    }

    /**
     * One length-prefixed field of the granted signing string.
     *
     * `:{byte length}:{value}` for a value, and `:-` for an absent one. The
     * length is what makes the concatenation injective: a reader knows exactly
     * where each field ends without looking for a delimiter, so no value can
     * spill into the next field by containing one. Absent is distinct from
     * empty, because "this is not a presence channel" and "the channel data is
     * an empty string" must not sign identically.
     */
    private static function field(?string $value): string
    {
        return $value === null ? ':-' : ':'.strlen($value).':'.$value;
    }
}
