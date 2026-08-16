# Agents

Lightspeed was built agentically: AI wrote the code, and a human governed a
process designed to catch it lying. Agentically coded contributions are
welcome on the same terms. This file is the playbook to give your agent
before it writes a line.

## Ground rule: direction

A contribution must push this package forward in the direction it was built
for: authenticated, bidirectional, keystroke-fast messaging between browsers
and a Laravel application, in one server. If your idea bends the package
toward something else, however good, it belongs in a fork or in a package
that builds on Lightspeed rather than in Lightspeed. That is not a
rejection; packages on top are the point of a foundation, and
[docs/build-on-lightspeed.md](docs/build-on-lightspeed.md) names the ones
we would most like to see.

For anything bigger than a focused fix, open an issue and get the design
agreed before any agent starts building. Urgency sets the queue, never the
spec: a locked design is what makes a large diff reviewable.

Welcome, as of now:

- Client-side recipes and examples: React, Vue, and mobile clients driving
  the documented protocol, with the same proof standard as `example/`.
- Grant and revocation ergonomics: anything that makes `tag()` and
  `revoke()` easier to adopt without weakening what they prove.
- Presence primitives that close real gaps, argued from a real application's
  need.
- CLI and background work on the resident server: the Swoole task workers
  and `Octane::concurrently()` plumbing already exist, and making them easy
  to drive, artisan-dispatched jobs running in the booted app instead of a
  cold process, is squarely the direction.
- Probes and integration checks that verify something no current probe
  verifies.
- Performance work with receipts: a measured before, a measured after, and
  the harness that produced both.
- Documentation that removes a real reader's real confusion.

## Before your agent writes a line

Give it these rules, and give every building agent a tripwire: the named
condition where it stops and reports instead of improvising. The failure
mode of agentic coding is not bad code, it is an agent quietly redesigning
your change to route around an obstacle it should have surfaced. These
rules are how this codebase was built, and a change that ignores them will
read as foreign no matter how well it works.

- **The model leads.** [docs/architecture.md](docs/architecture.md) names
  every concept and its one job. A change uses those names, in code and in
  prose. One word, one meaning; no synonyms drifting in.
- **The tree shouts the domain.** New code goes in the directory whose name
  says what it is. If no directory fits, that is a design conversation to
  have in an issue first, not a `Support/` folder to invent.
- **One job per file. Grow by splitting.** A concept that grows gains
  domain-named children, never a longer file.
- **Modules talk through contracts, never shared mutable state.** A class
  takes its inputs and returns its output; it does not reach into another's
  internals.
- **Search before building.** The thing you are about to write may already
  exist as a class, a config dial, or a documented behaviour. A duplicate
  mechanism is a bug even when it works.
- **Fixes land at the right layer.** No band-aids, no workaround shipped
  where the cause is reachable. If the correct fix is harder, that is the
  fix.
- **No silent judgment calls.** Every deviation from the plan, every
  fallback, every piece of code kept "just in case" gets surfaced in the
  PR, not defaulted. Insurance is the maintainer's decision to make.
- **Comments say why, never what.** And a comment claiming a guard is
  load-bearing is not a substitute for the test that proves it.

## How a change earns a merge

The bar is the one this repo was built under. Every step below was applied
to every feature already here, and each has caught real bugs:

1. **Write the test first and watch it fail**, for the exact reason you
   expect. A test that has never failed has never proved anything.
2. **Break a new check on purpose before trusting it.** If you add a
   gate or a probe, sabotage the thing it guards and watch it catch you.
3. **Prove it on the real thing.** The suite talks to a real Redis on
   purpose, and `example/install.sh` gives you a real server in a minute.
   Stand-ins prove nothing here.
4. **Mutation testing must not regress.** Run `composer test:mutate` on the
   files you touched. A surviving mutant in your change means your tests do
   not defend it; kill it or explain in the PR why it is equivalent.
5. **Attack your own work before submitting, from more than one angle.**
   Brief separate agents to refute the change, each with a different lens,
   because different lenses find different bugs: one hunts the failure
   paths (the bug is usually in the code that handles failure, so for every
   catch block and fallback, ask what it does when its own dependency is
   also missing), one asks "what if this were simply broken" (if a guard
   can be deleted with the suite still green, the test is decoration), and
   one reads the change cold, as the stranger who has to live with it.
   Tell them to refute, not to review: an agent asked to review will
   admire; an agent asked to refute will find something.
6. **Every claim in prose must be provable.** A sentence in the README or
   docs that nothing would fail on is not allowed in. Fail closed: when a
   check cannot run, the answer is no, never yes.

## The pull request

Know your reader. Your PR's first reviewer is an AI, briefed with this file
and instructed to refute the change rather than admire it. It does not get
tired, it does not get impressed, and enthusiasm in the description is
wasted on it. What survives goes to the human, and the human is worse.

Open with the sell. Before a line of the diff is read, the PR has to answer
why this should exist: what a real application cannot do today, who hits
that wall, and why this change and not a smaller one. The maintainer is
precious about what gets in; every line merged is a line owned forever, so
the default answer is no and the sell is what moves it. "It works" is not a
reason to merge; plenty of correct code does not deserve to exist in this
package.

Then the proof: what was proven and how, not just what changed. Which tests
were watched failing, what the mutation run said, what ran against a live
server, and every judgment call your agents made along the way. There is a
worked example, a real change that shipped, at
[docs/example-pull-request.md](docs/example-pull-request.md); a PR shaped
like it reads in minutes. One that arrives as a diff with vibes will be
closed with a pointer to this file.
