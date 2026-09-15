# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims to
follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from 1.0 onward.

Until 1.0 the public API should be treated as unstable, and minor versions may
contain breaking changes.

## [Unreleased]

Initial release. Extracted from Lightwave, the collaborative editor it powers
in production: keystrokes, presence, cursors, guest sessions.

### Added

- Swoole-based server that runs HTTP and websockets in one process, with
  Laravel booted once per worker (`lightspeed:serve`).
- Pusher protocol support, so Laravel Echo and existing Pusher clients connect
  unchanged: `pusher:subscribe`, `pusher:unsubscribe`, ping and pong, presence
  channels, and client events.
- Bidirectional message handling. A frame from the browser is dispatched to a
  Laravel handler, and the handler's return value is sent back down the same
  connection, correlated to the request that asked for it.
- Channel authorization through Laravel's own `routes/channels.php`, verified
  in process by HMAC over a server-minted socket id, so a client cannot present
  an identity it was not granted.
- Per-message authorization, opt-in and with no database on the message path.
  A channel callback attaches tags to the connection with `Lightspeed::tag()`,
  and they are signed into the `auth` string the client already presents. A
  client that strips or edits the grant fails the same signature check that
  catches a forged one, and there is no state in which a connection holds a
  valid credential with no grant attached. `Lightspeed::revoke('project:7')`
  drops every connection carrying that tag out of the fan-out on the relay
  notification, and refuses its next message with `lightspeed:stale`. The per-message check is
  one uncached Redis read; if Redis cannot answer, the message is refused.
- `ClientEvent::user()`, which hydrates the event's approved identity through
  the application's own auth user provider. Calling it is the only cost: one
  memoized lookup, and an event nobody asks about touches user storage not at
  all.
- Presence store backed by Redis, shared across workers and machines, with
  server-assigned colour slots so one person's tabs agree on a colour.
- A connection-closed hook. Classes listed in `connection_closed_handlers`
  implement `Lightspeed\Contracts\ConnectionClosedHandler` and are told inside
  the application when a connection ends, with the channels it held, the
  presence memberships it ended, the tags of the grants it was carrying, and
  `authPayloads`, a map of channel name to that channel's own grant payload.
  Handlers fire only for connections that held a channel subscription or a
  grant. Best effort: a killed worker emits no event, and presence members
  surface later as `swept`. See [docs/extending.md](docs/extending.md#the-bound-this-hook-is-best-effort).
- Bounds on what one connection can make the server carry: a client event is
  limited to 10KB, Pusher's own limit
  (`LIGHTSPEED_MAX_CLIENT_EVENT_BYTES`), refused with `client-event-too-large`
  rather than truncated, and Swoole's own per-request/per-frame ceiling is
  stated as a dial (`LIGHTSPEED_PACKAGE_MAX_LENGTH`, 2MB, which is also the
  largest upload the host application can accept on the shared port). Neither is
  a rate limit; there is none.
- Optional reaping of connections that have gone silent, through Swoole's
  heartbeat (`LIGHTSPEED_HEARTBEAT_IDLE_TIME` /
  `LIGHTSPEED_HEARTBEAT_CHECK_INTERVAL`). OFF by default: Swoole closes a quiet
  connection rather than pinging it, and a browser that only listens is quiet
  while it is perfectly healthy. See docs/configuration.md.
- Cross-worker and cross-machine broadcast relay over Redis streams.
- Per-resource owner routing and write leases, so writes for a given resource
  are handled by a single worker and cannot interleave with another worker's
  writes. The owner command bus is HMAC signed in both directions.
- `OwnerCommandBus::forwardWithoutReply()`, a fire-and-forget path to the
  owning worker for command streams whose result the caller has no use for. It
  writes the signed command to the owner's stream and returns immediately: it
  never polls, never sleeps and never waits. `forwardIfOwnedByAnotherProcess()`
  writes the same command and then polls for the owner's reply, which with
  `enable_coroutine` off blocks the single event loop serving every connection
  the calling worker holds; that is the cost `forwardWithoutReply()` exists to
  avoid. The owner writes no response and takes no write lease for a no-reply
  command, a handler that fails on the owner is logged rather than swallowed,
  and a command with no resolvable owner is dropped and logged per resource.
  Same signature, process addressing, freshness window and spend-once request id
  as a forwarded command.
- Probes that open real clients against a running server and fail loudly:
  `lightspeed:probe`, `lightspeed:presence-probe`, `lightspeed:relay-probe`,
  `lightspeed:load-probe`.
- `lightspeed:doctor`, which checks a setup and prints the file, env var or
  command that fixes each thing it finds, and exits non-zero when anything is
  broken. `--json` for machine output, `--fix` to print the commands without
  running them.
- `lightspeed:install`, which sets an application up for broadcasting in one
  non-interactive run. Re-running changes nothing, and an existing
  `LIGHTSPEED_APP_SECRET` is never overwritten, with or without `--force`.
- `lightspeed:stop` and `lightspeed:restart`, which find the server on its
  configured ports and stop it cleanly.
- A worked example under `example/`, installable from scratch with
  `example/install.sh`.
- Mutation testing as part of the toolchain (`composer test:mutate`), with the
  suite held above a 94% mutation score.
