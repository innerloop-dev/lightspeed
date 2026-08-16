# Your first real-time feature

Real-time work comes in a handful of shapes, and each shape is its own trip
through the system. This page walks one pattern at a time: what you are trying
to build, every piece you have to write, and where authentication happens for
that pattern. Read [Getting connected](#getting-connected) once, then jump to
the pattern that matches what you are building.

Each snippet is lifted from [example/](../example) or the reference docs.

## Getting connected

Everything below assumes a connected browser. This section is the one-time
setup; you do not repeat it per pattern.

### Start the server

You have run `php artisan lightspeed:install` and `php artisan lightspeed:doctor`
is green. Start the server:

```bash
php artisan lightspeed:serve
```

That is HTTP and websockets on one port. Your app is already being served by it.

### Install the client

```bash
npm install laravel-echo pusher-js
```

### Set the env

The config below reads four `VITE_` vars, and Vite bakes them
into the bundle at build time. `lightspeed:install` does not write them, because
Vite reads `.env` itself and knows nothing about PHP's fallbacks, so an unset
value expands to an empty string rather than to your `APP_URL` host. Add both
blocks to `.env`:

```ini
LIGHTSPEED_PUBLIC_SCHEME=http
LIGHTSPEED_PUBLIC_HOST=localhost
LIGHTSPEED_PUBLIC_PORT=8000

VITE_LIGHTSPEED_APP_KEY="${LIGHTSPEED_APP_KEY}"
VITE_LIGHTSPEED_SCHEME="${LIGHTSPEED_PUBLIC_SCHEME}"
VITE_LIGHTSPEED_HOST="${LIGHTSPEED_PUBLIC_HOST}"
VITE_LIGHTSPEED_PORT="${LIGHTSPEED_PUBLIC_PORT}"
```

That is the local, single-origin shape: the host you browse and the port you
passed to `lightspeed:serve`, which is the same port the websocket uses because
this server carries both. Leaving them unset does not fail loudly: `wsHost: ""`
gets baked into the bundle and the browser quietly dials
`ws://ws-.pusher.com/app/<your key>` instead of your server.

### Write resources/js/echo.js

This is the documented config, and it is the
file the demo's `/echo` page runs:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// laravel-echo reads the Pusher client off the window
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb', // the pusher-protocol driver; talks to Lightspeed unchanged
    key: import.meta.env.VITE_LIGHTSPEED_APP_KEY,
    wsHost: import.meta.env.VITE_LIGHTSPEED_HOST,
    wsPort: Number(import.meta.env.VITE_LIGHTSPEED_PORT),
    wssPort: Number(import.meta.env.VITE_LIGHTSPEED_PORT),
    forceTLS: import.meta.env.VITE_LIGHTSPEED_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

### Import it

Nothing creates or imports that file for you: that was
`install:broadcasting`'s job, and `lightspeed:install` replaces it. Without the
import the bundle builds fine and does nothing, and `window.Echo` is simply
undefined. Add the line to `resources/js/app.js`:

```js
import './echo';
```

And make sure the page loads the bundle:

```blade
@vite('resources/js/app.js')
```

### Add the CSRF tag

Your layout needs it:

```blade
{{-- laravel-echo reads the CSRF token off this tag and puts it on the
     /broadcasting/auth request. Without it that POST is a 419 and no
     private or presence channel can ever subscribe. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
```

### Be logged in

Every pattern below rides a private or presence channel, and
those authorize against the session user: an anonymous visitor gets a 403 from
`/broadcasting/auth` and the subscription never happens. In a real application
your login already covers this.

To follow along in a bare app, borrow the demo's
throwaway per-session login from
[`example/stubs/web.php`](../example/stubs/web.php); its comments also explain
why one browser is one person (a session is one cookie shared by every tab),
which is worth reading before you test with two tabs and conclude presence is
broken.

### No Laravel login? Guests, embeds, kiosks

Lightspeed does not require
Laravel's auth, it requires a signature its broadcaster produced, and who
produces it is yours to decide. Point the client's `authEndpoint` at a route
you control, authenticate however your app grants access, and sign through
the same broadcaster. The full recipe, the one Lightwave uses for share-link
guests in production, is
[your own auth endpoint](authorization.md#your-own-auth-endpoint-guests-without-accounts).

### Build, then restart the server

```bash
npm run build
php artisan lightspeed:restart
```

Vite bakes the `VITE_*` values in at build time, so `npm run build` has to run
after any change to them, and the server has to be restarted afterwards or it
keeps serving the manifest it read at boot. `npm run dev` works too while you
are iterating on the JavaScript.

Every dial in that config, and what each env var means, is in
[configuration.md](configuration.md#client-setup).

## You and your server: the private round trip

**What this is for.** Things only the sender should see. A form submit, a save,
a question with an answer. The browser sends a message up the socket, your PHP
handler runs inside the full Laravel app, and the answer comes back down the
same socket to the sender alone, matched by a `requestId` you mint. This is the
package's heart, and both sides of the wire are yours to write.

Five pieces: a channel rule, the JS send, a handler class, its registration in
config, and the JS listener for the reply.

**The channel rule.** This pattern needs a channel to travel on, and the
channel callback is where authentication happens: Lightspeed accepts a
subscription only if it carries a signature `/broadcasting/auth` produced, so
`routes/channels.php` is the whole gate.

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

// $user is the authenticated session user, so this callback is the gate: what it
// decides is who may join, and therefore who may send on this channel.
Broadcast::channel('lobby', function ($user) {
    return ['id' => $user->id, 'name' => $user->name];
});
```

Notice there is no "is the user authenticated?" line, because Laravel checks
that before your callback runs: on a private or presence channel, a request
no guard can resolve to a principal gets the 403 and your callback is never
invoked. So the callback answers a different question: not "are you
someone" but "may this someone join this channel".

And when your visitors
have no accounts at all, share links, embeds, kiosks, you can skip this
door entirely and run
[your own auth endpoint](authorization.md#your-own-auth-endpoint-guests-without-accounts),
authenticating however your app grants access and signing through the same
broadcaster.

Returning an array, rather than `true`, is what makes this a presence channel;
[Who is here](#who-is-here-presence) explains what the array means. On the
client the channel is `Echo.join('lobby')`, which on the wire is
`presence-lobby`.

**Send it.** `whisper('say')` sends the event `client-say`. Mint a `requestId`
yourself and keep it, because that is what pairs the answer with the question:

```js
// Authentication happens here: this line POSTs to /broadcasting/auth with the
// session cookie and the CSRF token, and only a signed answer joins the channel.
const lobby = window.Echo.join('lobby');

const pending = {};

// <input id="say"> somewhere in the page
document.getElementById('say').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.value.trim()) return;
    const requestId = Math.random().toString(36).slice(2);
    pending[requestId] = performance.now();
    // Echo's whisper() sends `client-say`, which is the event
    // App\Realtime\EchoHandler answers.
    lobby.whisper('say', { requestId, message: e.target.value.trim() });
    e.target.value = '';
});
```

**Handle it.** A client event handler runs inside the full Laravel container:
your models, your services, your gates. Return `null` and the event falls
through to normal Pusher peer relay, which is how you decline an event that is
not yours.

```php
// app/Realtime/EchoHandler.php
namespace App\Realtime;

