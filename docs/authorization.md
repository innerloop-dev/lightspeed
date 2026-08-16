# Authorization

Checking permissions usually means a database query. Fine once per page load. Too slow on every keystroke.

So Lightspeed checks once against your database. When someone subscribes, your normal channel authorization runs, and you tell Lightspeed what they're allowed to do. It remembers that on the connection.

Every message they send after that is still re-checked. But against a single Redis read, not your database.

When someone's access changes, you tell Lightspeed to forget it:

```php
Lightspeed::revoke('user:42');
```

Their next message is refused, and they are dropped out of the channel so they stop receiving. Their browser re-subscribes, and your authorization runs again.

A tag is any string you choose. If you tagged connections with the project they're working in, you can revoke the whole project at once:

```php
Lightspeed::revoke('project:7');
```

Everyone working in project 7 re-checks. Same call, same mechanism.

## Tagging a connection

In `routes/channels.php`, where you are already hitting the database to answer the same question:

```php
use Lightspeed\Facades\Lightspeed;

Broadcast::channel('doc.{id}', function (User $user, string $id) {
    $doc = Document::findOrFail($id);

    if (! $user->can('view', $doc)) {
        return false;
    }

    Lightspeed::tag(["user:{$user->id}", "project:{$doc->project_id}"])
              ->with(['can_edit' => $user->can('update', $doc)]);

    return true;
});
```

`tag()` lists the strings this connection can be revoked by. `with()` attaches an opaque payload, its shape is entirely yours, and Lightspeed never reads a key of it.

Nothing is committed until your callback returns successfully. A callback that calls `tag()` and then returns `false`, or throws, attaches nothing.

## Reading the payload in a handler

```php
public function handle(ClientEvent $event): ?ClientEventResult
{
    if (! ($event->auth['can_edit'] ?? false)) {
        return ClientEventResult::error('forbidden', 'Read-only.');
    }

    // ...
}
```

`$event->auth` is `null` when the channel callback never called `tag()`. Treat that as "no", never as "no restrictions", it means nothing was re-checked before your handler ran.

## Who is this user?

A message cannot arrive on a channel this socket did not subscribe to, and
subscribing ran your own `Broadcast::channel` rule. So the identity on the
event is one your app already approved, and reading it costs nothing:

```php
$event->userId;    // presence channels: the user_id your auth returned
$event->userInfo;  // presence channels: the payload your auth returned
$event->auth;      // whatever you attached with tag(...)->with(), signed
```

On private channels the Pusher protocol carries no user payload, so
`userId` is null there. The identity still exists in whichever of these
fits your app: the channel name (`private-inbox.42` was authorized for
user 42 by your own rule), the signed payload, or your session resolved
from `$event->connectionContext`.

