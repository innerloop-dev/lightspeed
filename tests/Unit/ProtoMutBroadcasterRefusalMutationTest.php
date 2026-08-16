<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Request;
use Lightspeed\Auth\GrantManager;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Broadcasting\LightspeedBroadcaster;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The four things this broadcaster refuses, and what it says while refusing.
 *
 * Every one of them is answered with a sentence rather than a status, and the
 * sentence is the whole remedy: these all happen inside the request a browser
 * makes to `/broadcasting/auth`, where the developer sees an exception message
 * and nothing else. The websocket side of the same refusal is deliberately
 * uninformative, which is correct for a security gate and useless to whoever
 * has to fix it. So the wording is asserted whole here, not sampled: a message
 * that has lost the half naming the env var, or the half naming the
 * alternative, has lost the reason it exists.
 *
 * The other property under test is order. A grant described by an authorization
 * that was refused must not be able to ride out on the next one, which is why
 * the pending slot is cleared at the START of an attempt rather than at its end.
 */
class ProtoMutUnsignedPusher extends \Pusher\Pusher
{
    public array $stubSettings = [];

    public static function make(array $settings): self
    {
        $pusher = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $pusher->stubSettings = $settings;

        return $pusher;
    }

    public function getSettings(): array
    {
        return $this->stubSettings;
    }
}

/**
 * A Pusher client with its own validation removed.
 *
 * pusher-php-server validates socket ids and channel names inside
 * authorizeChannel(), so the checks this package makes afterwards are defence
 * in depth and cannot be reached through the real client. This is the point:
 * the package must not rest a security property on a dependency's validation
 * continuing to exist, and a test that cannot get past that validation proves
 * nothing about what happens if it goes.
 */
class ProtoMutLaxPusher extends \Pusher\Pusher
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function getSettings(): array
    {
        return ['auth_key' => 'proto-mut-key', 'secret' => 'proto-mut-secret'];
    }

    public function authorizeChannel(string $channel, string $socket_id, ?string $custom_data = null): string
    {
        return json_encode(['auth' => 'proto-mut-key:signature']);
    }

    public function authorizePresenceChannel(string $channel, string $socket_id, string $user_id, $user_info = null): string
    {
        return json_encode(['auth' => 'proto-mut-key:signature', 'channel_data' => '{"user_id":"ada"}']);
    }
}

function protoMutBroadcaster(?\Pusher\Pusher $pusher = null): LightspeedBroadcaster
{
    return new LightspeedBroadcaster(
        app(BroadcastBridge::class),
        app(PendingGrants::class),
        app(RevocationLog::class),
        $pusher ?? new \Pusher\Pusher('proto-mut-key', 'proto-mut-secret', 'proto-mut-id'),
    );
}

function protoMutAuthRequest(string $socketId, string $channel): Request
{
    $request = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => $socketId,
        'channel_name' => $channel,
    ]);

    $request->setUserResolver(static fn () => new GenericUser(['id' => 'ada']));

    return $request;
}

test('an authorization that never finished cannot leave its grant for the next one', function () {
    $broadcaster = protoMutBroadcaster();
    // Registered without the prefix, the way an application registers one:
    // Laravel matches the pattern against the normalized channel name.
    $broadcaster->channel('proto-mut', static fn () => true);

    // A previous attempt on this request that described a grant and then went
    // no further: a channel callback that called tag() and then returned false,
    // or threw. The grant it described belongs to nothing.
    app(PendingGrants::class)->begin();
    app(GrantManager::class)->tag(['proto-mut-leftover'])->with([]);

    $request = protoMutAuthRequest('123.456789', 'private-proto-mut');

    // The response auth() itself returns, and not one from a later call:
    // Laravel builds it inside verifyUserCanAccessChannel, so this attempt is
    // the one that would pick up anything left lying around.
    $response = $broadcaster->auth($request);

    // `key:signature` is the untagged Pusher form; a third field would be the
    // leftover grant, signed and handed to a client that was authorized for
    // nothing of the sort.
    expect(substr_count($response['auth'], ':'))->toBe(1);
});

