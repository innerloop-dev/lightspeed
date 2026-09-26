# PR: Laravel 13

Written in the shape [docs/example-pull-request.md](../example-pull-request.md)
sets out, because [AGENTS.md](../../AGENTS.md) asks for the sell before the diff
and the proof with its teeth shown.

---

## Why this should exist

Laravel 13 is the current release. A developer who runs `laravel new` today and
follows this README is refused at step 1: `composer.json` says
`laravel/framework: ^11.0|^12.0`, so Composer will not install the package.

Widening that one constraint is not enough, and that is what makes this more
than a version bump. **On Laravel 13, Lightspeed took the host application
down.** Laravel 13's `BroadcastManager::extend()` rebinds the driver closure's
`$this` to the broadcast manager (`RebindsCallbacksToSelf`). Laravel 11.56.1 and
12.69.2 store the closure untouched. The `lightspeed` driver closure called
`$this->pusherClient()`, meaning the service provider. On 13 that call lands on
the manager, falls through its `__call` to `driver()`, which resolves the
`lightspeed` driver, which runs the same closure again, until memory runs out.

What that looked like in a fresh Laravel 13.33.0 app, installed from this branch
with the constraint widened and without the fix:

- `php artisan lightspeed:install` succeeds (exit 0).
- From then on, **every artisan command dies**, `php artisan --version` included:
  exit 255, `Allowed memory size ... exhausted`. With Homebrew's default ini it
  prints nothing at all. `lightspeed:doctor` and `lightspeed:serve` die the same
  way, so the CI live-server job could not have reached its first probe.