use Illuminate\Support\Facades\Broadcast;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;

class EchoHandler implements ClientEventHandler
{
    // The identity on $event was proven at subscribe by the channel callback, so
    // nothing here re-asks it: reading $event->userId costs nothing.
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        if ($event->event !== 'client-say') {
            return null;                       // not mine, try the next handler
        }

        $data = (array) $event->data;
        $said = trim((string) ($data['message'] ?? ''));

        if ($said === '') {
            return ClientEventResult::error('empty-message', 'Say something first.');
        }

        // The sender alone gets this, on the socket it asked from.
        return ClientEventResult::response(
            requestId: (string) ($data['requestId'] ?? ''),
            response: ['saved' => true],
        );
    }
}
```

Notice what the handler does not do: it never asks who the sender is or whether
they may be here. Authentication happened once, at subscribe, when the channel
callback ran, so a message on the socket does not pay a login check.

If the
callback also called `tag()`, the server still re-checks revocation on every
message, against a single Redis read, never your database; the full story is in
[authorization.md](authorization.md).

**Register it.** Handlers are found in config, not by convention. If you have
not published the config yet, `php artisan vendor:publish --tag=lightspeed-config`:

```php
// config/lightspeed.php
'client_event_handlers' => [
    App\Realtime\EchoHandler::class,
],
```

**Restart.** The server read that config at boot:

```bash
php artisan lightspeed:restart
```

A server that has not seen the handler does not error. The event falls through
to peer relay, exactly as the `null` return describes, and nothing answers.

**Read the answer.** The reply arrives as a `lightspeed:response` event on the
channel, carrying the `requestId` you sent. Only the socket that asked gets it:

```js
// The handler's reply comes back only on the socket that asked.
lobby.listen('.lightspeed:response', (frame) => {
    const started = pending[frame.requestId];
    if (started === undefined) return;
    delete pending[frame.requestId];
    console.log(frame.status, frame.response, Math.round(performance.now() - started) + 'ms');
});
```

The leading dot means "this is the exact event name", so Echo does not prefix
it with your app namespace.

That is the whole round trip: `whisper('say')` up, handler runs,
`lightspeed:response` down, matched by `requestId`. Once the channel is joined,
no HTTP request is involved in any of it.

`response()` is one of three factories on `ClientEventResult`; the other two are
in [extending.md](extending.md).

## Telling the room: broadcast

**What this is for.** Everyone looking at the same thing sees the change. One
document, many viewers: a message posted, a status flipped, a row updated.
Answering the sender and telling the channel are different things, and they
travel differently; most handlers want both.

From inside a handler, this is the round trip above plus one call before the
return:

```php
// Everyone hears it. $event->userId is the identity your channel
// authorization approved, not something the browser asserted here.
Broadcast::connection()->broadcast(['presence-lobby'], 'said', [
    'message' => $said,
    'from' => $event->userId,
    'at' => now()->toTimeString(),
]);
```

Who receives it: everyone subscribed to the channel, on every worker and every
instance, because the broadcast travels through Redis into whichever workers
are holding connections. On the client:

```js
lobby.listen('.said', (m) => console.log(m.at, 'user', m.from, m.message));
```

Only returning a result means nobody but the sender sees anything, which looks
broken in a chat-shaped app. Only broadcasting means the sender gets no
confirmation and cannot time the round trip.

Broadcasting needs no websocket server in the process, so the same call works
from a process holding no sockets at all. An artisan command, a queue worker, a
scheduled job:

```php
// app/Console/Commands/BroadcastHello.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;