Authorize on these and every message stays on the hot path. When you want
the actual model, see [moving off the hot path](#moving-off-the-hot-path-fully-hydrated-models).

## Revoking

```php
Lightspeed::revoke('project:7');          // one tag
Lightspeed::revoke(['user:42', 'user:9']); // several
```

Two things happen:

1. The revocation is written to Redis. From that moment every worker, everywhere, refuses the next message from every connection carrying the tag, because each of them reads Redis per message, and that read is authoritative.
2. Every worker is told over the existing relay to unsubscribe those connections, so they leave the fan-out and stop receiving.

The first is the enforcement and the second is the speed. If a relay entry is lost or slow, the connection is still refused on every message it sends, and still dropped when its grant expires. The relay makes that immediate rather than eventual.

Revoking is not a logout and not a disconnect. The socket stays open, and the next subscribe mints a fresh grant: if your channel callback still says yes, the user is straight back in, possibly with different permissions. Lightspeed cannot know *why* you revoked: fired, demoted, sharing changed, or just a stale payload. So it says "I no longer trust what I remembered" and lets your callback decide.

## What the client sees

A refused message comes back as a `lightspeed:stale` frame on the channel. Recovering means **unsubscribing and subscribing again**, through whatever function your app already uses to join the channel:

```js
function join(name) {
    const channel = pusher.subscribe(name);

    channel.bind('lightspeed:stale', (data) => {
        // data.reason      'revoked' | 'expired' | 'unavailable'
        // data.retry_in_ms a jittered hint, already spread across clients
        setTimeout(() => {
            pusher.unsubscribe(name);
            join(name);
        }, data.retry_in_ms);
    });

    channel.bind('message', (data) => { /* your handlers */ });

    return channel;
}
```

Two things about that are not optional, and both were found by driving it with real pusher-js.

**`channel.subscribe()` on its own does nothing.** pusher-js still believes it is subscribed, so the call returns immediately without authorizing: no request to `/broadcasting/auth`, no new grant, and a channel that stays refused forever. Only `unsubscribe` clears that flag.

**Unsubscribing throws the channel object away**, along with every handler bound to it. That is why the snippet above re-runs the whole `join`, and why re-binding is not a detail you can leave out, a version that only re-subscribes comes back to a live channel whose handlers no longer fire.

With Echo, the same shape is `Echo.leave(name)` followed by your usual `Echo.private(name).listen(...)`.

`retry_in_ms` matters too. Revoking a busy project refuses every connection carrying the tag at once, and a client that re-subscribed immediately would send that whole population through `/broadcasting/auth`, and therefore your database, in the same instant.

### If a client ignores the refusal

A client that keeps sending on a channel it has been dropped from gets a **connection-level** `pusher:error` with `code: 'not-subscribed'`, not a channel event. So it arrives at the connection's error handler rather than at anything bound with `channel.bind`:

```js
pusher.connection.bind('error', (err) => {
    // err.type === 'PusherError'
    // err.data.code === 'not-subscribed'
});
```

## Why this is safe

The grant is not stored anywhere. It is encoded and signed into the `auth` string your channel-auth endpoint already returns:

```
key:signature          no grant  (the default, unchanged)
key:signature:grant    tagged
```

pusher-js treats `auth` as an opaque string and passes it back unchanged, so nothing about the protocol or your client changes. Strip the grant, edit a tag, or paste in someone else's, and the signature stops matching.

So a valid auth string always carries its grant. There is no state where the credential is good and the grant is missing, which means an absent grant can only mean one thing: the application did not tag that channel.

The grant carries its own expiry, so it stops working on its own.

## Cost

One Redis read per message, whatever the tag count, about **0.03ms** measured against loopback Redis, and flat from one tag to eight because it is a single `MGET`. Never your database.

It is never cached. A cache, a per-worker mirror or a shared-memory table would each save that 0.03ms, and each would add a way to keep serving a revoked connection after something unrelated broke. Buying 0.03ms with that is a bad trade. If Redis cannot answer, the message is **refused**.

Over a network Redis, expect the read to cost roughly your Redis round-trip time instead.

## What "auth runs on every message" means

Every message from a tagged connection is checked. The signed grant is
verified, and Redis is asked whether any of its tags has been revoked since
it was issued. If it has, the message is refused. For a tagged connection
that check is never cached and never skipped; a connection you never tagged
has no grant to check and pays nothing.

What does not happen is your `Broadcast::channel()` closure running again. Your
rules ran once, when the connection subscribed, and their answer travels with
the connection until you revoke it or it expires.

This is the same shape as any token-based system. A JWT is verified and checked
against a revocation list on every request; the login does not re-run. The
practical consequence is the same too: if someone's permissions change and you
do not call `revoke()`, the grant stays good until it expires. That is what the
grant lifetime is for, and it is why you should set it deliberately.

## Your own auth endpoint: guests without accounts

Lightspeed does not require Laravel's login. It requires a signature its
broadcaster produced, and who produces that signature is yours to decide.
The standard door is `routes/channels.php` behind Laravel's guards. The
second door is your own endpoint: point the client's `authEndpoint` at a
route you control, authenticate however your application grants access (a
share token, a signed link, a kiosk key), and sign through the broadcaster
yourself. This is how Lightwave admits share-link guests in production:
visitors with no account, fully authenticated, fully revocable.

```js
// The client just asks a different endpoint; nothing else changes.
authEndpoint: '/guest/broadcasting/auth',
```

```php
Route::post('/guest/broadcasting/auth', function (Request $request) {
    // Your rules, your refusals. Whatever your app means by guest access:
    // a share token in the session, a signed URL, an API key. 403 anything
    // that does not hold one.
    $access = /* resolve your guest access, however you granted it */;
    abort_unless($access !== null, 403);

    $broadcaster = Broadcast::connection('lightspeed');

    // The standard entrypoint would have called begin() for you; signing
    // directly means starting the grant yourself, then tagging exactly as a
    // channel callback would. One tag makes every guest on this document
    // revocable in one call.
    app(\Lightspeed\Auth\PendingGrants::class)->begin();
    Lightspeed::tag(["document-guest:{$access->documentId}"]);

    // Presence signing reads the member id off the request's user; a guest
    // has none, so install a stub that answers with your guest id (a small
    // class whose getAuthIdentifierForBroadcasting() returns it).
    $request->setUserResolver(fn () => new GuestIdentity($access->guestId));

    return response()->json($broadcaster->validAuthenticationResponse($request, [
        'tabId' => $access->tabId,
        'name' => $access->guestName,
        'isGuest' => true,
    ]));
});
```

For a private channel, pass `true` instead of the member array and skip the
resolver stub. Everything downstream is identical to the standard door: the
auth string carries the signed grant, the per-message check runs, and
`Lightspeed::revoke('document-guest:42')` cuts every guest of that document
off mid-connection. Re-check whatever your access means on every auth call,
the way a channel callback would: this endpoint IS your channel callback,
worn openly.

## Worth knowing

- **Forget a tag and revoking it will not touch that connection.** Lightspeed cannot guess what a connection should have been tagged with.
- **Redis is on the message path for tagged channels, and on their subscribe.** A Redis outage means tagged connections cannot send, and cannot subscribe either: a subscription that carries a grant costs one extra `MGET`, which is what keeps a replayed auth string out of the fan-out while revocations are unreadable. Untagged connections have no Redis call *added* on either path, which is not the same as none: a subscribe already claims a resource owner, and joining a presence channel already writes the membership. This feature neither added those nor changed them.
- **Receiving stops on a relay notification.** Lose it and a revoked user keeps *receiving* until the grant expires, at which point each worker's own sweeper drops them, having been told nothing by anybody. They cannot send in the meantime. That is what makes a lost relay entry a bounded delay rather than a hole.
- **`grant_lifetime_seconds` is a backstop, not the guarantee.** It bounds the blast radius of a Redis outage and how long a lost relay entry can matter. Shorter is safer and means more re-authorization traffic. Default 300s. `sweep_interval_ms` (default 1000) is how often each worker looks, so it is the granularity of that bound.
- **Clocks.** Grant issue times and revocation times both come from Redis's own clock, so the "was this revoked after it was issued" comparison never depends on your servers agreeing. Grant *expiry* is judged against the local clock, so a badly skewed server runs its grants short or long by the skew.
- **Opt-in, and opting out is doing nothing.** An application that never calls `tag()` signs the same auth string it always did, and has no Redis call added on subscribe or per message, it does exactly what it did before this feature existed.
- **JSONP channel auth is not supported with `tag()`.** The legacy `callback` transport returns a response this cannot re-sign, so it raises rather than quietly returning an ungranted string.
- **The dash in `private-` and `presence-` is load-bearing.** A channel named `privateOrders` still runs your `Broadcast::channel()` callback, because Laravel matches registered patterns by name and does not test the prefix. So it looks protected from your side. Lightspeed follows the Pusher protocol, where the prefix is what marks a channel protected, and treats that name as public: a client that never calls the auth endpoint can subscribe to it with no auth string.

  Name channels `private-orders`. That is what `Echo.private('orders')` generates.
- **`Echo.encryptedPrivate()` is refused.** `private-encrypted-` is not a stricter private channel. In the Pusher protocol it means the payload is encrypted end to end with a secret the server never holds, and Lightspeed implements no part of that. It used to accept those channels anyway, on the strength of the name starting with `private-`: the subscription succeeded, the app looked correct in the browser, and every payload went out in clear text, which is worse than not supporting the feature at all. Now the `/broadcasting/auth` request is refused with a 403 that says why, and the subscribe frame is refused with it.

  Use `Echo.private('orders')` when payloads this server can see are acceptable for the data, and encrypt in your own code before publishing when they are not.
- **A `socket_id` must be `{integer}.{integer}`.** That is what this server mints and what every Pusher client sends. A channel-auth request carrying anything else is refused with a 403, because the untagged signing string joins the socket id and the channel name with a colon and escapes neither, so an unconstrained socket id lets a caller choose where that join lands.
- **A revocation has to outlive the grants it kills, and one dial is a fleet-wide declaration.** Normally this is automatic: every mint records its lifetime in a Redis high-water mark, and a revoke keeps its record for twice the longest lifetime anything recently minted under. If that mark is flushed or evicted, the retention falls back to `max(this process's lifetime * 2, revocation_retention_floor_seconds)`, which covers this process's own grants but cannot be shown to cover a *peer* minting longer ones. That is the one residual gap: a rolling deploy that lowers the lifetime while the mark is gone, which no single process can detect at boot. Set the floor to twice the longest lifetime in your fleet, run Redis with `noeviction`, and check it with `lightspeed:doctor`, which reads the mark. `revoke()` logs whenever it found no mark to read. See [Configuration](configuration.md#the-revocation-floor-is-a-declaration).
- **Removing a `tag()` call turns re-checking off for that channel.** It is the supported way to opt out, and it is also what a deploy that accidentally dropped the line looks like. Lightspeed cannot tell them apart, so it logs a warning the first time a connection re-subscribes to a channel without the grant it was holding, once per channel prefix per worker.
- **Redis write access is inside the trust boundary.** Relay control entries are signed, but ordinary broadcasts are not: anything that can write to the relay stream can inject a payload into any channel, private ones included. See `SECURITY.md`.

## Moving off the hot path: fully hydrated models

Everything above is the hot path. None of it is a prerequisite: when you
want Laravel's hydrated models, step off the hot path with `$event->user()`:

```php
public function handle(ClientEvent $event): ?ClientEventResult
{
    $user = $event->user();   // your Authenticatable, through your default guard's provider

    // ordinary Laravel from here: Eloquent, gates, policies
}
```

The trade is one query against your user storage for that message. For
typical operations, a form submit, a settings change, that is exactly the
cost the rest of your app already pays. For something you need at message rate, reach for a
real-time-optimized answer instead: a username you display on every event
belongs in the signed `tag()->with()` payload or in a Redis cache, not in a
per-message `user()` call.

`user()` is always available and there is nothing to configure. It resolves
through the provider your default guard is configured with, the same one
session auth uses, and the answer is memoized per event, misses included.
Null means "no" here too: it is the answer on private channels and for an
id your provider no longer knows, so a handler that gates on the user
refuses on null rather than falling through.

If you want `auth()->user()`, gates and policies to see that user too, log
them in for the duration of the handler and undo it after, because the
worker outlives the message:

```php
auth()->setUser($user);

try {
    // gates, policies and auth() now answer as this user
} finally {
    auth()->forgetUser();
}
```

Both ends are yours per handler, per message: keystroke-rate messages stay
on the hot path, and the slow way is there when you want your whole app,
forms and all, to travel over websockets.
