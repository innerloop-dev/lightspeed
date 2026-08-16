<?php

use Lightspeed\Protocol\SubscriptionAuthorizer;

/**
 * This is the whole of channel security on the websocket surface, and every
 * one of these rejections is the only thing standing between a client and
 * someone else's private channel.
 *
 * The signature binds three things at once. The connection, the channel, and
 * (for presence) the identity the member claims. Each of those bindings can be
 * broken independently, and breaking any one of them has to fail the check, so
 * each is asserted separately here rather than trusting one happy path to
 * prove the HMAC is wired up at all.
 */

function subscriptionAuthorizer(string $secret = 'test-secret'): SubscriptionAuthorizer
{
    return new SubscriptionAuthorizer('test-key', $secret);
}

function signedSubscriptionAuth(
    string $socketId,
    string $channel,
    ?string $channelData = null,
    string $secret = 'test-secret',
): string {
    $payload = "{$socketId}:{$channel}";

    if ($channelData !== null) {
        $payload .= ":{$channelData}";
    }

    return 'test-key:'.hash_hmac('sha256', $payload, $secret);
}

test('a public channel needs no authorization at all', function () {
    $decision = subscriptionAuthorizer()->authorize('123.456789', 'updates', null, null);

    expect($decision->authorizationRequired)->toBeFalse()
        ->and($decision->granted)->toBeTrue()
        ->and($decision->denied())->toBeFalse()
        ->and($decision->presenceMember)->toBeNull();
});

test('it accepts a private subscription signed with the app secret', function () {
    $auth = signedSubscriptionAuth('123.456789', 'private-resource.1');

    $decision = subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', $auth, null);

    expect($decision->granted)->toBeTrue()
        ->and($decision->authorizationRequired)->toBeTrue()
        ->and($decision->presenceMember)->toBeNull();
});

test('an authorized private channel is distinguishable from a public one', function () {
    // Both outcomes grant the subscription and neither carries a presence
    // member, so nothing but authorizationRequired tells them apart. Which is
    // the whole reason the decision is an object and not a bare value.
    $auth = signedSubscriptionAuth('123.456789', 'private-resource.1');
    $authorizer = subscriptionAuthorizer();

    $public = $authorizer->authorize('123.456789', 'updates', null, null);
    $private = $authorizer->authorize('123.456789', 'private-resource.1', $auth, null);

    expect($public->granted)->toBe($private->granted)
        ->and($public->presenceMember)->toBe($private->presenceMember)
        ->and($public->authorizationRequired)->toBeFalse()
        ->and($private->authorizationRequired)->toBeTrue();
});

