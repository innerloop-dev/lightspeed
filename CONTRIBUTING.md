# Contributing

## Running the tests

You need PHP 8.2+ with the Swoole extension, and a Redis server on localhost.

```bash
composer install
vendor/bin/pest
```

The suite talks to a real Redis rather than a fake one. Several of the things
worth testing here (atomic Lua, stream reads, key expiry, what happens when the
connection dies) only behave honestly against the real thing. Each run keys its
Redis state under its own namespace, so two runs at once do not corrupt each
other, and `REDIS_HOST` / `REDIS_PORT` point the suite at a non-default Redis.

## Mutation testing

```bash
composer test:mutate
```

Suite size says nothing about whether an assertion would notice a broken
guard; mutation testing does. It needs a coverage driver (pcov or Xdebug).
A surviving mutant in code you touched means the change needs a test, written
the way the next section describes.

## Trying it against a real server

```bash
cd example && ./install.sh
```

That builds a small Laravel app, serves it, and gives you something to point a
browser at. It is also the fastest way to find out whether a change breaks the
Pusher protocol, because Echo and pusher-js are less forgiving than any test.

## What a good change looks like

**Write the test first, and watch it fail.** Not "run the suite and see red",
but see the specific new test fail for the specific reason you expect. A test
that has never failed has never proved anything. If the test passes before your
change, either the behaviour already existed or the test is not testing it.

Deleting the guard you are about to add is a better control than deleting the
file: removing a file only proves the code was absent.

**Assume someone will try to break it.** This package terminates websockets and
decides who is allowed to send. Every round of review so far has found a way to
fail open, usually in the code that handles failure: an exception in a Redis
error handler, a cache miss read as permission, a log write inside a catch
block. When you add a failure path, ask what it does when its own dependency is
missing, and prefer refusing to allowing.

**Comments explain why, not what.** The surrounding code does this fairly
consistently. A comment saying a guard is load-bearing is not a substitute for
a test proving it; if you find yourself writing one, write the test too.

## Style

Match what is around you. There is no linter opinion to satisfy beyond that.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
