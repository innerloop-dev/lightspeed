# Architecture

The map of the system. Each name here is the name in the code: one word, one
meaning. When a concept grows, it gains children named from this page, not a
bigger file.

## The shape

A browser speaks the Pusher protocol to a Swoole server embedded in your
Laravel app. Workers are separate processes; Redis is what sits between them.
Everything else is a detail of one of these concepts:

| Concept | Directory | The one job |
|---|---|---|
| Wire protocol | `src/Protocol` | `Handshake` decides which protocol a socket speaks and `Teardown` mirrors it; `FrameRouter` hands each frame to its answerer; `Frames` builds the protocol's control envelopes; `Delivery` writes frames to one socket. Channel fan-out builds and writes its envelopes where the channel state lives (see Channels) |
| Connections | `src/Connections` | which process is holding socket X, findable from outside it: `ConnectionRegistry` records it, `ConnectionSweeper` keeps it true for as long as it is true; `ConnectionClosedDispatcher` tells the application when one goes ([best effort](extending.md#the-bound-this-hook-is-best-effort)) |
| Channels | `src/Channels` | who is subscribed to what on this worker; `Subscriptions` runs the refusal ladder onto a channel and is the single way off one (the diagnostic protocol attaches through `ChannelManager` directly, carrying no presence); `SubscribeLimits` bounds what an anonymous client can allocate; `ChannelManager` holds the local state and does the channel fan-out, because the fan-out consumes that state mid-loop |
| Per-message authorization | `src/Auth` | grants, revocation, expiry sweep, the one uncached Redis read a granted connection pays per message; ungranted connections pay nothing until the application opts in with `Lightspeed::tag()`. Fail closed |
| Broadcasting | `src/Broadcasting` | the Laravel broadcast driver: `LightspeedBroadcaster` answers `/broadcasting/auth` and is the one place a grant is signed into the auth string; `BroadcastBridge` hands server-side events to the fan-out |
| Presence | `src/Presence` | shared member lists in Redis, refcounted, reaped when nobody vouches for them |
| Relay | `src/Relay` | broadcast delivery between workers over one Redis stream, plus the control entries (revocation) that ride it |
| Owner commands | `src/Owner` | run app code on the worker that owns a resource: `OwnerCommandBus` transports and executes (its own Redis streams, beside the relay), `OwnerCommandSigner` signs both directions, `ResourceRouter` routes ownership and holds the write lease (on by default, switchable) |
| Client events | `src/ClientEvents` | what a `client-*` frame passes through before app handlers see it |
| Diagnostics | `src/Diagnostics` | the opt-in probe protocol; refused unless the peer is on the configured allow list (loopback by default) and unproxied |
| HTTP | `src/Http` | the four destinations of a plain request, the publish endpoint, the host app's Octane worker, static file types |
| Workers | `src/Workers` | one process's identity and state: `WorkerContext` (who am I), `WorkerHealth` (am I ready), `AttachedServer` (what am I attached to) |
| Boot | `src/Boot` | what refuses or reports a bad setup before it serves: `BootValidation` stops `lightspeed:serve`, `VersionConstraint` reads composer constraints for `lightspeed:doctor` |
| Logging | `src/Logging` | `OperatorLog`: what an operator must see, once; `RuntimeLogger`: config-gated event lines |
| Assembly | `src/Server.php`, `src/LightspeedServiceProvider.php` | the listeners, the guard around every callback body, and the object graph |

`src/Console` holds the artisan commands plus `DoctorReport`, the doctor's
renderer. `lightspeed:doctor` is Boot's console face; `lightspeed:relay-probe`
speaks the diagnostic protocol, while the other probes open real Pusher
clients; `lightspeed:install`, `stop` and `restart` are operational tools with
no deeper concept behind them. `src/Contracts` is the interfaces
applications implement; `src/Facades` is `Lightspeed::tag()` / `::revoke()`.

## Vocabulary

- **frame**: the unit on the websocket wire, both directions.
- **event**: the Pusher event name string inside a frame.
- **payload**: data carried by something, named by its carrier: a grant
  payload, a command payload, a frame's decoded payload.
- **fd**: the worker-local socket number. Reusable, meaningless in any other
  process; the diagnostic protocol echoes it to loopback probes as a debug
  aid, and nothing else lets it out.
- **socket id**: the durable cross-process identity of one connection.
- **connection**: one client's websocket. A Redis config entry is a "Redis
  connection name", spelled fully.
- **grant**: the signed authorization a connection holds for one channel.
  A connection whose auth string carries none is "untagged".

Older identifiers predating this page do not all follow the vocabulary yet;
new code must.
