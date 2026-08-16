<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use Lightspeed\ClientEvents\ClientEvent;

/**
 * `ClientEvent::user()` is opt-in, and the opt-in is the whole point: the
 * README's "no database on the message path" claim survives a convenience
 * method only while an event nobody asks about costs nothing.
 *
 * So the provider here counts. Every test below asserts a number of lookups,
 * not just an answer, because "returns the right user" and "returns the right
 * user once" are different claims and only the second one is load-bearing.
 */
class CeUserCountingProvider implements UserProvider
{
    public int $lookups = 0;

    /**
     * @param  array<string, Authenticatable>  $users
     */
    public function __construct(private readonly array $users = [])
    {
    }

    public function retrieveById($identifier)
    {
        $this->lookups++;

        return $this->users[(string) $identifier] ?? null;
    }

    public function retrieveByToken($identifier, $token)
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token)
    {
    }

    public function retrieveByCredentials(array $credentials)
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false)
    {
    }
}

/**
 * Make the counting provider the one the application's default guard uses.
 *
 * This goes in through `Auth::provider()` and the auth config rather than by
 * binding an object the package could look up by class, because the claim is
 * that `user()` resolves through whatever provider the application configured.
 * A test that handed the package its provider directly would pass even if the
 * package ignored the application's configuration entirely.
 *
 * @param  array<string, Authenticatable>  $users
 */
function ceUserConfiguredProvider(array $users = []): CeUserCountingProvider
{
    $provider = new CeUserCountingProvider($users);

    Auth::provider('ceuser-counting', fn () => $provider);

    $guard = config('auth.defaults.guard');
    config()->set('auth.providers.'.config("auth.guards.{$guard}.provider").'.driver', 'ceuser-counting');

    return $provider;
}

function ceUserEvent(?string $userId): ClientEvent
{
    return new ClientEvent(
        fd: 1,
        channel: $userId === null ? 'private-room' : 'presence-lobby',
        event: 'client-typing',
        data: [],
        socketId: '1.1',
        userId: $userId,
    );
}

test('a presence event hydrates its user through the configured provider', function () {
    $alice = new GenericUser(['id' => '7', 'name' => 'Alice']);
    $provider = ceUserConfiguredProvider(['7' => $alice]);

    $user = ceUserEvent('7')->user();

    expect($user)->toBe($alice)
        ->and($user)->toBeInstanceOf(Authenticatable::class)
        ->and($provider->lookups)->toBe(1);
});

test('an event with no identity answers null without asking the provider', function () {
    // Private channels carry no user payload in the Pusher protocol, so this
    // is the ordinary case rather than an edge one. Asking the provider for
    // null would put a lookup on the message path of every private-channel
    // handler that calls user() defensively.
    $provider = ceUserConfiguredProvider(['7' => new GenericUser(['id' => '7'])]);

    expect(ceUserEvent(null)->user())->toBeNull()
        ->and($provider->lookups)->toBe(0);
});

test('an identity the provider does not know answers null', function () {
    // A deleted user, or an id from a connection that outlived the row. The
    // handler gets null, which means "no", and never "no restrictions".
    $provider = ceUserConfiguredProvider(['7' => new GenericUser(['id' => '7'])]);

    expect(ceUserEvent('404')->user())->toBeNull()
        ->and($provider->lookups)->toBe(1);
});

test('repeated calls cost one lookup, including when the answer is null', function () {
    // Memoization has to cache the miss too. Caching only the hit means a
    // handler that asks twice about a deleted user pays twice, and a handler
    // in a loop pays per iteration, which is exactly the shape of accident
    // this method exists to prevent.
    $found = ceUserConfiguredProvider(['7' => new GenericUser(['id' => '7'])]);
    $event = ceUserEvent('7');

    expect($event->user())->toBe($event->user())
        ->and($event->user())->not->toBeNull()
        ->and($found->lookups)->toBe(1);

    $missing = ceUserConfiguredProvider([]);
    $miss = ceUserEvent('404');

    expect($miss->user())->toBeNull()
        ->and($miss->user())->toBeNull()
        ->and($miss->user())->toBeNull()
        ->and($missing->lookups)->toBe(1);
});

test('an application with no user provider configured answers null rather than dying', function () {
    // A guard can name no provider at all, and `createUserProvider()` answers
    // null for that rather than throwing. Reached without the null check, the
    // first handler that calls user() dies inside the DTO on the frame, and
    // the sender is told only that its event could not be handled. Null is the
    // honest answer here for the same reason it is above: there is nobody to
    // hydrate, and a handler reading null refuses.
    $guard = config('auth.defaults.guard');
    config()->set("auth.guards.{$guard}.provider", null);
    config()->set('auth.defaults.provider', null);

    expect(ceUserEvent('7')->user())->toBeNull();
});

test('an event nobody asks about costs no lookups', function () {
    // The opt-in. Constructing the event, reading its fields, and handing it
    // to a handler that never calls user() must not touch the provider at all.
    $provider = ceUserConfiguredProvider(['7' => new GenericUser(['id' => '7'])]);

    $event = ceUserEvent('7');

    expect($event->userId)->toBe('7')
        ->and($event->channel)->toBe('presence-lobby')
        ->and($provider->lookups)->toBe(0);
});