test('an encrypted channel is refused with the sentence that says what to use instead', function () {
    $broadcaster = protoMutBroadcaster();

    expect(static fn () => $broadcaster->auth(protoMutAuthRequest('123.456789', 'private-encrypted-orders')))
        ->toThrow(
            AccessDeniedHttpException::class,
            'Lightspeed does not implement end-to-end encrypted channels, so it refuses '
            .'`private-encrypted-` channels rather than relaying their payloads in clear '
            .'text. Use a `private-` channel (Echo.private) if payloads this server can '
            .'see are acceptable for this data.'
        );
});

test('a client with no credentials refuses to sign, and names both env vars to set', function () {
    // The settings array without the keys at all, which is the shape this guard
    // reads defensively rather than the one a configured client produces.
    $broadcaster = protoMutBroadcaster(ProtoMutUnsignedPusher::make([]));

    expect(static fn () => $broadcaster->auth(protoMutAuthRequest('123.456789', 'private-proto-mut')))
        ->toThrow(
            RuntimeException::class,
            'Lightspeed cannot authorize a channel: '
            .'app_key (LIGHTSPEED_APP_KEY, or REVERB_APP_KEY) and '
            .'app_secret (LIGHTSPEED_APP_SECRET, or REVERB_APP_SECRET) is not configured. '
            .'Set it in [lightspeed.reverb_compat]. Without it every private and presence '
            .'channel signature can be computed by anyone, so Lightspeed refuses to issue one.'
        );
});

test('a credential present but null is read as missing rather than signed with', function () {
    // What an unconfigured install produces: the config resolves both through
    // env(), and an unset variable is null. Null is not a credential, and a
    // signature computed with one is computable by anyone.
    //
    // One at a time, because each has its own guard and a pair of them can hide
    // a broken half: with both missing, a guard that has stopped noticing null
    // still throws, on the strength of the other one.
    $noKey = protoMutBroadcaster(ProtoMutUnsignedPusher::make([
        'auth_key' => null,
        'secret' => 'proto-mut-secret',
    ]));

    $noSecret = protoMutBroadcaster(ProtoMutUnsignedPusher::make([
        'auth_key' => 'proto-mut-key',
        'secret' => null,
    ]));

    $request = protoMutAuthRequest('123.456789', 'private-proto-mut');

    expect(static fn () => $noKey->validAuthenticationResponse($request, true))
        ->toThrow(RuntimeException::class, 'app_key (LIGHTSPEED_APP_KEY, or REVERB_APP_KEY) is not configured.');

    expect(static fn () => $noSecret->validAuthenticationResponse($request, true))
        ->toThrow(RuntimeException::class, 'app_secret (LIGHTSPEED_APP_SECRET, or REVERB_APP_SECRET) is not configured.');
});

test('a grant is never attached to a request that did not name a socket', function () {
    $broadcaster = protoMutBroadcaster(ProtoMutLaxPusher::make());

    app(PendingGrants::class)->begin();
    app(GrantManager::class)->tag(['proto-mut'])->with([]);

    // A grant binds permissions to ONE connection. With no socket id there is
    // no connection to bind it to, and a signature computed over an absent one
    // is a credential anybody's socket can present.
    expect(static fn () => $broadcaster->validAuthenticationResponse(
        protoMutAuthRequest('', 'private-proto-mut'),
        true,
    ))->toThrow(
        BroadcastException::class,
        'Lightspeed cannot attach a grant without a socket_id and channel_name on the request.'
    );
});

test('a grant is never attached to a request that did not name a channel', function () {
    $broadcaster = protoMutBroadcaster(ProtoMutLaxPusher::make());

    app(PendingGrants::class)->begin();
    app(GrantManager::class)->tag(['proto-mut'])->with([]);

    // The other half of the same binding: a grant names what it permits, and a
    // grant for no channel would be checked against whichever channel the
    // client later claimed.
    expect(static fn () => $broadcaster->validAuthenticationResponse(
        protoMutAuthRequest('123.456789', ''),
        true,
    ))->toThrow(
        BroadcastException::class,
        'Lightspeed cannot attach a grant without a socket_id and channel_name on the request.'
    );
});
