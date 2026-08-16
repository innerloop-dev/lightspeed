# Build on Lightspeed

Lightspeed is a foundation on purpose. It gives you one thing done properly:
an authenticated, bidirectional, keystroke-fast socket between browsers and a
running Laravel application. It deliberately stops there. The layers above,
the frameworks, the component kits, the protocols with opinions, are left for
other packages, and this page is an open invitation to build them.

[AGENTS.md](../AGENTS.md) explains what belongs inside the package. This page
is about what belongs on top of it. If one of these ideas grabs you, open an
issue and say so; we will happily link your project from here, answer
protocol questions, and add hooks the core is missing, because a package
built on Lightspeed is the best possible proof that the foundation holds.

## A live-form frontend kit

Livewire proved Laravel developers love forms that talk to the server while
you fill them in, and it works everywhere Laravel does because it rides
plain HTTP. A persistent socket into an already-booted app is a different
transport with a different budget: a round trip costs so little you can
spend one on every keystroke. Someone should build the form kit that spends
it: validation as you type from the real server-side rules, suggestions
from the actual database, drafts that save themselves, and a submit button
that has almost nothing left to fail, because everything was already checked.

The project: a Tailwind-styled component kit (Blade, React, or Vue) where
each field quietly rides the socket. The hard part, an authenticated
per-keystroke channel to the application, is already done; the kit is the
part users see.

## Presence UI primitives

Lightspeed ships presence state: who is on the channel, joins and leaves,
whispers between clients. It does not ship the pixels. Live cursors, "three
people are viewing," avatar stacks, and "someone is typing" indicators are
rebuilt from scratch in every collaborative app, and they are subtle to get
right: throttling cursor whispers, interpolating motion, expiring the ghost
of someone who closed their laptop.

The project: a drop-in package that turns a presence channel into those
components. [Lightwave](https://innerloop.works/lightwave) built all of this
privately on the same engine; the invitation is to build it once, publicly.

## A real-time AI harness

Agent sessions are long-lived, bidirectional, and personal: exactly the
shape of thing HTTP is worst at. On Lightspeed, a browser holds one
authenticated socket while tokens stream down it, the user interrupts up
it, and every message in both directions passes per-message authorization,
so a revoked user stops mid-stream.

The project: a package that gives Laravel apps a chat and agent session
layer, streaming responses, tool-call progress, cancellation, presence of
the agent itself, on channels your `routes/channels.php` already guards.

## The ground rules

Build in your own repository, under your own name; these are your projects,
not satellites of this one. Two things make a build-on project work well:

- Drive the documented surface: the Pusher protocol, `ClientEventHandler`,
  the broadcaster, `tag()` and `revoke()`. If you need a hook that does not
  exist, ask for the hook rather than reaching into internals; that request
  is welcome exactly per [AGENTS.md](../AGENTS.md).
- Hold the same proof standard you can see in [example/](../example) and
  [AGENTS.md](../AGENTS.md). A layer that claims "validation as you type"
  should have a test where the server says no.

Building something? Open an issue titled "Building: your project" and tell
us what you are making. The interesting failures you hit are exactly the
feedback a foundation needs.
