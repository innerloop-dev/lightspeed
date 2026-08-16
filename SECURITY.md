# Security policy

## Reporting a vulnerability

Please report security issues privately through GitHub's private vulnerability
reporting, on the [Security tab](https://github.com/innerloop-dev/lightspeed/security)
of this repository. That keeps the report between us until there is a fix.

Please do not open a public issue for anything exploitable.

A useful report says what an attacker can do, and includes enough detail to
reproduce it: the configuration involved, the frames or requests sent, and what
happened that should not have. A working proof of concept is welcome but not
required if the reasoning is clear.

This is a small project with no dedicated security team, so acknowledgement may
not be immediate. Reports are taken seriously regardless.

## Supported versions

The project is pre-1.0 and unstable. Only the current `main` branch receives
fixes. There are no backports.

## What this package is responsible for

Lightspeed terminates websocket connections and decides who is allowed to
subscribe to a channel and whose identity a message carries. The parts worth
scrutinising most:

- **Subscription authorization** (`src/Protocol/SubscriptionAuthorizer.php`).
  Private and presence channels require an HMAC over the socket id, the channel
  name, and (for presence) the channel data. The socket id is minted by the
  server and never taken from the client, so a signature issued for one
  connection cannot be replayed on another. A channel is protected when its name
  starts with `private-` or `presence-`, **with the dash**, which is the Pusher
  rule. Laravel's `PusherBroadcaster` guards on `private`/`presence` without it,
  so a channel called `privateOrders` runs your channel callback while this
  server treats it as public. Name channels the way Echo does.
- **`private-encrypted-` channels are refused, not served.** Lightspeed does not
  implement Pusher's end-to-end encrypted channels: it holds no shared channel
  secret and seals no payloads, and everything it relays is the clear text the
  publisher sent. The prefix is refused at both gates, the `/broadcasting/auth`
  endpoint (403, with a message saying why) and the subscribe frame, so an
  application that asks for encryption is told no at the first attempt. It used
  to be accepted, because the name starts with `private-`: the channel
  authorized, subscribed and delivered, and `Echo.encryptedPrivate(...)` looked
  like it worked while giving you exactly the property you chose that API to
  avoid. Use `Echo.private()` when server-visible payloads are acceptable for
  the data, and encrypt in your application before publishing when they are not.
- **Identity on inbound messages.** A handler's view of who sent a frame comes
  from the channel the frame arrived on, never from the frame's payload.
- **The diagnostic protocol**, which is off by default. It is an unauthenticated
  administrative surface, so it is gated to the loopback addresses in
  `LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM` (`127.0.0.1,::1` by default),
  eligibility is decided once at connection open rather than per frame, and any
  connection presenting `X-Forwarded-For`, `X-Real-IP`, `Forwarded`, or
  `X-Forwarded-Host` is refused. If you put a proxy in front of the server,
  read `docs/configuration.md` first: a proxy that forwards every path will make
  every connection appear to come from loopback.

## Redis is a trusted component

Every worker reads a shared Redis: the relay stream that carries broadcasts
between processes, the revocation log, presence, and the connection registry.
Write access to that Redis is inside the trust boundary. This is the precise
extent of it.

- **Relay CONTROL entries are signed** with `LIGHTSPEED_APP_SECRET`, and the
  control type is inside the signature so one control cannot be replayed as
  another. Redis write access alone therefore cannot force connections off a
  channel.
- **Owner commands and their responses are signed** with the same secret, and
  the same way. This is the instruction path that matters most: a drained
  command becomes a call into your own `OwnerCommandHandler` with the command
  string and payload array the entry carried, taken under the resource write
  lease so it is cleanly serialised against your real mutations. Nothing secret
  is needed to address one, the stream key is `lightspeed:owner-commands:` plus
  a process key that sits in plaintext in `lightspeed:resource-owner:*`, so
  before the signature, Redis write access was arbitrary application write
  execution. The response is signed too, because the request id an attacker
  would need to forge one is a field of the command entry they can already read.
- **Ordinary BROADCAST entries are not signed.** Anything that can write to the
  relay stream can put an arbitrary payload into any channel, private ones
  included, and every subscriber receives it. That is worse than forcing an
  unsubscribe, so read the control signing above narrowly: it covers the control
  path and nothing else. Redis write access is not harmless.

  Signing every broadcast would put an HMAC on the fan-out path, which is the hot path this package is measured
  on, and would make a rolling deploy discard the entries written by whichever
  half of the fleet has not restarted yet. Control entries are rare, and are
  instructions rather than data, so they pay neither cost. If your threat model
  includes an attacker with Redis write access but not your application secret,
  do not rely on channel privacy: give Lightspeed a Redis of its own, on its own
  credentials, reachable only from your application and websocket hosts.

- **A revocation has to outlive the grants it killed.** Deleting keys under
  `lightspeed:revoked:*`, or letting them be evicted under a `maxmemory` policy,
  readmits every connection whose auth string is still inside its grant
  lifetime, because that string is replayed verbatim and there is nothing left
  to refuse it. Run the Redis Lightspeed uses with `noeviction`.

## What is your responsibility

- **Your channel authorization rules.** Lightspeed enforces that a client is who
  your application said it is. It cannot decide whether that identity should be
  allowed to do a given thing, which is your `routes/channels.php` and your
  handler's job.
- **Keeping `LIGHTSPEED_APP_SECRET` secret.** Anyone holding it can mint valid
  subscription signatures for any channel and any identity.
- **Terminating TLS.** The server speaks cleartext websockets and HTTP. Anything
  facing the internet belongs behind a proxy that terminates TLS.
- **Not proxying the diagnostic protocol.** It is not a separate port: it is a
  websocket path on the same port everything else is served on, so what keeps it
  unreachable is your proxy forwarding only the paths that belong to clients.
  `deploy/nginx/two-hosts.conf.example` forwards `/app/`, `/apps/` and
  `/healthz` and answers 404 to everything else, which is the shape to copy.

## `/healthz` is meant to be probed

`docs/PRODUCTION.md` tells operators to point load balancer readiness and
liveness checks at `GET /healthz`, and the nginx example proxies it. What it
answers is a fixed, small object: `ok` (bool), `server` (the constant string
`lightspeed`), `time` (an ISO-8601 timestamp), and, only when the worker is NOT
ready, `error`: a one-line summary of what stopped that worker starting. No
connection, channel, identity or payload data is in there, in any state.

The one line worth thinking about is that `error`, because it is the class name
and message of a real exception. The usual cause is a Redis that was unreachable
at worker start, so such a message can name internal infrastructure (`...
Connection refused [tcp://10.0.0.5:6379]`) to anyone who reaches the endpoint
while a node is broken. Other failures can put other configuration-derived
strings there: the class name of a handler, or the name of a connection.

The alternative, a red check with no reason, costs more. If that address is
something you would rather not publish, restrict the `/healthz` location to your
load balancer's network at the proxy; the probe still reaches it, and everything
above still applies.

## Known weaknesses

Known, and not treated as vulnerabilities:

- Behaviour under adversarial load is untested. Load testing so far has measured
  delivery and latency, not resistance to abuse.
- There is no per-connection rate limiting. A client event is bounded in SIZE
  (`lightspeed.client_events.max_frame_bytes`, 10KB, Pusher's own limit) and a
  request or frame is bounded before Swoole assembles it
  (`lightspeed.server.package_max_length`), but nothing bounds how OFTEN an
  authorized connection may send. Each client event costs a handler run inside
  the application and, when no handler answers it, a fan-out to every other
  subscriber on the channel. Rate limiting has to come from your handler or from
  something in front of the server.
- A half-open socket, one whose client vanished without a FIN, is held open. The
  Swoole heartbeat that would reap it exists as a dial
  (`lightspeed.server.heartbeat_idle_time` / `heartbeat_check_interval`) and is
  OFF by default, because Swoole closes a quiet connection rather than pinging
  it, and this server does not yet ping quiet clients: a browser that only
  listens to a busy channel sends nothing and would be closed while it is
  working perfectly. Turn it on when every client of yours sends something more
  often than the idle time.
- There is no end-to-end encryption. Payloads are readable by the server, by
  anything with read access to its Redis, and by anything terminating TLS in
  front of it. `private-encrypted-` channels are refused rather than emulated.
  Data that must not be readable by this server has to be encrypted by your
  application before it is published.
- Write leases are not renewed mid-flight, so the single-writer guarantee holds
  only while a mutation finishes inside the lease TTL.
- Presence entries can outlive a worker killed with `SIGKILL`. The bound is
  `lightspeed.presence.connection_ttl_seconds` (60 by default) plus up to one
  sweep interval, plus rotation time when a surviving worker holds more presence
  channels than `sweep_max_channels_per_tick`. Call it about a minute in the
  ordinary case, longer on a busy worker.
- A forwarded owner command is bounded by
  `lightspeed.owner_commands.blocking_wait_timeout_ms` (250ms) rather than by
  `wait_timeout_ms` whenever the waiting worker cannot yield, which with the
  shipped `enable_coroutine` setting is always. A slower owner is reported to
  the caller as a failure instead of freezing the worker.