- A web request (PHP's built-in server on `public/`) gets no response: the
  connection closes with nothing sent. With the fix, the same request is a 200.

`routes/channels.php` calls `Broadcast::channel()`, which resolves the driver at
boot, so once `lightspeed:install` has written that file nothing escapes it. A
user who forced the install past the constraint would have had an application
that could not start, and no message saying why.

**Why this and not something smaller.** The constraint alone is the smaller
change, and it is exactly what the finding above proves wrong. **Why not
bigger.** No refactor of the provider and no new class for the Pusher client.
The closure now captures the provider's method before the closure is handed to
Laravel. That is the one thing that had to change.

## What changed

- **`composer.json`**: `laravel/framework` `^11.0|^12.0|^13.0`; dev
  `orchestra/testbench` `^9.0|^10.0|^11.0` (testbench 11 is the Laravel 13
  line). `php: ^8.2` unchanged.
- **`.github/workflows/ci.yml`**: both jobs have a Laravel dimension and both
  gain 13. The unit job's matrix adds Laravel 13 with testbench `^11.0` and
  excludes PHP 8.2 x 13, with a comment saying why. The live-server job (fresh
  app, every probe) adds 13. Its "allow the EOL framework" step now runs for
  Laravel 11 only, the way the unit job's already did, so Composer's advisory
  policy stays on for 12 and 13 (proof below).
- **`src/LightspeedServiceProvider.php`** (+11/-2): `boot()` captures
  `$pusherClient = $this->pusherClient(...)` outside the closure, and the
  closure `use`s it. A comment says why.
- **`example/install.sh`**: `create-project laravel/laravel` takes the package's
  framework range instead of pinning `11.*`.
- **`README.md`**, **`docs/verifying.md`**, **`CHANGELOG.md`** (Unreleased,
  under Added): Laravel 11, 12 or 13.
- **Comments only**, in `src/Console/Commands/LightspeedInstall.php` (three
  docblocks) and `tests/Unit/InstMutInstallCommandMutationTest.php` (one): they
  listed the skeletons as "11 or 12". Each was checked against a fresh 13.33.0
  skeleton before it was reworded: no `config/broadcasting.php`, no
  `routes/channels.php`, no `BroadcastServiceProvider`, `config/app.php`
  present, and `Env::writeVariable` exists, so the branch that bypasses the
  Laravel 11 fallback always wins on 12 and 13.

Commits, in order: `3464c4b` constraint and CI, `0f7a4a5` the fix, `5d26195`
install.sh, `17d8ff1` README and verifying, `2febd69` changelog, `70be7df` CI
advisory gate, `75b039d` comments, `a84d415` README and changelog wording. The
constraint went in first and without the fix, so the failure below is the
failure of a committed state.

## Proof

All of it on one laptop (Apple silicon), against the real local Redis
(`redis-cli ping` returns `PONG`). Laravel 13 ran on PHP 8.3.20; Laravel 11 and
12 on PHP 8.2.29. Each suite ran on its own, never two at once, with the full log
written to a file and the exit code read directly, never through a pipe.
Dependencies were resolved the way CI resolves them: pin testbench with
`composer require --dev --no-update`, then `composer update`.

**Watched failing, each time for the expected reason.**

| state | command | result |
|---|---|---|
| `main`, testbench pinned `^11.0` | `composer update` | **exit 2**: every `orchestra/testbench` 11.x requires `laravel/framework ^13`, which conflicts with the root's `^11.0\|^12.0` |
| `3464c4b` (constraint and CI, no fix), Laravel 13.33.0 | `vendor/bin/pest` | **exit 255** at test 360: `Allowed memory size of 536870912 bytes exhausted` |
| same, fresh Laravel 13 app after `lightspeed:install` | `php artisan --version` | **exit 255**, memory exhausted |
| same app, `display_errors=stderr` | `artisan --version`, `lightspeed:doctor`, `lightspeed:serve` | **exit 139** each |

No new test was needed to catch this. Run file by file on 13 without the fix,
exactly four of 99 files fail, and all four fail from this recursion:

- `DocMutDoctorChecksMutationTest`, `DoctorCommandTest` and
  `MisconfiguredCredentialsTest` run out of memory.
- `CoreMutServiceProviderMutationTest` fails five tests on
  `NullBroadcaster::pusherClient()`. That is the same rebinding, landing on a
  different default driver.

A stack sample taken as memory passed 200MB shows the loop directly:
`LightspeedServiceProvider.php:113` → `BroadcastManager::__call` → `driver()` →
`callCustomCreator` → the closure, over and over.

**Suite, at the final commit.**

| Laravel | PHP | testbench | result |
|---|---|---|---|
| 13.33.0 | 8.3.20 | 11.3.0 | exit 0, **1103 passed** (3626 assertions) |
| 12.69.2 | 8.2.29 | 10.x | exit 0, **1103 passed** (3626 assertions) |
| 11.56.1 | 8.2.29 | 9.x | exit 0, **1088 passed, 15 skipped** (3609 assertions) |

The 15 skips on 11 are all in `EnvWriterFallbackParityTest`, skipped by design:
its oracle is `Env::writeVariable`, which Laravel 11 does not have.

**Sabotage.** Reverting only the fix, with everything else in place:

- On **13** the suite fails. That is the watched-failing run above, on the
  committed constraint.
- On **12** the suite stays **green**: 1103 passed, exit 0. That is expected,
  and it is a real limit. Laravel 12 does not rebind the closure, so a test on 12
  has nothing to see. **The only thing that defends this fix is the Laravel 13
  cells of the CI matrix.** Remove them and the fix is undefended.
- It follows that a local `composer test:mutate` in a checkout whose `vendor/` is
  Laravel 11 or 12 never exercises the fix. The mutation runs below were made
  with Laravel 13 installed.

**Mutation.** `composer test:mutate -- --path=src/LightspeedServiceProvider.php`
on Laravel 13, with pcov. The final commit scores 21 untested, 17 timeout,
7 tested (53.33%). An earlier run of the same code scored 60.00%. The score is
not the finding. What matters is which mutants survive and why.

One survivor is on a line this change touched: `RemoveMethodCall` on line 117,
which deletes the whole `Broadcast::extend(...)` call. **It is a false
survivor, and I proved it by hand.** With that call deleted, the real suite
fails: 21 failed, 1082 passed, exit 2. Re-running the one mutant alone
(`--id=af23b00eea7fb9cb`) still reports it untested, so I captured the command
Pest runs for it:

- Pest runs only the tests that cover the mutated line, as a
  `--filter="a|b|..."` regex. This line runs in every test's boot, so the regex
  has 1076 alternatives and is 110KB long.
- Run on the unmutated code, that exact command says `No tests found.` and exits
  0. Pest counts an exit 0 as the mutant escaping.

So any mutant on a line that every test boots through cannot be killed by this
tool, however good the tests are.

The other survivors are all on code this change did not touch:

- `register()`'s `mergeConfigFrom` and `singleton()` calls.
- `boot()`'s `publishes` and `commands` calls, and the items in the command list.
- `pusherClient()`'s three string casts.

Every run leaves the same kinds of survivors, but which exact lines survive and
which time out changes from run to run. On Laravel 12, the same command before
and after the fix left an identical set (16 and 17 untested). The `register()`
and `boot()` survivors are very likely the same oversized-filter artifact, but I
proved that only for line 117. Killing any of them means work on code this PR
does not touch, so they are left alone rather than stuffed in here.

**Laravel 13 live server, every probe CI runs.**

- A fresh `laravel/laravel` 13.33.0 skeleton, with Lightspeed installed from this
  branch the way the CI job installs it: a `vcs` repository at the checkout, then
  `composer require innerloop-dev/lightspeed:dev-laravel-13`. It installed at
  `0f7a4a5`.
- Then the CI job's own steps, copied from `ci.yml` and run in order:
  - credentials into `.env`;
  - `lightspeed:install`, with all five of its file checks;
  - both application handlers registered;
  - `lightspeed:doctor`: **all 21 checks passed**, exit 0;
  - `lightspeed:serve --workers=2 --relay=1`.
- Every probe then exited 0:
  - channel authorization cannot be bypassed;
  - subscription authorization is enforced;
  - Laravel broadcasts reach a subscribed socket;
  - a handler answers a client event on the same socket;
  - an application handler is told a connection closed;
  - `lightspeed:probe`, `lightspeed:presence-probe`, and
    `lightspeed:load-probe --connections=100`;
  - `lightspeed:stop --force`, then a restart with diagnostics enabled;
  - the diagnostic protocol is reachable exactly where it was enabled;
  - `lightspeed:relay-probe --clients=24`.

**A real browser on Laravel 13, through `example/install.sh`.**

- On PHP 8.3, `example/install.sh` ran end to end, `npm install` and
  `npm run build` included, with Node v20.19.6.
- It built the hello world on Laravel 13.33.0 (Vite 8.3.1,
  laravel-vite-plugin 3.2.0) and served it.
- The 13 skeleton's `resources/js/app.js` is only `//`, with no
  `import './bootstrap'`. The script appends `import './echo'`, and that is all
  the `/echo` page needs. Vite 8's Node requirement (`^20.19.0 || >=22.12.0`) is
  the same as Vite 7's, so nothing about Node changes.

Two headless Chromium sessions ran the check: two browser contexts, so two
people, as the example README says. They opened `/`, the raw pusher-js page, and
then `/echo`, which is real Laravel Echo from the Vite bundle. On each page:

- presence showed 2 members in both sessions;
- session A typed a message and pressed Enter; `client-say` went up the socket,
  `EchoHandler` answered, and A showed its round trip (8ms on `/`, 4ms on
  `/echo`);
- the handler's broadcast showed the message in **both** sessions, with A's
  marked "(you)";
- B showed no round trip of its own, because the reply goes only to the socket
  that asked;
- `php artisan hello:broadcast`, run from a separate process, reached both
  sessions.

That browser check was broken on purpose before it was trusted:

- With `EchoHandler` removed from `client_event_handlers` and the server
  restarted, it failed on the round trip (exit 1).
- With the handler restored, it passed again.

**`example/install.sh` on PHP 8.2** also ran end to end. It built on Laravel
12.69.2 (Vite 7.3.6), same Node, and the same browser check on `/` exited 0.

**The advisory policy, checked with a current Composer (2.10.3).** It ran with a
clean `COMPOSER_HOME`, so the policy was at its default, on.

- `create-project laravel/laravel "11.*"`: **exit 2**, blocked by security
  advisories. That run is the control: it shows the policy really was on.
- `"12.*"` and `"13.*"`: exit 0, "No security vulnerability advisories found".
- Installing this branch into each of those two skeletons: exit 0.

So the live-server job's opt-out is needed for 11 only, and it is now gated to
11.

**PHP 8.2 x Laravel 13 cannot resolve.** With `composer.json` at the final
commit and testbench pinned `^11.0`, `composer update` on PHP 8.2.29 exits 2:
`orchestra/testbench[v11.0.0, ..., v11.3.0] require php ^8.3`. That is the
claim the CI exclusion comment makes.

## Judgment calls, surfaced

**PHP 8.2 x Laravel 13 is excluded from CI, and the package floor stays
`^8.2`.**
- Laravel 13 requires PHP `^8.3`, so that cell cannot resolve (proven above). It
  would fail in Composer before a test ran and prove nothing.
- The floor stays `^8.2` because it is still true on 11 and 12, and those lines'
  8.2 cells keep it true.

**The README command reaches Laravel 13 only after the next release.**
- `composer require innerloop-dev/lightspeed` installs 0.1.0 from Packagist, and
  0.1.0's constraint refuses Laravel 13. Nothing in this PR tags a release.
- So every Laravel 13 proof above installed from this branch: a `vcs`
  repository in the live-server run, and in `install.sh` the `path` repository
  it already uses.
- The README says "Lightspeed 0.1.0 predates it: on 13, use a later release".
  That wording stays true after the next release without naming a version number
  the maintainer has not chosen.
- Until a release is tagged, a Laravel 13 user following the README is still
  refused at step 1. Tagging is the maintainer's call.

**The fix is in the changelog's Added entry, not under Fixed.** No released
version could hit the recursion, because 0.1.0 cannot install on 13. A Fixed
entry would tell 0.1.0 users they had a bug they never had.

**The only behaviour change in `src/` is the capture.**
- `pusherClient()` still reads config when the broadcaster is resolved, not at
  boot. What is captured is the method, not its result.
- So a config change between boot and resolve still takes effect. The credential
  tests depend on that, and they pass.
- The other `src/` edits are comments.

**No test was added for the fix.**
- Laravel 12 cannot catch a revert of the fix, as shown above.
- A test that simulates 13's rebinding on 12 would test a fake of the framework.
  The Laravel 13 CI cells test the real one.

**`example/install.sh` takes a range, not one pinned line.**
- It used to pin `11.*`, with no recorded reason. Pinning `13.*` would have
  broken the example on PHP 8.2, which its preflight still accepts.
- `create-project` picks the newest skeleton the running PHP can install. Proven
  above: 13.33.0 on PHP 8.3, 12.69.2 on PHP 8.2.
- The cost: the range now appears twice, in `composer.json` and in `install.sh`,
  and nothing keeps the two in step. The script could read the range out of
  `composer.json`, but that is more code in a demo script to remove a
  duplication that changes once per Laravel major, so I left it written out.
- A future Laravel 14 falls outside the range, so the script will not pick it
  until someone widens the range.
- **Nothing in CI runs `install.sh`.** Both runs above were by hand. Adding it
  to CI is out of scope here and left as a known gap.

**What review found, and what became of it.** Three agents were told to
refute this change, each from a different angle. From what they found:

- **Fixed:**
  - The live-server job's advisory opt-out, now gated to 11 (proof above).
  - The stale "11 or 12" comments, including a CI comment that still called 11
    "half" of a three-line matrix.
  - The README wording.
  - The Fixed entry, folded into Added.
  - The provider comment, which first claimed every request died without saying
    when. It now says the crash follows `routes/channels.php` resolving the
    broadcaster at boot.
- **Proven rather than changed:**
  - The 8.2 x 13 claim.
  - `install.sh` on both skeletons and on the current Node.
  - The crash after `lightspeed:install` (the logs above).
- **Checked and found sound:**
  - No other closure in `src/` is handed to a Laravel 13 manager that rebinds.
    Broadcast, Cache, Redis, Auth, Filesystem and Log all do, and `src/`
    registers exactly one, the one fixed here.
  - `PusherBroadcaster` and `Env::writeVariable` are identical between 12 and
    13.
  - The CI matrix `include`/`exclude` semantics give exactly 8 unit cells.

**What I installed on the machine to run Laravel 13.**
- The default `php` is 8.2, and Homebrew's PHP 8.3 had no Swoole or Redis
  extension.
- Installed into PHP 8.3.20 only, each through 8.3's own `pecl`:
  - `swoole-6.2.0`, built with sockets, openssl@3, curl, c-ares and brotli to
    match the 8.2 build. It needed `CPPFLAGS=-I/opt/homebrew/opt/pcre2/include`
    to find Homebrew's PCRE headers.
  - `redis-6.2.0`, because the suite talks to phpredis directly.
  - `pcov-1.0.12`, for the mutation runs.
- `pecl` added three `extension=` lines to PHP 8.3's own `php.ini`.
- The shell's `PHPRC` points every PHP at 8.2's ini, so 8.3 ran through a
  wrapper that sets `PHPRC` to 8.3's ini for each command.
- For the advisory check, a verified `composer.phar` 2.10.3 in a scratch
  directory, with its own `COMPOSER_HOME`.
- PHP 8.2, the default `php`, the global Composer 2.5.1 and its global config
  were not touched.

**A server I did not start.** Three `lightspeed:serve --port=8010` processes
were already running on the machine before this work began, using the same local
Redis. I left them alone. Every suite run above passed with them running.