class BroadcastHello extends Command
{
    protected $signature = 'hello:broadcast {message=hello from the server}';

    public function handle(): int
    {
        Broadcast::connection()->broadcast(
            ['presence-lobby'],
            'hello',
            ['message' => $this->argument('message')],
        );

        return self::SUCCESS;
    }
}
```

It broadcasts `hello` rather than `said`, so bind that one too:

```js
lobby.listen('.hello', (data) => console.log('broadcast:', data.message));
```

Run `php artisan hello:broadcast "anyone there?"` and every open tab has it.

Where authentication happens: on the receiving side, at subscribe. A broadcast
reaches only sockets whose subscription your channel callback approved; the
broadcasting process itself is your own PHP and needs no further permission.

## Who is here: presence

**What this is for.** Member lists, avatars, "who is viewing this document",
shared cursors. All of these need to know who is in the room before they can
show anything, and presence is the channel type that carries that answer.

You already built the server side in the round trip: the array your channel
callback returned is the member payload. `{ id, name }` here, because that is
what `Broadcast::channel('lobby', ...)` above returns. Want an avatar in the
member list, put it in that array. Nothing else populates it, and it is the
identity your handlers receive as `$event->userId` and `$event->userInfo`.

Where authentication happens: that same callback. The identity each member
shows to the room is server-approved, produced by your code from the session
user, not something the browser claimed about itself.

On the client, `here()` fires once with the current members, `joining()` and
`leaving()` fire as people come and go:

```js
let here = [];

// <ul id="members"></ul> somewhere in the page
const render = () => {
    document.getElementById('members').innerHTML = '';
    here.forEach((m) => {
        const li = document.createElement('li');
        li.textContent = m.name;
        document.getElementById('members').appendChild(li);
    });
};

window.Echo.join('lobby')
    .here((members) => { here = members; render(); })
    .joining((m) => { here.push(m); render(); })
    .leaving((m) => { here = here.filter((x) => String(x.id) !== String(m.id)); render(); })
    .error((e) => console.error('presence auth failed', e));
