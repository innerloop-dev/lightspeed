# An example pull request

This is a real change that shipped: `ClientEvent::user()`. It is reproduced
here as its pull request would have read, because [AGENTS.md](../AGENTS.md)
demands a shape ("open with the sell, then the proof") that is easier to
copy than to imagine. Everything below is what actually happened.

---

## PR: ClientEvent::user(), hydrate the event's identity in one call

### Why this should exist

A handler that wants the sender as a model writes the same three lines in
every application: read `$event->userId`, null-check it, `User::find()` it.
Every consumer of this package will write those lines in week one, each
picking their own model class, their own null handling, and their own
wrong assumptions about private channels. The identity is already on the
event and already trusted; making the hydration a method removes the one
piece of boilerplate every handler shares, without touching the hot path
for anyone who does not call it.

Why this and not something smaller: a doc recipe was the smaller version,
and it existed. It cannot enforce memoization, cannot promise the
zero-cost-when-unused property, and cannot stop the copy that forgets the
null check on private channels. One method can promise all three.

### What changed

- `src/ClientEvents/ClientEvent.php`: a `user()` method, 50 lines with
  docblock. Lazy, memoized, resolved through the provider the default
  guard is configured with. No constructor change; construction sites are
  byte for byte untouched.
- `docs/authorization.md` and `docs/extending.md`: the recipe becomes the
  method, with its cost stated.
- `tests/Unit/ClientEventUserTest.php`: six tests, below.

### Proof

- **Watched failing**: each behavioural test was run against a broken form
  of the method, not just the missing one. Deleting the memo assignment
  fails only the memoization test. Replacing the userId guard with `true`
  fails only the zero-lookup test. Calling `user()` from the constructor
  fails only the never-asked test.
- **The claim that matters most has a counting test**: an event whose
  `user()` is never called triggers exactly zero provider lookups. That is
  what keeps "no database on the message path" true.
- **Mutation**: `pest --mutate --covered-only --path=src/ClientEvents/ClientEvent.php`
  scores 100.00%. The first run left one survivor (`RemoveNullSafeOperator`);
  it was killed with a test for an application that has no user provider
  configured, which returns null rather than dying.
- **Suite**: green before, green after, six tests larger.

### Judgment calls, surfaced

The brief expected a resolver closure injected at construction. I did not
add one, and this is the deviation you should weigh: the semantics require
reaching the application's default provider lazily, and once the method
does that, an injected closure is a duplicate mechanism that leaves every
other construction site with a dead `user()`. The cost of my choice is
that the DTO touches config and the Auth facade inside one lazy method.
The gain is that construction stays untouched and the tests prove
resolution goes through the *configured* provider rather than through
whatever closure was injected. If the maintainer prefers the injected
shape, the tests transfer.

The provider name is passed explicitly (`auth.guards.{guard}.provider`)
rather than relying on `createUserProvider(null)`, because that fallback
reads `auth.defaults.provider`, a key a stock `config/auth.php` does not
define.

---

That is the whole shape: the sell, the diff summary, the proof with its
teeth shown, and every place the agent exercised judgment, offered up
rather than discovered later. It reads in two minutes and it is refutable
at every claim, which is exactly what the first reviewer will try to do.
