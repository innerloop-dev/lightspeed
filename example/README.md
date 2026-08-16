# Lightspeed hello world

A tiny app that shows the three things Lightspeed claims, in a browser, in about a minute.

```sh
./install.sh
```

That creates a fresh Laravel app in `./hello-world`, installs Lightspeed into it, runs `lightspeed:install` the way the main README says to, builds the client assets, and starts the server. Then open <http://localhost:8000> in two browser tabs.

You need PHP 8.2+ with the Swoole extension, Redis running locally, Composer, and npm. Redis is looked for on `127.0.0.1:6379`; set `REDIS_HOST` and `REDIS_PORT` to point the whole app somewhere else.

### One browser is one person

The demo logs you in as a throwaway user so channel authorization has an identity to work with, and it creates that user **once per session**, not once per request. A session is one cookie and every tab in the browser shares it, so:

- **Two tabs are one person on two connections.** That is enough for presence, for whispers, and for watching a `broadcast()` arrive in both.
- **To be two people, be two sessions.** Open the second one in a private/incognito window, a second browser, or a second browser profile.

Worth spelling out because the tempting shortcut, a `User::create()` and `Auth::login()` on every request, reads like a per-tab identity and is not one: each new tab overwrites the shared session, and the previous tab goes on displaying an id that `/broadcasting/auth` has stopped answering for, subscribed to a private channel that now belongs to someone else. This is example code people copy, so it does the boring correct thing.

There are two pages, and they exist for different reasons:

| Page | Client |
|---|---|
| `/` | Raw pusher-js off the CDN, so you can watch every frame on the wire |
| `/echo` | Real Laravel Echo from npm, configured by `resources/js/echo.js`, which is the snippet in [docs/configuration.md](../docs/configuration.md) verbatim |

`/echo` is the page that proves the documented path rather than describing it: it connects, authorizes a presence channel *and* a private channel through `/broadcasting/auth`, lists members, receives a server-side `broadcast()`, sends a client event and gets its handler's reply back on the same socket, and relays a whisper to the other tab. Vite bakes the `VITE_*` values into the bundle at build time, so after changing any of them run `npm run build` and restart the server.

## What you are looking at

**Presence.** Each tab joins a presence channel and both lists update as tabs open and close. That list is shared state in Redis, so tab A and tab B see each other even though they may be talking to different workers.

**Up the socket.** Type a message and press send. The browser does not make an HTTP request: it sends a client event over the websocket, a Laravel handler runs it inside the full framework, and the reply comes back down the same socket. The round-trip time you see is that whole path.

**Down the socket.** In another terminal:

```sh
cd hello-world
php artisan hello:broadcast "anyone there?"
```

A plain Laravel `broadcast()` from a process with no server in it reaches every open tab through Redis.

## Files worth reading

| File | Why |
|---|---|
| `stubs/EchoHandler.php` | The whole server side: one class, one method, answers a client event |
| `stubs/channels.php` | Channel authorization. This is what decides who may join |
| `stubs/welcome.blade.php` | The raw pusher-js client, so you can see every frame on the wire |
| `stubs/echo.js` | The documented Laravel Echo config, copied out of `docs/configuration.md` |
| `stubs/echo-page.blade.php` | The Echo client: presence, private, client events, whispers, broadcasts |
| `stubs/BroadcastHello.php` | The artisan command that broadcasts from outside the server |
| `stubs/InboxHello.php` | The same, aimed at one user's private channel |
| `stubs/web.php` | The two routes, and the throwaway per-session login that gives channel authorization an identity to work with |

Nothing in either client is special. One is pusher-js pointed at Lightspeed's port and the other is stock Laravel Echo pointed at the same place, which is the point: existing Pusher clients do not change.

## Cleaning up

```sh
./install.sh --clean
```