```

Each `m` is the array your channel callback returned. The list itself lives in
Redis, so two tabs see each other even when they are talking to different
workers.

`leaving()` tells the other browsers. To tell your own PHP as well, so that a
claim or a lock can be released when somebody's connection ends, register a
connection-closed handler: [extending.md](extending.md#knowing-when-a-connection-goes).

## Client to client: whispers

**What this is for.** High-frequency ephemeral state between people in the same
room: cursor positions, typing indicators, selection highlights. The server
relays the event to the channel's other subscribers without running your
application code. No handler, no database, no PHP at all in the path, which is
why it is the right tool at 60 events per second and the wrong one for anything
you need to keep.

One verb, two uses. `whisper('typing')` and the round trip's `whisper('say')`
put the same kind of frame on the wire: a `client-*` event. Every such event is
offered to your handlers first, and a handler returning `null` is what lets it
fall through to peer relay. Your handler decides by declining. So `client-say`
gets answered by PHP because `EchoHandler` claims it, and `client-typing`
relays to peers because nothing does:

```js
lobby.listenForWhisper('typing', (m) => console.log(m.from, 'is typing:', m.text));

// <input id="typing"> somewhere in the page
document.getElementById('typing').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.value.trim()) return;
    // No server handler claims `client-typing`, so it falls through to plain
    // Pusher peer relay and only the *other* tabs see it.
    lobby.whisper('typing', { from: userId, text: e.target.value.trim() });
    e.target.value = '';
});
```

The sender does not receive its own whisper; the relay goes to everyone else on
the channel.

Where authentication happens: a whisper is authenticated by the subscription it
travels on. The server refuses a client event from a socket that is not
subscribed to the channel, and allows client events only on private and
presence channels, which exist only because your channel callback said yes at
subscribe; if that callback also tagged the connection, the grant is re-checked
against Redis before the relay, so a revoked sender gets a `lightspeed:stale`
frame instead of being relayed.

What the relay does not check is content:
inside an authorized channel, whisper payloads are peer-asserted, so treat
`m.from` above as a hint for rendering, not proof of identity. When identity
matters, claim the event with a handler and use `$event->userId`.

## When access changes

Channel authorization runs once, when a connection subscribes. An open socket
has no idea you changed your mind. Tagging is how you get the second half, and
it is opt-in: a connection you never tagged behaves exactly as it did before.

Tag the connection in the callback, where you are already hitting the database
to answer the same question. `tag()` lists the strings this connection can be
revoked by, and `with()` attaches an opaque payload that arrives on every
message as `$event->auth`:

```php
use Lightspeed\Facades\Lightspeed;

Broadcast::channel('lobby', function ($user) {
    Lightspeed::tag(["user:{$user->id}"])
              ->with(['can_shout' => true]);

    return ['id' => $user->id, 'name' => $user->name];
});
```

Then revoke from anywhere in your app when access changes. Their next message
is refused, and they are dropped out of the channel so they stop receiving:

```php
Lightspeed::revoke('user:42');
```

The client sees a `lightspeed:stale` frame and recovers by leaving and joining
again, which re-runs your callback. Re-joining is not optional and neither is
re-binding: leaving throws the channel object away along with every handler
bound to it, which is why this re-runs the whole function.

```js
function join(name) {
    const channel = window.Echo.join(name);

    channel.listen('.lightspeed:stale', (data) => {
        // data.reason      'revoked' | 'expired' | 'unavailable'
        // data.retry_in_ms a jittered hint, already spread across clients
        setTimeout(() => {
            window.Echo.leave(name);
            join(name);
        }, data.retry_in_ms);
    });

    channel.listen('.said', (m) => { /* your handlers */ });

    return channel;
}
```

Respect `retry_in_ms`: it is the jitter that keeps a revoked crowd from hitting
`/broadcasting/auth`, and therefore your database, all in the same instant. The
full story, including the cost and the tradeoffs, is in
[authorization.md](authorization.md).

## Where to next

- [Extending](extending.md): the handler contract in full, error frames, and routing work to the worker that owns a resource
- [Authorization](authorization.md): tagging, revoking, what a grant is and what it costs
- [Configuration](configuration.md): every setting, the public address, health checks
- [example/](../example): this page as a running app, in two browser tabs, in about a minute
