# Arena load on a production box, 2026-09-14

A measurement of Lightspeed carrying a 30Hz authoritative simulation and its
fan-out on real hardware, run on the night of 2026-09-14. Every number below
comes from one application, the arena at
[innerloop.works/lightspeed](https://innerloop.works/lightspeed), measured on the
box that serves it.

This is a different shape of load from the fan-out probe in
[verifying.md](../verifying.md), which sends one event to every socket. Here the
server is computing a world 30 times a second per arena and sending a different
frame to every socket, while clients send input back up at 30Hz.

## What was measured, and on what

The box is the production host for innerloop.works: a Hetzner Cloud CCX33
(dedicated vCPU), 8 vCPU, 32 GB RAM, 240 GB local disk, in Hillsboro, Oregon
(us-west), with 3 TB/month outbound included, managed by Laravel Forge. Ubuntu,
nginx in front of `lightspeed:serve`, Redis local.

The application is the arena, which is the app's code, not the package's:

- an authoritative world per arena, ticking at 30Hz on the worker that owns it
- players on a presence channel, receiving snapshots at 30Hz
- spectators on a public watch channel, receiving them at 5Hz
- arenas of 5 players, capped at 6, with a ceiling of 50 arenas
- placement into an arena through a Redis Lua script
- input from a player whose socket landed on another worker forwarded with
  `OwnerCommandBus::forwardWithoutReply()`, the package change merged as
  [PR #1](https://github.com/innerloop-dev/lightspeed/pull/1) earlier the same day

The harness is the application's, not this package's:
`scripts/arena-load/swarm.mjs` in the innerloop-dev website repository. It is
pure Node websocket bots speaking the real wire protocol, with no browser in
them: session cookie, placement, Pusher handshake, presence auth, and client
events at 30Hz. Server-side figures were read from the application's own
`arena-world-metrics.log` and from `top` over the same window.

The clients are two machines, a Mac mini and a MacBook Pro, connected from Los
Angeles over a residential connection, roughly 1,500km from the Hillsboro box.
Every round-trip number below therefore includes real internet distance, not a
LAN.

## The runs

Client counts are what the harness dialled. Tick, frame and watcher rates are
what the application logged. Egress and load are the box.

### Stage 1, 2 workers: 5 players, 10 watchers

Tick 30.3Hz. Player frames 30.3Hz. Watcher frames 5.0Hz. Input round trip 96ms
p50. 2.2 Mbit/s egress. Box load 0.17.

### Stage 2, 2 workers: 50 players, 200 watchers, ramped at 25/s

8 arenas. Tick 30.3Hz. Player frames 30.4Hz. Watcher frames 5.0Hz. Input lag
118ms p50. 29 Mbit/s. Workers about 5% CPU. Box load 0.9.

### Stage 3, 2 workers: 200 players, 800 watchers, ramped at 25/s

995 of 1,000 connected. 20 arenas. Tick fell to 13.5Hz p50. Player frames 13Hz.
Input lag 2s and growing. 56 Mbit/s. Box load 1.9, with both workers pegged.

Per-tick compute was still 82µs. The simulation was not the cost. Fanning
frames to 1,000 sockets from 2 processes was.

### Stage 3 again, after raising the daemon to 6 workers

983 of 1,000 connected. 14 arenas. Tick 30Hz p50, with an 18Hz minimum on the
busiest worker at 85% CPU. Player frames 30Hz p50. Input lag 160ms p50 and 5.5s
p95. 101 Mbit/s. Box load 4.2 of 8.

### Stage 4, 6 workers: 20 players, 3,000 watchers

3,019 of 3,020 connected. Every arena at 30Hz. Watcher frames 5.0Hz p50. Players
at 30Hz with 171ms input lag. 104 Mbit/s egress. Box load 0.4. Per-tick compute
70µs.

The watchers came from two client machines because one machine caps at about
2,000 sockets, which is a per-device limit on the home router the clients sit
behind. About 4% of watcher sockets closed with code 1006 and reconnected during
the run, in the same shape from both client machines. That is attributed to the
clients' shared uplink. It is not confirmed.

## What the numbers mean

The simulation is cheap and the fan-out is what costs. Per-tick compute stayed
between 70µs and 82µs across every stage, including the stage where the tick
rate collapsed. What moved was how many sockets each worker process had to write
a frame to, which is why the same 1,000-socket load ran at 13.5Hz on 2 workers
and 30Hz on 6.

Spectators are cheap relative to players: 3,000 watchers at 5Hz left the box at
load 0.4, while 200 players at 30Hz on 2 workers pegged it. Frame rate per
socket, not socket count, is the number to size against.

For context, the package's own fan-out probe reached 10,000 sockets with 100%
delivery and p95 206ms on a laptop, on 2026-08-12. That is a single event to
every socket, not a per-socket frame at 30Hz, and the two are not comparable
except in the direction they both point.

## What load testing found

Two defects, both in the application, both found by running these stages and
fixed before the numbers above were taken:

- Placement raced under a 25/s connection burst. Fixed with atomic seat
  reservations.
- The daemon's worker count, 2 on an 8-core box, was the first wall hit.

## What this does not prove

- Nothing above 3,000 concurrent watchers was measured. The 200 Mbit/s egress
  budget projects to roughly 5,500 watchers at 5Hz, and a projection is not a
  measurement.
- A rate-adaptive spectator stream is being built. It is not in any of these
  numbers.
- Input p95 at 1,000 sockets on 6 workers was seconds, not milliseconds. On this
  box and this application, 1,000 concurrent is playable and 500 is comfortable.
