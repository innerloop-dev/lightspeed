# PR: `OwnerCommandBus::forwardWithoutReply()`, a path to the owner that does not wait

Written in the shape [docs/example-pull-request.md](../example-pull-request.md)
sets out, because [AGENTS.md](../../AGENTS.md) asks for the sell before the diff
and the proof with its teeth shown.

---

## Why this should exist

`forwardIfOwnedByAnotherProcess()` is the only public way to get work to the
worker that owns a resource, and it **waits**. It writes the command to the
owner's Redis stream and then polls a response key. With `enable_coroutine` off
— which is the shipped default, and the right one for Laravel — that poll is a
`usleep()` on the single event loop that serves *every* connection the calling
worker holds. The caller does not pay for the answer with its own latency; it
pays with the whole worker.

For a mutation whose result the caller needs, that is simply the price of the
result, and the class already documents it honestly. The gap is that **there was
no way to decline the answer.** An application with a stream of commands that
needs no reply — input frames, cursor positions, telemetry, anything where the
next command matters more than the last one's receipt — had one option, and it
was the expensive one.

Who hits that wall: anyone routing per-interaction work to an owning worker at
interaction rates. It was found by building a multiplayer arena on the package,
where a client's socket lands on whichever of two workers Swoole gives it, so
roughly half of all players send every intent across the bus. At 30 intents per
second from **one** such player, that worker spent **35% of its event loop
blocked inside the forward** with the dials tuned as far as they go, and **73–85%
with package defaults**. Two players put defaults at 63–88%. Meanwhile the
*owning* worker's own 30Hz tick sagged to 22–26Hz writing response keys that
nobody would ever read. Numbers and harness below.

**Why this and not something smaller.** Three smaller things were considered:

- *Tune the dials.* They exist (`poll_interval_ms`, `wait_interval_us`,
  `write_lease`) and they were already at their useful limit for that
  measurement — 35% of a worker's loop is what the *tuned* configuration costs.
  A dial cannot remove a wait that the API requires.
- *Turn on `enable_coroutine`.* That makes the wait a yield, and it is already
  named in the timeout message as the supported escape. But it also makes the
  host application's request handling concurrent inside one process, which
  `config/lightspeed.php` refuses to recommend for good reasons. Trading the
  application's correctness for the package's latency is the wrong trade.
- *Document the pattern.* There is no pattern to document. Nothing a consumer
  can write on top of the public API writes a signed, addressed, replay-bounded
  entry to the owner's stream; the signing scheme is deliberately internal.

And why not a bigger one: no batching layer, no owner-side coalescing, no
coroutine mode. Those are all plausible and all bigger than the gap; this change
is one method and one wire field.

## What changed

- **`src/Owner/OwnerCommandBus.php`** (+205/−6 including the extractions). The
  new public `forwardWithoutReply()`; `executeLocallyWithoutReply()`;
  `reportNoReplyFailure()` and `reportNoReplyCommand()`. Two extractions that
  the two delivery paths now share rather than duplicate: `remoteOwner()` (the
  owner-claim decision) and `appendCommand()` (the signed stream write, which is
  every gate the drain checks). The drain reads `expects_reply` and skips the
  response write on an explicit `false`; `executeCommand()` skips the write
  lease for a no-reply command.
- **`src/Owner/OwnerCommand.php`** (+10): `public readonly bool $expectsReply = true`.
- **`config/lightspeed.php`** (+12): `owner_commands.no_reply_report_interval_seconds`.
- **`docs/extending.md`** (+32): a subsection beside "Routing work to the owning
  worker", including the deploy-order bound below.
- **`CHANGELOG.md`** (+21/−3).
- **`tests/Unit/OwnerCommandForwardWithoutReplyTest.php`**: new, 18 tests.

No behaviour on the synchronous path changes, and its bytes on the wire do not
change either — there is a test whose only job is to fail if they ever do.

## Proof

**Suite.** `vendor/bin/pest > /tmp/lightspeed-pest.log 2>&1; echo "exit $?"` →
`exit 0`, **1099 passed / 3611 assertions**, 18 of them new. Run against a real
Redis, as the suite does on purpose.

**Watched failing.** Each of these was broken on purpose in the implementation,
the suite run, the failure confirmed as the *expected* one, and the line
restored:

| line broken | what it became | what failed | log |
|---|---|---|---|
| drain's `if (!$expectsReply) { continue; }` | `if (false)` | *a no-reply command reaches the owner handler and writes no reply* — only that one | `/tmp/break1.log` |
| `executeCommand()`'s `\|\| !$command->expectsReply` | removed | *takes no write lease* and *still executes when leased elsewhere* — only those two | `/tmp/break2.log` |
| `forwardWithoutReply()`'s remote append | wrapped in `waitForResponse(...)` | *returns without waiting on the owner*, at **256.9ms** median against a 5ms bound (the real path measures ~0.1ms in-suite) | `/tmp/break3.log` |
| `appendCommand()`'s conditional spread | `'expects_reply' => $expectsReply` (unconditional) | *a forwarded command carries no expects_reply field at all* — only that one | `/tmp/break-bytes.log` |

The last one is the important one, and it is in this table because it was found
by review rather than by me: the unconditional form **left all 1093 tests
green**. It is correct in every single-version deployment and breaks owner
routing between two versions of the same application for the length of a rolling
deploy, because both ends of the signing scheme are Lightspeed and a process
talking to itself cannot notice that an encoding moved. The test now asserts the
*absence* of a key rather than the value of one.

**Mutation.** `vendor/bin/pest --mutate --covered-only --path=src/Owner/OwnerCommandBus.php --parallel`
— **88.57%**, 331 tested / 51 untested / 3 timeout. The first run of this branch
scored 87.61% and each round since has raised it; the absolute number is not
comparable to `main`'s, because this change adds mutable lines of its own, so
what follows is every survivor that falls on one of them. Four are equivalent
mutants rather than missing tests:

- `RemoveEarlyReturn` on `remoteOwner()`'s `!is_array($owner)` guard — both
  branches return the identical array (`?? null` suppresses the offset access on
  a non-array), so the mutant is equivalent. The guard stays for its type safety.
- `RemoveStringCast` on two `(string) Str::uuid()` calls — the values land on
  `string`-typed parameters and returns and coerce identically.
- `RemoveDoubleCast` on the report interval — `max(1.0, …)` coerces and the
  interpolated string is byte-identical either way.
- `GreaterOrEqualToGreater` on the rate-limit sweep — a wall-clock boundary at
  *exactly* the interval, which no test can hit reliably.

Six other survivors on new lines **were** missing tests and were killed: the
early return on both local branches (exactly-once dispatch), the empty and the
non-record owner-key branches, the drop note, and the rate limiter's expiry and
its configured interval.

**Proven on a real application.** See the measurement section below. This is a
consumer-app measurement, not a package benchmark.

## The measurement, and what it is not

**It is not a package benchmark.** It measures what one application's arena costs
one worker at one input rate on one laptop, with a second agent building on the
same machine throughout. It is evidence that a real application hit a real wall
and that this change moved it. No number here should be quoted as a property of
the package.

