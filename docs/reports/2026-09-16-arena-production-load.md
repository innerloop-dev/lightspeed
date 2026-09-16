# Arena load on a production box, 2026-09-16

A measurement of Lightspeed carrying a 30Hz authoritative simulation and its
fan-out on real hardware, run on the afternoon of 2026-09-16, Pacific time. Every number below
comes from one application, the arena at
[innerloop.works/lightspeed](https://innerloop.works/lightspeed), measured on
the box that serves it and at the machine that dialled it.

This is a different shape of load from the fan-out probe in
[verifying.md](../verifying.md), which sends one event to every socket. Here the
server is computing a world 30 times a second per arena and sending a different
frame to every socket, while players send input back up at 30Hz.

The finding is that a thousand concurrent sockets, 200 of them flying and 800 of
them watching, held a 30Hz simulation across 35 arenas on one 8-core box, behind
Cloudflare, with input round-tripping from Los Angeles to Oregon and back in
123 ms at p50 and 256 ms at p95. Input lag is the number that decides whether
the game feels alive, and at this load it stayed in milliseconds.

It stays there because of the input policy the server holds to. Each player's
input stream is stepped exactly one intent per tick and no more. A tick that
arrives starved takes its step on trust and records it. The late intents that
follow are absorbed, keeping their state and sequence number without stepping
the world a second time. An emptied queue after an absorb holds for one tick, so
the acknowledgement cannot run ahead of the page. There is nothing for a backlog
to grow on, so late input is spent rather than queued, and input lag under load
is bounded by the network rather than by the server's own arrears.

## What was measured, and on what

The box is the production host for innerloop.works: a Hetzner Cloud CCX33 with
dedicated vCPU, 8 vCPU, 32 GB RAM, 240 GB local disk, in Hillsboro, Oregon, with
3 TB a month of outbound included, managed by Laravel Forge. Ubuntu, nginx in
front of `lightspeed:serve`, Redis local. The daemon ran 6 workers.

Cloudflare sits in front of nginx, and `/lightspeed` is a cacheable page shell
that is the same bytes for every visitor, with the guest identity fetched
afterwards in one small call.

The application is the arena, which is the app's code, not the package's:

- an authoritative world per arena, ticking at 30Hz on the worker that owns it
- players on a presence channel, receiving snapshots at 30Hz
- spectators on a public watch channel, receiving them at 5Hz
- arenas capped at six players, filled to five, with a ceiling of 50 arenas
- placement into an arena through a Redis Lua script
- input from a player whose socket landed on another worker forwarded with
  `OwnerCommandBus::forwardWithoutReply()`

The harness is the application's, not this package's:
`scripts/arena-load/swarm.mjs` in the innerloop-dev website repository. It is
pure Node websocket bots speaking the real wire protocol with no browser in
them: identity, placement, Pusher handshake, presence auth, and client events at
30Hz. Server-side figures were read on the box over the same window: per-arena
tick rate from the application's own `arena-world-metrics.log`, plus load
average, memory and per-process RSS.

The client is one Mac in Los Angeles on a residential connection, roughly
1,500km from the Hillsboro box, running the whole swarm in a single Node
process. Every round-trip number below therefore includes real internet
distance, not a LAN.

## Getting a thousand bots past the rate limits

Worth stating first, because a run reproduced without it will not reach a
thousand bots and the reason will not be obvious.

The arena's HTTP endpoints are rate limited per address: placement 120 a minute,
seat reservation 60, the watch ping 60, channel auth 120, identity 120. A
thousand bots leaving one machine share one address, so the first attempt today
capped at about a hundred connected bots with the rest refused, and the box
barely noticed.

| first attempt, no key | value |
|---|---|
| bots connected | about 100 of 1,000 |
| requests refused | 982 |
| box load average | 0.3 |

The fix is not to lift the limits. `swarm.mjs` now reads a shared secret from
its own environment, `ARENA_SWARM_KEY`, which lives only in the Forge
environment, and sends it with a per-bot id on every request. The server counts
those requests against the bot id instead of the address, at exactly the
allowance a real visitor gets. The guards stay under test along with everything
else. The beacon endpoint is deliberately excluded and stays keyed by address.

With the key, a 200-bot warm-up connected 200 of 200 with no refusals, and the
full run below connected 1,000 of 1,000.

## The run: 200 players and 800 watchers, through Cloudflare

Ramped at 25 bots a second, held for 120 seconds, against
`https://innerloop.works`, 6 workers. Client counts and frame rates are what the
bots saw. Tick rate, compute, load and memory are the box.

| measure | value |
|---|---|
| connected | 1,000 of 1,000 |
| arenas ticking | 35, at most six players each |
| server tick Hz, all arenas | p50 30.2, p95 30.5, min 24.0 |
| per-tick compute | p50 148 µs, p95 231 µs |
| player frames at the bots | p50 28.4 Hz, p5 25.6 Hz |
| input lag | p50 3 intents / 123 ms, p95 7 intents / 256 ms |
| watcher frames | p50 4.6 Hz of a 5.0 Hz target |
| ingress at the client | 134 Mbit/s |
| box load average, peak | 3.84 of 8 cores |
| box memory used | 2.3 GB of 31 GB |
| errors | none |

## Cloudflare in front added nothing measurable

Cloudflare is in front of the origin and the run above went through it.

A single bot's input lag through the proxy was 123 ms p50, matching the direct
number taken an hour earlier the same afternoon. That is one bot, not a
distribution, but it is the same number on both sides of the proxy.

The visitor's real address is restored at the origin, which matters for
correctness rather than for speed: without it every visitor would share one
address and the per-address rate limits above would be a single global counter
that the first few dozen placements would exhaust.

## Bullet density, on a laptop, not production

Not a load number. This is game tuning done with the load tooling, measured
locally with five bots holding fire, and included because the tooling produced
it.

Cutting a bullet's life from 1.8 s to 1.1 s roughly halves what is on screen and
what it kills.

| measure | before | after |
|---|---|---|
| live player bullets, p50 | 46 | 28 |
| deaths a minute | 51 | 27 |

## What this does not prove

- Nothing above 1,000 concurrent was measured. The box had headroom left at
  that load, and headroom is not a measurement.
- A rate-adaptive spectator stream is still being built. It is not in any of
  these numbers.
- The five percent gap between the server's tick rate (30.2) and the frame rate
  the bots saw (28.4) is most likely the client: one Node process taking
  134 Mbit/s of frames and timestamping them. That is the likeliest explanation
  and it is unproven. It was not isolated by splitting the swarm across two
  machines.
- The Cloudflare comparison is one bot against one bot, an hour apart. It is
  enough to say the proxy did not obviously cost anything and not enough to put
  a bound on what it costs.
- The worker CPU figures in the server-side capture were sampled after the run
  had ended and are not run-time CPU. The load average peak of 3.84 is the
  run-time number.
- The bullet numbers are a laptop with five firing bots, not production and not
  people.