test('it rejects a signature made with the wrong secret', function () {
    $auth = signedSubscriptionAuth('123.456789', 'private-resource.1', null, 'not-the-secret');

    expect(subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', $auth, null)->denied())->toBeTrue();
});

test('it rejects an auth string issued for a different socket', function () {
    $auth = signedSubscriptionAuth('999.111111', 'private-resource.1');

    expect(subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', $auth, null)->denied())->toBeTrue();
});

test('it rejects an auth string issued for a different channel', function () {
    $auth = signedSubscriptionAuth('123.456789', 'private-resource.2');

    expect(subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', $auth, null)->denied())->toBeTrue();
});

test('it rejects a protected subscription from a connection with no socket id', function () {
    $auth = signedSubscriptionAuth('', 'private-resource.1');

    expect(subscriptionAuthorizer()->authorize(null, 'private-resource.1', $auth, null)->denied())->toBeTrue()
        ->and(subscriptionAuthorizer()->authorize('', 'private-resource.1', $auth, null)->denied())->toBeTrue();
});

test('it rejects a protected subscription with no usable auth string', function () {
    expect(subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', null, null)->denied())->toBeTrue()
        ->and(subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', '', null)->denied())->toBeTrue()
        ->and(subscriptionAuthorizer()->authorize('123.456789', 'private-resource.1', ['not-a-string'], null)->denied())->toBeTrue();
});

test('it verifies a presence subscription over the channel data as well', function () {
    $channelData = json_encode(['user_id' => '7', 'user_info' => ['name' => 'Ada']]);
    $auth = signedSubscriptionAuth('123.456789', 'presence-resource.1', $channelData);

    $decision = subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', $auth, $channelData);

    expect($decision->granted)->toBeTrue()
        ->and($decision->authorizationRequired)->toBeTrue()
        ->and($decision->presenceMember)->toBe(['user_id' => '7', 'user_info' => ['name' => 'Ada']]);
});

test('it rejects presence channel data that was tampered with after signing', function () {
    $signed = json_encode(['user_id' => '7', 'user_info' => ['name' => 'Ada']]);
    $tampered = json_encode(['user_id' => '8', 'user_info' => ['name' => 'Ada']]);
    $auth = signedSubscriptionAuth('123.456789', 'presence-resource.1', $signed);

    expect(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', $auth, $tampered)->denied())->toBeTrue();
});

test('it rejects a presence subscription whose signature omitted the channel data', function () {
    $channelData = json_encode(['user_id' => '7']);
    $auth = signedSubscriptionAuth('123.456789', 'presence-resource.1');

    expect(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', $auth, $channelData)->denied())->toBeTrue();
});

test('it rejects a presence member whose user_id is empty', function () {
    // Adversarial review, proven live before the fix: ['id' => ''] from a channel
    // callback produced a signed member the authorizer accepted, the
    // presence store silently wrote no row for, and the subscribe reported
    // success on anyway. The result was a ghost: in the fan-out, in nobody's
    // snapshot including its own, no member_added ever sent. An identity
    // that empty is malformed member data, refused like the shapes below.
    $channelData = json_encode(['user_id' => '', 'user_info' => ['name' => 'Ghost']]);
    $auth = signedSubscriptionAuth('123.456789', 'presence-resource.1', $channelData);

    expect(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', $auth, $channelData)->denied())->toBeTrue();

    // The positive control: one character is an identity, so the refusal
    // above is about emptiness, not about presence channels in general.
    $shortData = json_encode(['user_id' => '7']);
    $shortAuth = signedSubscriptionAuth('123.456789', 'presence-resource.1', $shortData);

    expect(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', $shortAuth, $shortData)->granted)->toBeTrue();
});

test('it rejects presence channel data that is missing or unusable', function () {
    expect(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', 'anything', null)->denied())->toBeTrue()
        ->and(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', 'anything', '{not json')->denied())->toBeTrue()
        ->and(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', 'anything', '{"user_info":{}}')->denied())->toBeTrue()
        ->and(subscriptionAuthorizer()->authorize('123.456789', 'presence-resource.1', 'anything', '{"user_id":7}')->denied())->toBeTrue();
});

test('it treats private and presence channels as protected and everything else as open', function () {
    expect(SubscriptionAuthorizer::isProtectedChannel('private-resource.1'))->toBeTrue()
        ->and(SubscriptionAuthorizer::isProtectedChannel('presence-resource.1'))->toBeTrue()
        ->and(SubscriptionAuthorizer::isProtectedChannel('resource.1'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The signing string is unambiguous (F4)
// ---------------------------------------------------------------------------

/**
 * The commit message claims a grant cannot be moved between channels. It could:
 * the signing string joined a variable number of fields with ':' and escaped
 * nothing, so a channel name was free to absorb the grant and produce a
 * byte-identical string.
 *
 *   granted    socketId + "private-orders"                + grant
 *   ungranted  socketId + "private-orders:<grant>"
 *
 * Both used to render as "123.456:private-orders:<grant>". A reviewer could not
 * turn that into same-channel access. The collision necessarily changes the
 * channel name. But an ambiguous signing string is a hole waiting for a
 * future field, and the stated invariant was simply false.
 *
 * The untagged forms are Pusher's and must stay byte for byte identical, or
 * every existing client's auth string stops verifying.
 */
test('the untagged signing strings are byte for byte the Pusher ones', function () {
    expect(SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, null))
        ->toBe('123.456:private-orders')
        ->and(SubscriptionAuthorizer::signingString('123.456', 'presence-orders', '{"user_id":"7"}', null))
        ->toBe('123.456:presence-orders:{"user_id":"7"}');
});

test('a channel name cannot absorb a grant and produce the same signing string', function () {
    $grant = 'eyJ0IjpbInByb2plY3Q6NyJdfQ';

    $granted = SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, $grant);
    $ungranted = SubscriptionAuthorizer::signingString('123.456', "private-orders:{$grant}", null, null);

    expect($granted)->not->toBe($ungranted);
});

test('a presence channel data field cannot absorb a grant either', function () {
    $grant = 'eyJ0IjpbInByb2plY3Q6NyJdfQ';

    $granted = SubscriptionAuthorizer::signingString('123.456', 'presence-orders', '{"user_id":"7"}', $grant);
    $ungranted = SubscriptionAuthorizer::signingString('123.456', 'presence-orders', '{"user_id":"7"}:'.$grant, null);

    expect($granted)->not->toBe($ungranted);
});

test('a granted auth string cannot be re-read as an ungranted one for a longer channel', function () {
    // A REAL grant, so that what is under test is the whole manoeuvre rather
    // than Grant::decode() happening to reject a made-up string.
    $now = (int) (microtime(true) * 1_000_000);
    $grant = (new \Lightspeed\Auth\Grant(['project:7'], ['can_edit' => true], $now, $now + 300_000_000))->encode();

    // What the application issued: a grant for `private-orders`, and nothing
    // else. The signature covers "123.456:private-orders:<grant>".
    $auth = 'test-key:'.hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, $grant),
        'test-secret',
    ).':'.$grant;

    expect(subscriptionAuthorizer()->authorize('123.456', 'private-orders', $auth, null)->granted)->toBeTrue();

    // What the client can make of it: snip the grant off, so the string has two
    // fields and is read as an ordinary Pusher auth, and subscribe to a channel
    // whose NAME is the old channel plus the grant. The signing string the
    // server then computes is byte-identical to the one above, so the signature
    // verifies for a channel the application never authorized.
    [$key, $signature] = explode(':', $auth);

    $decision = subscriptionAuthorizer()->authorize(
        '123.456',
        "private-orders:{$grant}",
        "{$key}:{$signature}",
        null,
    );

    expect($decision->granted)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Round four
// ---------------------------------------------------------------------------

/**
 * S6. `privateOrders`, with no dash, is NOT a protected channel here, and this
 * pins that divergence rather than leaving it to be discovered.
 *
 * Laravel's PusherBroadcaster guards on `str_starts_with($name, 'private')`, so
 * a channel called `privateOrders` runs the application's channel-auth callback
 * and can therefore look protected from the application's side. This server
 * follows the Pusher protocol instead, `private-` and `presence-`, with the
 * dash. So the same channel is subscribable by anyone with no auth string at
 * all. Both behaviours are defensible; what is not defensible is the gap being
 * undocumented, because a callback that runs is the exact thing that makes an
 * author believe a channel is protected.
 */
test('a channel named without the dash is open here, though Laravel guards it', function () {
    expect(SubscriptionAuthorizer::isProtectedChannel('privateOrders'))->toBeFalse()
        ->and(SubscriptionAuthorizer::isProtectedChannel('presenceOrders'))->toBeFalse()
        ->and(SubscriptionAuthorizer::isProtectedChannel('private-orders'))->toBeTrue();

    // And it really is open: no auth string, and the decision is "nothing to
    // authorize" rather than a refusal.
    $decision = subscriptionAuthorizer()->authorize('123.456', 'privateOrders', null, null);

    expect($decision->authorizationRequired)->toBeFalse()
        ->and($decision->granted)->toBeTrue();
});

/**
 * S7 mutant. `:-` for an absent field, not `:0:`.
 *
 * Absent and empty must not sign identically. Collapse them and "this is not a
 * presence channel" becomes indistinguishable from "the channel data is the
 * empty string", which is a field boundary that has moved without the signature
 * noticing. The exact class of ambiguity the length prefixes exist to remove.
 */
test('an absent field and an empty one do not sign to the same string', function () {
    $grant = 'eyJ0IjpbInByb2plY3Q6NyJdfQ';

    expect(SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, $grant))
        ->not->toBe(SubscriptionAuthorizer::signingString('123.456', 'private-orders', '', $grant));
});

/**
 * S7 mutant. The granted form announces itself.
 *
 * The literal is spelled out rather than read from the constant on purpose: a
 * test that asserts `str_starts_with($s, self::GRANT_SIGNING_PREFIX)` passes
 * when the constant is emptied, which is precisely the mutation this is for.
 */
test('the granted signing string begins with its version tag', function () {
    $granted = SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, 'GRANT');

    expect($granted)->toStartWith('lightspeed.grant.v1:');
});

/**
 * The untagged form is two client-influenced values joined with ':', and the
 * socket id half reaches it straight from the /broadcasting/auth request body.
 * So the question is not whether the two forms LOOK different, it is whether
 * any split of a granted string can be fed back through the untagged one, in
 * which case an application with a permissive channel pattern could be made to
 * sign an arbitrary grant.
 *
 * What stops it is the socket id being a shape rather than a free string.
 */
test('no split of a granted signing string can be reproduced through the untagged form', function () {
    $grant = 'eyJ0IjpbInByb2plY3Q6NyJdfQ';
    $granted = SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, $grant);

    $collisions = [];

    foreach (str_split($granted) as $offset => $character) {
        if ($character !== ':') {
            continue;
        }

        $socketId = substr($granted, 0, $offset);
        $channel = substr($granted, $offset + 1);

        try {
            $untagged = SubscriptionAuthorizer::signingString($socketId, $channel, null, null);
        } catch (\InvalidArgumentException) {
            // A socket id this server would never mint, refused before it can
            // be signed. That is the guard working.
            continue;
        }

        if ($untagged === $granted) {
            $collisions[] = $socketId;
        }
    }

    expect($collisions)->toBe([]);
});

test('a socket id that is not the Pusher shape is refused before it is signed', function () {
    expect(fn () => SubscriptionAuthorizer::signingString('lightspeed.grant.v1', 'private-orders', null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(SubscriptionAuthorizer::signingString('123.456', 'private-orders', null, null))
        ->toBe('123.456:private-orders');
});

/**
 * F5. `$` is not end-of-string in PCRE.
 *
 * isValidSocketId used `/^\d{1,20}\.\d{1,20}$/`, and PCRE's `$` matches before
 * a trailing newline. So `"123.456\n"` satisfied a check whose docblock says
 * the id is `{integer}.{integer}` and nothing else. `\A`/`\z` mean what the
 * docblock says.
 *
 * Scope, stated plainly rather than dramatised: pusher-php-server ^7.2, this
 * package's own dependency, already validates socket ids with `/\A\d+\.\d+\z/`
 * inside authorizeChannel(), which the inherited
 * PusherBroadcaster::validAuthenticationResponse() calls. So this check is a
 * weaker duplicate of an upstream guard and the round-four collision was not
 * reachable through this broadcaster. It stays as defence in depth, a package
 * should not rest a security property on a dependency's validation. And it is
 * being made to say what it claims, not being credited with closing a live hole.
 */
test('a socket id with a trailing newline is not the Pusher shape', function () {
    expect(SubscriptionAuthorizer::isValidSocketId("123.456\n"))->toBeFalse();
});

test('a socket id with a leading newline is not the Pusher shape', function () {
    expect(SubscriptionAuthorizer::isValidSocketId("\n123.456"))->toBeFalse();
});

test('the plain Pusher shape is still accepted', function () {
    // The positive half, so a regex that refused everything would not pass.
    expect(SubscriptionAuthorizer::isValidSocketId('123.456'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Round-six mutants that lived through the suite.
// ---------------------------------------------------------------------------

/**
 * M06. field() loses its length prefix, and the granted signing string stops
 * being injective.
 *
 * The whole reason the granted form length-prefixes every field is that a
 * concatenation joined by a bare delimiter is ambiguous: a value containing the
 * delimiter absorbs the boundary. Drop the length and two DIFFERENT
 * (channel, channel_data) pairs sign the same bytes. So a signature the
 * application issued for one channel is a valid signature for another.
 *
 * The docblock said this; nothing proved it.
 */
test('two different field splits cannot sign to the same granted string', function () {
    $a = SubscriptionAuthorizer::signingString('1.2', 'a', 'b:c', 'd');
    $b = SubscriptionAuthorizer::signingString('1.2', 'a:b', 'c', 'd');

    // Without the length prefixes both are `...:1.2:a:b:c:d`.
    expect($a)->not->toBe($b);
});

test('every field of a granted signing string carries its own byte length', function () {
    expect(SubscriptionAuthorizer::signingString('1.2', 'private-x', null, 'GRANT'))
        ->toBe('lightspeed.grant.v1:3:1.2:9:private-x:-:5:GRANT');
});

/**
 * M09. An empty third auth field is refused before the HMAC, not after it.
 */
test('an auth string with an empty third field is refused', function () {
    $socketId = '123.456789';
    $channel = 'private-resource.1';

    $auth = 'test-key:'.hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString($socketId, $channel, null, ''),
        'test-secret',
    ).':';

    expect(subscriptionAuthorizer()->authorize($socketId, $channel, $auth, null)->granted)->toBeFalse();
});

/**
 * M11. A presence member whose `user_id` is not a string is refused.
 *
 * The user id is the identity every downstream consumer keys off. The presence
 * set, `member_removed`, the colour slot, and `$event->userId` in the
 * application's own handler. JSON will happily hand back an int, an array or
 * null, and the signature does not care what type it covers: `channel_data` is
 * signed as bytes, so a client whose application legitimately signed
 * `{"user_id": 42}` is authorized with an int, and every one of those consumers
 * gets a type it was never written for.
 */
test('a presence member whose user_id is not a string is refused', function () {
    $socketId = '123.456789';
    $channel = 'presence-resource.1';
    $channelData = json_encode(['user_id' => 42, 'user_info' => []]);

    $auth = signedSubscriptionAuth($socketId, $channel, $channelData);

    expect(subscriptionAuthorizer()->authorize($socketId, $channel, $auth, $channelData)->granted)->toBeFalse();
});

test('a presence member with no user_id at all is refused', function () {
    $socketId = '123.456789';
    $channel = 'presence-resource.1';
    $channelData = json_encode(['user_info' => []]);

    $auth = signedSubscriptionAuth($socketId, $channel, $channelData);

    expect(subscriptionAuthorizer()->authorize($socketId, $channel, $auth, $channelData)->granted)->toBeFalse();
});

test('a presence member with a string user_id is still accepted', function () {
    // The positive half, so a check that refused every presence member would
    // not satisfy the two above.
    $socketId = '123.456789';
    $channel = 'presence-resource.1';
    $channelData = json_encode(['user_id' => '42', 'user_info' => []]);

    $auth = signedSubscriptionAuth($socketId, $channel, $channelData);

    expect(subscriptionAuthorizer()->authorize($socketId, $channel, $auth, $channelData)->granted)->toBeTrue();
});

/**
 * M13. Expiry is `>=`, so a grant is dead ON its expiry instant.
 *
 * `>` leaves the grant alive for the whole microsecond it expires in, which is
 * the same unorderable-tie argument RevocationLog::isRevoked() settles the same
 * way: when two instants cannot be ordered, the answer that costs a
 * re-subscribe is the right one, and the answer that leaks a message is not.
 */
test('a grant is expired at exactly its expiry instant', function () {
    $grant = new Lightspeed\Auth\Grant(['project:7'], [], 1_000, 2_000);

    expect($grant->hasExpired(2_000))->toBeTrue()
        ->and($grant->hasExpired(1_999))->toBeFalse();
});

/**
 * S7. `private-encrypted-` IS REFUSED, because this server cannot deliver what
 * the prefix promises.
 *
 * In the Pusher protocol that prefix does not mean "private, and a bit more
 * careful". It means end-to-end encryption: the server never sees plaintext,
 * and payloads are sealed with a shared secret that only the application and
 * the subscribing clients hold. Lightspeed implements none of that.
 *
 * Before this, the name simply started with `private-`, so it authorized, the
 * subscription succeeded, and every payload went out in clear text. `Echo
 * .encryptedPrivate('orders')` therefore worked, looked correct in the browser,
 * and delivered exactly the property the developer chose that API to avoid.
 *
 * Accepting is the dangerous option here and refusing is the safe one, which is
 * why the refusal is not conditional on configuration: a channel whose name
 * states a security property this server does not provide never subscribes,
 * so the failure is visible at the first attempt rather than in an incident
 * report. The application-facing half of the message (why, and use
 * `Echo.private()` if clear-text delivery is acceptable) is raised where a
 * developer will actually read it, in the broadcaster's auth endpoint; see
 * Broadcasting\LightspeedBroadcaster.
 */
test('an encrypted private channel is refused rather than served in clear text', function () {
    // A correctly signed one, so nothing about this refusal can be mistaken for
    // a signature failure.
    $auth = signedSubscriptionAuth('123.456789', 'private-encrypted-orders');

    $decision = subscriptionAuthorizer()->authorize('123.456789', 'private-encrypted-orders', $auth, null);

    expect($decision->denied())->toBeTrue('an encrypted channel subscribed and its payloads were never encrypted')
        ->and($decision->authorizationRequired)->toBeTrue()
        ->and($decision->granted)->toBeFalse();

    // The plain private channel beside it is untouched: this refuses one
    // prefix, not the feature it sits in front of.
    $plain = signedSubscriptionAuth('123.456789', 'private-orders');

    expect(subscriptionAuthorizer()->authorize('123.456789', 'private-orders', $plain, null)->granted)->toBeTrue();
});

test('the encrypted prefix is named as such rather than merely being protected', function () {
    expect(SubscriptionAuthorizer::isEncryptedChannel('private-encrypted-orders'))->toBeTrue()
        ->and(SubscriptionAuthorizer::isEncryptedChannel('private-orders'))->toBeFalse()
        ->and(SubscriptionAuthorizer::isEncryptedChannel('presence-orders'))->toBeFalse()
        // Not a Pusher encrypted channel: the prefix is `private-encrypted-`,
        // and a public channel that merely contains the word is unaffected.
        ->and(SubscriptionAuthorizer::isEncryptedChannel('encrypted-orders'))->toBeFalse();

    // It stays a protected channel too. The refusal is about what this server
    // can deliver, and nothing here loosens the gate that made it protected.
    expect(SubscriptionAuthorizer::isProtectedChannel('private-encrypted-orders'))->toBeTrue();
});

/**
 * S8. THE REFUSAL HAS TO REACH A HUMAN, and the websocket frame cannot carry it.
 *
 * A refused subscription is answered on the wire with a generic
 * `subscription_error` (Server), which is right for a security gate: a client
 * that guesses wrong learns nothing from it. But the developer who wrote
 * `Echo.encryptedPrivate('orders')` did not guess wrong about a signature, and
 * a generic auth failure sends them looking at their channel callback and their
 * app secret for a problem that is in neither.
 *
 * /broadcasting/auth is where that developer's browser goes FIRST, before any
 * subscribe frame is sent, and it is an HTTP response with room for a sentence.
 * So the explanation lives there: the same refusal, stated once, where it can
 * say why and what to use instead.
 */
function authoriseChannelName(string $channel): mixed
{
    $broadcaster = new Lightspeed\Broadcasting\LightspeedBroadcaster(
        app(Lightspeed\Broadcasting\BroadcastBridge::class),
        app(Lightspeed\Auth\PendingGrants::class),
        app(Lightspeed\Auth\RevocationLog::class),
        new \Pusher\Pusher(
            config('lightspeed.reverb_compat.app_key'),
            config('lightspeed.reverb_compat.app_secret'),
            config('lightspeed.reverb_compat.app_id'),
        ),
    );

    return $broadcaster->auth(Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [
        'channel_name' => $channel,
        'socket_id' => '123.456',
    ]));
}

test('the channel-auth endpoint explains why an encrypted channel is refused', function () {
    expect(fn () => authoriseChannelName('private-encrypted-orders'))
        ->toThrow(
            Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
            'Lightspeed does not implement end-to-end encrypted channels',
        );
});

/**
 * AN UNDECODABLE GRANT IS REFUSED, and nothing was watching that it is.
 *
 * Replacing
 *
 *     if ($grant === null) {
 *         return SubscriptionDecision::refused();
 *
 * with `granted($presenceMember)`, so that a grant which cannot be read is
 * treated as an untagged subscription, left the whole suite green.
 *
 * WHAT THAT MUTANT COSTS, precisely. The grant is not decoration on top of the
 * signature; it IS the per-message credential. A subscription that arrives with
 * a grant and is admitted without one is a connection that:
 *
 *   - carries no tags, so `Lightspeed::revoke()` can never reach it. Revocation
 *     works by dropping connections whose grant carries the revoked tag, and a
 *     connection with no grant carries nothing.
 *   - has no expiry, so Auth\GrantSweeper never judges it. A grant is the only
 *     thing on a connection that expires.
 *   - is not re-checked per message, because Auth\GrantCheck has nothing to ask
 *     Redis about.
 *
 * So the mutant does not merely fail to notice a malformed grant. It turns the
 * one channel the application asked to be re-checked into the one channel that
 * never is, and it does it silently, for a connection whose signature verified.
 *
 * Reaching this branch requires a VALID signature over an INVALID grant, which
 * is why Grant::decode()'s own unit coverage does not cover it: the signature
 * is checked first, so every test that fed a garbage grant to authorize() was
 * refused a step earlier, on the HMAC, and proved nothing about this line.
 * These sign the garbage properly, which is what the application's own auth
 * endpoint would do if it ever emitted a grant this server could not read.
 */

/** An auth string whose third field is exactly the given bytes, correctly signed. */
function authWithRawGrant(string $encodedGrant, string $channel = 'private-orders', ?string $channelData = null): string
{
    return 'test-key:'.hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString('123.456', $channel, $channelData, $encodedGrant),
        'test-secret',
    ).':'.$encodedGrant;
}

test('a correctly signed grant that cannot be decoded is refused, not downgraded', function () {
    $cases = [
        'not base64url at all' => 'this is not base64!!',
        'base64 of something that is not JSON' => rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '='),
        'base64 of JSON that is not an object' => rtrim(strtr(base64_encode('"a string"'), '+/', '-_'), '='),
        'no tags member' => rtrim(strtr(base64_encode('{"p":{},"i":1,"e":2}'), '+/', '-_'), '='),
        'tags that are not a list' => rtrim(strtr(base64_encode('{"t":"project:7","p":{},"i":1,"e":2}'), '+/', '-_'), '='),
        'an empty tag list, which nothing could ever revoke' => rtrim(strtr(base64_encode('{"t":[],"p":{},"i":1,"e":2}'), '+/', '-_'), '='),
        'an empty tag inside the list' => rtrim(strtr(base64_encode('{"t":[""],"p":{},"i":1,"e":2}'), '+/', '-_'), '='),
        'a tag that is neither string nor int' => rtrim(strtr(base64_encode('{"t":[{"a":1}],"p":{},"i":1,"e":2}'), '+/', '-_'), '='),
        'issued-at that is not a number' => rtrim(strtr(base64_encode('{"t":["x"],"p":{},"i":"soon","e":2}'), '+/', '-_'), '='),
        'expires-at that is not a number' => rtrim(strtr(base64_encode('{"t":["x"],"p":{},"i":1,"e":"later"}'), '+/', '-_'), '='),
    ];

    foreach ($cases as $why => $encodedGrant) {
        $decision = subscriptionAuthorizer()->authorize('123.456', 'private-orders', authWithRawGrant($encodedGrant), null);

        expect($decision->granted)->toBeFalse("a grant with {$why} should be refused")
            ->and($decision->grant)->toBeNull("a grant with {$why} should carry no grant");
    }
});

test('an undecodable grant on a presence channel is refused too', function () {
    // The presence path returns the member alongside the grant, so a mutant
    // that reached for `granted($presenceMember)` produces a subscription that
    // looks entirely normal: the member joins, the snapshot is right, and only
    // the re-checking is gone.
    $channelData = '{"user_id":"7"}';
    $encodedGrant = rtrim(strtr(base64_encode('{"t":[],"p":{},"i":1,"e":2}'), '+/', '-_'), '=');

    $decision = subscriptionAuthorizer()->authorize(
        '123.456',
        'presence-orders',
        authWithRawGrant($encodedGrant, 'presence-orders', $channelData),
        $channelData,
    );

    expect($decision->granted)->toBeFalse()
        ->and($decision->grant)->toBeNull();
});

test('a correctly signed grant that CAN be decoded is granted, and carries its tags', function () {
    // The positive control, and it is what makes the refusals above a
    // judgement rather than a constant. Without it, an authorize() that refused
    // every three-field auth string would satisfy all of the above while
    // breaking per-message authorization outright.
    $now = (int) (microtime(true) * 1_000_000);
    $encodedGrant = (new \Lightspeed\Auth\Grant(['project:7'], ['can_edit' => true], $now, $now + 300_000_000))->encode();

    $decision = subscriptionAuthorizer()->authorize('123.456', 'private-orders', authWithRawGrant($encodedGrant), null);

    expect($decision->granted)->toBeTrue()
        ->and($decision->grant)->not->toBeNull()
        ->and($decision->grant->tags)->toBe(['project:7'])
        ->and($decision->grant->payload)->toBe(['can_edit' => true]);
});

test('a grant is refused for being expired, which is a different refusal from being unreadable', function () {
    // The two refusals sit one after the other and both return the same
    // decision, so a test that only ever saw one of them would not notice the
    // other being removed.
    $now = (int) (microtime(true) * 1_000_000);
    $encodedGrant = (new \Lightspeed\Auth\Grant(['project:7'], [], $now - 600_000_000, $now - 300_000_000))->encode();

    $decision = subscriptionAuthorizer()->authorize('123.456', 'private-orders', authWithRawGrant($encodedGrant), null, $now);

    expect($decision->granted)->toBeFalse();
});