**Harness**, copied into the consumer repo so the numbers can be reproduced
rather than believed:
`scripts/arena-load/` in the innerloop website repo (the consumer) —
`measure.mjs` (headless Chrome over CDP, `Input.dispatchKeyEvent`, N players
placed on the *non-owning* worker), `report.py` (reads the windows out of the
server's own logs), `fly.mjs` (the original driver it grew from), and a README
with the reproduction steps and the two traps that silently produce a plausible
wrong number. Instrumentation is the application's own
`storage/logs/arena-input.log` (per worker per second: `pid`, `forwards`,
`medianUs`, `loopBlockedPct` — the routing call timed with `hrtime(true)` either
side) and `arena-metrics.log` (the owning worker's achieved tick `hz`,
`localSockets`, per-tick `computeUs`). No measurement is read out of the browser.

30-second windows, `.env` in the **tuned** configuration (`poll_interval_ms=5`,
`wait_interval_us=2000`, `write_lease=false`) — i.e. the most favourable case
for the path being replaced:

| | intents/s | median forward | loop blocked % |
|---|---|---|---|
| forward, 1 player | 31 | **10.52 ms** | **35.1** (26–88) |
| forward, 2 connected / 1 flying | 32 | **12.50 ms** | **49.5** (35–80) |
| no reply, 1 player | 31 | **1.33 ms** | **9.9** (3.5–24) |
| no reply, 1 player (repeat) | 31 | **1.16 ms** | **12.1** (6.4–25) |
| no reply, 2 players | **62** | **1.02 ms** | **19.9** (4.8–67) |

Per intent, **10.5ms → ~1.1ms**. At *double* the rate, the no-reply path blocks
the worker less than half as much as the forward did at half the rate. Redis
confirms the mechanism independently: zero `lightspeed:owner-command-response:*`
keys exist while the stream runs.

Two things the measurement does **not** establish, stated because they were
expected to and did not:

- **No clean two-player before.** Chrome throttles hidden tabs to ~1Hz, so the
  first two-player runs were silently measuring one flying player. By the time
  that was found and fixed, the application had been restarted onto the new path
  and the old one was no longer reachable. The 49.5% row is two connected, one
  flying. The 63–88% two-player figure is from the original gate, at package
  defaults.
- **The owner's tick is unresolved.** It did not improve and was slightly worse
  (median 28.5–29.0 after against 29.2–30.3 before), with per-tick compute p95
  rising 61µs → 215µs. Load average on the machine was **69** during the after
  runs. That is the likeliest explanation and it is not proof of anything; a
  repeat on a quiet machine would settle it in one 30-second window. It is
  recorded here as unresolved rather than as a win.

## Judgment calls, surfaced

**No write lease for a no-reply command.** The lease exists to be able to
*refuse*, and every other caller on this bus gets that refusal back as a
structured failure it can retry. `forwardWithoutReply()` has already returned, so
a refusal would be a command dropped in silence, at exactly the rate the caller
chose this path for. The ordering a caller gets is therefore the owner's own
single-threaded drain and nothing stronger, said plainly in the docs. If a
command needs the lease, it needs a reply.

**No reply written.** The response key is the *failure report* for a forwarded
command. Writing one for a no-reply command would put a short-lived Redis key
behind every entry of a stream chosen for its volume, with no reader to delete
any of them.

**Drop on unresolvable owner.** Resolving the owner is `claimOwner()`, which
*claims* an unowned resource, so an unowned resource makes the caller the owner
and runs locally. A null means Redis could neither grant the claim nor say who
holds it. With no caller to fail, the command is dropped and logged. Its report
does **not** reuse `reportRefusal()`: that dedupes on a reason and keeps it for
the life of the worker, which would let one arena going dark hide behind
another's line an hour earlier. It has its own headline (the bus refused
nothing — it could not deliver) and is rate-limited per resource.

**A failing handler is logged, not swallowed.** Raised in review: a no-reply
command whose handler threw vanished with no trace. It now reports, rate-limited
per resource and command.

**Local and remote failures are symmetric.** Not asked for, and the largest
judgment call here. The local branch now runs through `executeCommand()` (so any
future gate applies to both paths — behaviour is otherwise unchanged, as the
lease is already skipped) and **catches**. Without the catch, the same failing
handler is silent on one worker and thrown at the caller on the other, decided by
which worker Swoole gave the socket to, and an application cannot write code
against that. The cost is that a local handler's exception no longer surfaces to
the caller; it surfaces to the log instead, which is where the remote one already
went.

**It can still throw.** With Redis unreachable, the ownership lookup or the
stream write raises and that reaches the caller, exactly as on the forwarding
path. The docs said "no error"; that was wrong and is corrected.

**The deploy-order bound.** `expects_reply` is written only when false, so a
forwarded command's bytes are unchanged. But an **old** worker draining a **new**
no-reply entry does not know the field: it executes the command correctly and
then treats it as forwarded — writing a response key nobody reads (expiring on
its own after `response_ttl_seconds`) and taking the write lease. **If the lease
is contended, that command is refused and the refusal goes to a caller that has
already gone, so it is dropped silently**, for the length of the mixed-version
window. Documented as a bound with the ordering that avoids it: deploy the
package and restart every worker before shipping code that calls the new method.

**Naming.** The first cut was `send()`. Review pointed out that `send` already
means "push a frame to a socket" in `DiagnosticProtocol::send`, and that the
sibling method is `forwardIfOwnedByAnotherProcess()`. Renamed to
`forwardWithoutReply()` before merge, on the maintainer's call, and the
vocabulary follows it: a command delivered this way is a *no-reply command*
throughout the code, the tests and the docs.
