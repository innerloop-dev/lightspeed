# Configuring Lightspeed

Everything the server reads, what it answers for itself, and the one protocol you should understand before enabling it.

## Checking a setup

```bash
php artisan lightspeed:doctor
```

Reads the configuration below and reports what is wrong with it, on one screen, with the file, env var or command that fixes each thing. It checks:

- PHP against the constraint in the package's own `composer.json`, and the Swoole extension
- that a PHP Redis client exists, ext-redis or `predis/predis`. `composer require innerloop-dev/lightspeed` installs neither, because predis is only a `suggest`
- that Redis answers on each connection this package uses, naming which connection each one resolved to. See the fallback chain below: they are not all `default`
- `LIGHTSPEED_APP_ID` / `APP_KEY` / `APP_SECRET`. `lightspeed:serve` refuses to start if either the key or the secret is missing. The app id is an address, not a credential: an empty one still lets the server start, but the publish URL becomes `/apps//events`, a 404, so every server-side `broadcast()` reaches nobody
- that the connection `BROADCAST_CONNECTION` names **resolves to Lightspeed's broadcaster**, and that `config/broadcasting.php` defines it. The connection may be called anything; only its `driver` has to be `lightspeed`
- that at least one channel is **registered**, asked of the broadcaster rather than grepped from `routes/channels.php`, so a file with every definition commented out is reported as defining none
- your client event handlers: that the config file was published, and that handlers are registered. Separately, that every class in all three handler lists exists and implements its contract, which the boot validation `lightspeed:serve` runs checks too, so a typo in any of the three refuses the start rather than surfacing as a silent no-op later
- the public address clients dial against the address the server binds, and the scheme of that address against `APP_URL`: an `https` page may not open a `ws://` socket, which every browser blocks as mixed content before anything reaches this server
- `worker_num`, `enable_coroutine`, the grant lifetime ceiling that the package refuses to run past, and the revocation floor, which it checks against the grant lifetime high-water mark it reads out of Redis rather than against your config. If Redis cannot be asked it says so, rather than reporting the absent answer as a pass

It exits non-zero if anything is broken, so it works in a deploy script. `--json` gives the same report as `{ok, counts, checks}`, and gives it even when something the command has to resolve throws. `--fix` prints the commands and runs none of them.

## Every setting

Every setting lives in [config/lightspeed.php](../config/lightspeed.php). All of them have an env var except four, which are lists rather than values and have to be edited in the published config file:

- `static_files.mime_types`, an extension to MIME map
- `client_event_handlers`, `connection_closed_handlers` and `owner_command_handlers`, all lists of class names

Highlights:

| Env | Default | Meaning |
|---|---|---|
| `LIGHTSPEED_SERVER_PORT` | `8000` | Bind port for HTTP + websockets |
| `LIGHTSPEED_WORKER_NUM` | `1` | Worker processes |
| `LIGHTSPEED_RELAY_ENABLED` | `true` | Redis relay for cross-worker/instance delivery |
| `LIGHTSPEED_INSTANCE_ID` | none | Unique instance id (required for clusters) |
| `LIGHTSPEED_APP_ID/KEY/SECRET` | falls back to `REVERB_APP_*` | Pusher-protocol credentials |
| `LIGHTSPEED_RESOURCE_CHANNEL_PREFIX` | `resource` | Channel noun that names an owned resource |
| `LIGHTSPEED_PRESENCE_COLOR_SLOTS` | `0` | Palette size for presence colours; `0` disables them |
| `LIGHTSPEED_RESOURCE_OWNER_TTL_SECONDS` | `30` | Owner claim lease length |
| `LIGHTSPEED_DIAGNOSTICS_ENABLED` | `false` | Local diagnostic protocol (loopback only) |
| `LIGHTSPEED_MAX_CLIENT_EVENT_BYTES` | `10240` | Largest `client-*` frame accepted; over-limit frames are refused with `client-event-too-large`, never truncated ([below](#bounding-what-one-connection-can-send)) |
| `LIGHTSPEED_PACKAGE_MAX_LENGTH` | `2097152` | Largest request or frame Swoole assembles at all, uploads to your application included ([below](#bounding-what-one-connection-can-send)) |
| `LIGHTSPEED_HEARTBEAT_IDLE_TIME` | `0` (off) | Seconds of silence after which Swoole closes a connection ([below](#reaping-connections-that-went-quiet)) |
| `LIGHTSPEED_HEARTBEAT_CHECK_INTERVAL` | `0` (off) | How often that check runs; both halves are needed for either to apply |
| `LIGHTSPEED_PRESENCE_REDIS_CONNECTION` | `LIGHTSPEED_RELAY_REDIS_CONNECTION`, else `default` | Redis connection for presence state |
| `LIGHTSPEED_RELAY_REDIS_CONNECTION` | `default` | Redis connection for the relay, and the fallback for presence, connections, resources, auth and owner commands |
| `LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS` | `300` | How long a per-message grant lasts before the client re-authorizes ([authorization](authorization.md)) |
| `LIGHTSPEED_AUTH_STALE_RETRY_MAX_MS` | `2000` | Upper bound on the jittered re-subscribe hint sent with a refusal |
| `LIGHTSPEED_AUTH_MAX_TAG_LENGTH` | `191` | Longest tag `Lightspeed::tag()` accepts; over-long tags are rejected, never truncated |
| `LIGHTSPEED_AUTH_MAX_TAGS` | `16` | Most tags one grant may carry |
| `LIGHTSPEED_AUTH_REDIS_CONNECTION` | `LIGHTSPEED_RELAY_REDIS_CONNECTION`, else `default` | Redis connection for the revocation log |
| `LIGHTSPEED_AUTH_REVOCATION_RETENTION_FLOOR_SECONDS` | `3600` | Shortest time a revocation is kept. Set it to twice the longest grant lifetime **anywhere in your fleet** ([why](#the-revocation-floor-is-a-declaration)) |
| `LIGHTSPEED_AUTH_SWEEP_MAX_PER_TICK` | `200` | Subscriptions one sweeper tick will unwind, split between revocation backlog and expiry |
| `LIGHTSPEED_OWNER_COMMANDS_FRESHNESS_SECONDS` | `30` | How old a signed owner command may be before a worker refuses it, which is what stops a captured command being replayed later |
| `LIGHTSPEED_OWNER_COMMANDS_REDIS_CONNECTION` | `LIGHTSPEED_RELAY_REDIS_CONNECTION`, else `default` | Redis connection for the owner-command streams |
| `LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM` | `127.0.0.1,::1` | Comma-separated peers allowed to speak the diagnostic protocol |
| `LIGHTSPEED_PUBLIC_SCHEME` / `_HOST` / `_PORT` | from `APP_URL` | Where **clients** dial, as opposed to where the server binds ([below](#bind-address-versus-public-address)) |

**Note the fallback chain.** Presence, connections, resources, auth and owner commands each fall back to `LIGHTSPEED_RELAY_REDIS_CONNECTION` before `default`, not straight to `default`. That is five usages plus the relay itself, which is the six `lightspeed:doctor` reports a row for. So setting only the relay connection moves all of them.


## Bounding what one connection can send

Two limits, at two layers, because they answer different questions.

`LIGHTSPEED_MAX_CLIENT_EVENT_BYTES` (10240, Pusher's own limit) is the one that matters to an application. A `client-*` frame is the only frame that carries an application payload up the socket, and it is the payload the server does the most with: it crosses into your handler inside the booted application, and if no handler answers it, it is fanned back out to every other subscriber on the channel.

An over-limit frame is refused with a `pusher:error` whose code is `client-event-too-large` and whose message names the limit. Nothing is truncated, because a payload silently cut in half is a handler running against data the client never sent. The limit is measured on the whole frame, envelope included, rather than on the `data` member alone the way Pusher states it.

`LIGHTSPEED_PACKAGE_MAX_LENGTH` (2MB, Swoole's own default) is the bound underneath that, applied by Swoole before any of this package's code runs. It is **not** sized to the client-event limit, and cannot be: one process serves your application's HTTP on the same port, so this number is also the largest file your application can accept as an upload. Raise it if your application takes bigger uploads than 2MB; lowering it to 10KB would refuse every upload you have.

There is no rate limit at either layer. Size is bounded, frequency is not; see [SECURITY.md](../SECURITY.md#known-weaknesses).

## Reaping connections that went quiet

A client whose network vanished without closing its socket leaves a connection this server holds open indefinitely. Swoole can reap those: `LIGHTSPEED_HEARTBEAT_IDLE_TIME` closes any connection that has **sent** nothing for that many seconds, checked every `LIGHTSPEED_HEARTBEAT_CHECK_INTERVAL` seconds, and the close runs the same teardown an orderly disconnect does. Set both or neither — one alone is ignored.

**Both ship at `0`, off.** Swoole's heartbeat *closes* a quiet connection; it does not ping it first (measured on Swoole 6.2.0). And pusher-js only sends a ping after `activity_timeout` of hearing nothing at all, resetting that timer on every frame it **receives**. So a browser that only listens, on a channel that is busy, sends nothing while it is working perfectly, and a heartbeat on by default would close it. Pusher's and Reverb's equivalent is a server-side ping to quiet connections, which this package does not have yet.

Turn it on when every client of yours sends something more often than the idle time: a client that both sends and receives, or one you have given a ping of its own. `120` / `60` are the values to use, sized against the 30-second `activity_timeout` the handshake advertises. `lightspeed:serve` prints the pair in its banner whenever it is on, because it is the one setting here that closes working connections.

## Bind address versus public address

Two different things, and they are separate settings because in production they are separate values:

| | Setting | Env | Default |
|---|---|---|---|
| Where this process **listens** | `server.host` / `server.port` | `LIGHTSPEED_SERVER_HOST` / `LIGHTSPEED_SERVER_PORT`, falling back to `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `0.0.0.0` / `8000` |
| Where **clients dial** | `reverb_compat.public_scheme` / `public_host` / `public_port` | `LIGHTSPEED_PUBLIC_SCHEME` / `_HOST` / `_PORT`, falling back to `REVERB_SCHEME` / `REVERB_HOST` / `REVERB_PORT` | parsed from `APP_URL` |

This is the same split [Reverb](https://laravel.com/docs/reverb) makes, with the same names and the same meanings, so a Reverb setup transfers unchanged.

It exists because behind a TLS terminator the two are genuinely different: the server binds `8000` on loopback and browsers dial `443` over `https`. That is the shape [the nginx example](../deploy/nginx) documents, and a package with only bind values could not describe it.

Everything client-facing reads the public triple: the Echo config below, and the defaults of the probe commands. Nothing reads the bind values to tell a client where to go.

The probes are not uniform about it, and it is worth knowing which one you are running. `lightspeed:probe`, `lightspeed:relay-probe` and `lightspeed:presence-probe` each take `--host`, `--port` and `--tls`, all three defaulting from the public triple. `lightspeed:load-probe` is the exception: it takes `--host` (defaulting from `public_host`) but addresses the servers it opens connections against with `--targets=connectHost:port,...` instead of a `--port`, because its whole job is to spread load across several of them, and its `--tls` defaults to `0` rather than to `public_scheme`.

If your app and your realtime traffic share one origin, which is the default shape, set none of these. `APP_URL` already says where browsers reach you and the public triple is parsed from it.

**Lightspeed does not terminate TLS.** There is no certificate setting, because that is the proxy's job in every topology here. So `public_scheme=https` always means something is terminating in front, and `lightspeed:doctor` warns if you set `https` on the very port the server binds, which cannot work.


## Client setup

Standard Echo + Pusher configuration. If you ran `install:broadcasting`, this **replaces** the generated `resources/js/echo.js`:

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

Those three values are the public triple, so the same config is correct on a laptop and behind a TLS terminator. `wsPort` and `wssPort` are both set because pusher-js picks between them on `forceTLS`, and a config that sets only one is right in exactly one of the two deployments.

No `cluster` key is needed. Raw pusher-js throws `Options object must provide a cluster` no matter what else you set, but `broadcaster: 'reverb'` makes laravel-echo pass `cluster: ''` for you. If you use `broadcaster: 'pusher'` instead, you have to add a `cluster` of your own; its value is never used once `wsHost` is set.

Vite only exposes env vars prefixed with `VITE_`, so publish them in `.env` alongside the server-side ones. **Spell the public triple out.** `config/lightspeed.php` defaults it from `APP_URL`, but that fallback is PHP: Vite reads `.env` itself and knows nothing about it, so an unset `LIGHTSPEED_PUBLIC_HOST` expands to an empty string rather than to your `APP_URL` host:

```ini
LIGHTSPEED_PUBLIC_SCHEME=http
LIGHTSPEED_PUBLIC_HOST=localhost
LIGHTSPEED_PUBLIC_PORT=8000

VITE_LIGHTSPEED_APP_KEY="${LIGHTSPEED_APP_KEY}"
VITE_LIGHTSPEED_SCHEME="${LIGHTSPEED_PUBLIC_SCHEME}"
VITE_LIGHTSPEED_HOST="${LIGHTSPEED_PUBLIC_HOST}"
VITE_LIGHTSPEED_PORT="${LIGHTSPEED_PUBLIC_PORT}"
```

That is worth being fussy about, because leaving them unset does not fail loudly. `wsHost: ""` and `wsPort: 0` get baked into the bundle, pusher-js fills the blanks from its cluster default, and the browser quietly dials `ws://ws-.pusher.com/app/<your key>` instead of your server. Set them, and rebuild: Vite bakes these in at build time, so `npm run build` has to run after any change, and a long-lived `lightspeed:serve` has to be restarted afterwards or it keeps serving the manifest it read at boot.

Values above are the local, single-origin shape: `http`, the host you browse, and the port you passed to `lightspeed:serve`, which is the same port the websocket uses because this server carries HTTP and websockets together. Behind a TLS terminator they become `https`, your public hostname and `443`. The port the *terminator* listens on, never the one the server binds. That is the only difference between the two deployments; the JavaScript above does not change.

One thing the snippet cannot do for you: laravel-echo reads the CSRF token out of `<meta name="csrf-token" content="{{ csrf_token() }}">` and puts it on the `/broadcasting/auth` request. Without that tag in your layout every private and presence subscription fails on a 419 before it ever reaches a channel callback.

All of the above is exercised by `example/hello-world`'s `/echo` page, which builds this exact `resources/js/echo.js` and drives presence, private channels, client events and whispers against a running server.


## The revocation floor is a declaration

`LIGHTSPEED_AUTH_REVOCATION_RETENTION_FLOOR_SECONDS` is the one setting here you cannot derive from this process alone.

A revocation has to outlive every grant it invalidates, or the client replays the same auth string once the record lapses and there is nothing left to refuse it. Normally that works itself out: every mint records the lifetime it used in a Redis high-water mark, and a revoke keeps its record for twice the longest lifetime anything recently minted under.

The floor is what stands in for that mark when there is none to read: a flushed Redis, a key evicted under `maxmemory`, or the first revoke a fresh deployment performs.

**Nothing a process mints itself needs it.** A revoke already keeps its record for twice its own `grant_lifetime_seconds` whatever the floor says. What the floor is for is the one case that doubling cannot reach: a *peer* minting longer grants than the revoking process, while the mark is gone. That happens during a rolling deploy that lowers the lifetime.

So set it to twice the longest `grant_lifetime_seconds` any process in your fleet mints under, **including the version still draining**. No process can check that at boot, which is why the server does not refuse to start on it. What does help:

- `lightspeed:doctor` reads the actual high-water mark out of Redis and warns if the floor would not cover it
- `revoke()` logs a warning whenever it found no mark to read, which is the moment the gap opens
- run this Redis with `noeviction`


## Health checks

The server answers two paths itself, before your application sees them:

| Path | Answered by | For |
|---|---|---|
| `/healthz` | Lightspeed | Load balancer checks against the realtime surface |
| `/apps/{id}/events` | Lightspeed | The Pusher HTTP publish API |

Everything else goes to your Laravel routes. Worth knowing: if your app already defines a `/healthz` route, Lightspeed answers it first and your route never runs. Rename yours, or use `/up`, which Laravel ships and Lightspeed passes straight through.


## The diagnostic protocol

Alongside the Pusher protocol, the server can speak a small second protocol written for inspection: `whoami` (which worker and instance am I on), a raw `subscribe`, and `broadcast-test`. It is what lets `lightspeed:relay-probe` prove that a message published on one worker reaches a client attached to another.

Inspection is what it is *for*, not the limit of what it *can do*, and the difference matters when you decide where to enable it. A connection admitted to this protocol subscribes to any channel, private and presence included, without presenting an auth signature; from there it can whisper to that channel, publish to any channel with `broadcast-test`, and send a `client-*` frame that reaches your application's own client-event handlers, exactly as an authorized Pusher client's would. In SECURITY.md's words it is an **unauthenticated administrative surface**. What stands between that and the internet is three gates: it ships disabled, it accepts only the peer addresses in `LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM` (loopback), and it refuses any connection carrying forwarding headers.

Enable it when you want to run the relay probe:

```ini
LIGHTSPEED_DIAGNOSTICS_ENABLED=true
```

The peers it will accept are `LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM`, comma separated, defaulting to `127.0.0.1,::1`. Widening it past loopback is the change that turns a local inspection tool into a client surface, so read the caveat below first.

A client that connected with the Pusher protocol can never switch to it; everything else is refused with `invalid-path`. The list is only loopback until you change it, which is not the same as loopback being hardcoded: the gate is exactly as narrow as `LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM` says it is.

**One caveat worth understanding before you enable it anywhere but your laptop.** A reverse proxy connects to this server from loopback on behalf of whoever it is serving, so "the peer is local" stops meaning "the caller is local" the moment something is proxying every path through to the realtime port. Refusing forwarded requests covers the proxies that announce themselves, but the real protection is not routing this path to the server at all: the [nginx example](../deploy/nginx) proxies only `/app/`, `/apps/` and `/healthz` through to the realtime port, and returns 404 for everything else. If you enable diagnostics on a machine that takes traffic from the internet, check your own proxy config before you trust the gate.

