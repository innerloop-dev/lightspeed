<p align="center">
  <img src="logo.png" alt="Lightspeed: a Laravel and Swoole real-time server" width="420">
</p>

<p align="center">
  <a href="https://github.com/innerloop-dev/lightspeed/actions/workflows/ci.yml"><img src="https://github.com/innerloop-dev/lightspeed/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License: MIT"></a>
</p>

**Build real-time apps the Laravel way.**

Lightspeed is a Swoole-based, Pusher-compatible websocket server with authenticated bidirectional sockets, running your whole app in one server.

**Live demo: <https://innerloop.works/lightspeed>.** An asteroids-style multiplayer arena on the landing page, sharded into arenas of six, its authoritative world state ticking at 30Hz inside Lightspeed. A server you can join, rather than a screenshot: [what it measured in production](docs/reports/2026-09-16-arena-production-load.md).

Your browser sends a websocket message. Your auth runs. Your Laravel code handles it. The answer comes straight back down the same websocket, fast enough to sit behind a keystroke: [0.28ms median](docs/verifying.md#round-trip-latency-browser-to-laravel-to-browser) on a laptop, server side. The rest of the budget is your network and your handler.

It's your web server too. One server runs your whole app, HTTP and websockets on one port, just like Node. It boots once and stays in memory.

Works with Laravel Echo.

Here's a video of Lightspeed in action. Each keystroke is a round trip between Los Angeles and Oregon:

https://github.com/user-attachments/assets/a51df99d-cde5-437c-9fa1-2681b0e31fd5

Try the same engine locally with a small demo page, in about a minute. This is a
throwaway clone for the demo; the package install is further down:

```bash
git clone https://github.com/innerloop-dev/lightspeed.git
cd lightspeed/example && ./install.sh
```

## Why?

I built [Lightwave](https://innerloop.works/lightwave), a real-time
collaborative editor, and I needed a duplex websocket system that could
authorize every keystroke without touching the database. I also needed a
client's own messages to arrive in the order it sent them, which HTTP/2
multiplexing explicitly does not guarantee.

Nothing out there did that, so I wrote Lightspeed to power it and decided to
share it.

```
BEFORE                          WITH LIGHTSPEED

  browser                         browser
    │                               ║
    │  every action is a            ║  one socket, always open,
    │  separate HTTP request        ║  carrying both directions
    ▼                               ▼
┌──────────┐  ┌──────────┐      ┌────────────────────────────┐
│ php-fpm  │  │  Reverb  │      │         lightspeed         │
│    or    │  │  push ──►│      │                            │
│  Octane  │  │          │      │    HTTP  ⇄  websockets     │
└────┬─────┘  └────▲─────┘      │      your Laravel app      │
     └──broadcast───┘           └────────────────────────────┘

two servers, one direction      one server, both directions
```

## Who is this for?

Anyone who wants to build on an authenticated, bidirectional websocket layer
with Laravel. As fast as one Redis read when a message needs nothing, and the
whole framework when it does.

- Real-time AI systems (harnesses, chats, orchestrators), collaborative editors,
  whiteboards, multiplayer games, trading dashboards.
- Anything with shared state, more than one person, and **auth that runs on every
  message without touching the database**, once you tag a connection.

If your server mostly pushes events out to browsers, use Reverb. It is
first-party and simpler. Lightspeed is for applications where browsers also send
authenticated application messages into Laravel over the socket.

## Try it

The install above serves <http://localhost:8000>.

- Open two tabs. Presence fills as they join.
- Type in one. It appears in both, with your round-trip time.

Then broadcast from a process with no server in it:

```bash
cd example/hello-world && php artisan hello:broadcast "anyone there?"
```

There are two client pages on purpose. `/` is raw pusher-js, so you can watch
every frame on the wire. `/echo` is Laravel Echo, configured exactly as
[the docs](docs/configuration.md) tell you to, and running against a live
server.

## Browser to Laravel to browser

A message from the browser lands in a handler, inside the full Laravel container.

```php
namespace App\Realtime;

use Illuminate\Support\Facades\Broadcast;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;

class EchoHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        // Not mine: hand it to the next handler, then to normal peer relay.
        if ($event->event !== 'client-say') {
            return null;
        }

        $said = $event->data['message'];

        // Everyone on the channel hears it.
        Broadcast::connection()->broadcast(['presence-lobby'], 'said', [
            'message' => $said,
            'from' => $event->userId,
        ]);

        // The sender alone gets this, on the socket it asked from.
        return ClientEventResult::response(
            requestId: $event->data['requestId'],
            response: ['saved' => true],
        );
    }
}
```

`$event->userId` is the identity your channel authorization approved, not something the browser claimed.

The full round trip, browser side included: [docs/usage.md](docs/usage.md).

## Cutting someone off mid-connection

Websocket authorization runs once, when a connection subscribes, so an open
socket has no idea you changed your mind. Lightspeed gives you the second half.

Tag the connection where you already know the answer:

```php
// routes/channels.php (you are already hitting the database here)
Broadcast::channel('doc.{id}', function (User $user, string $id) {
    $doc = Document::findOrFail($id);

    if (! $user->can('view', $doc)) {
        return false;
    }

    Lightspeed::tag(["user:{$user->id}", "project:{$doc->project_id}"])
              ->with(['can_edit' => $user->can('update', $doc)]);

    return true;
});
```

Then revoke it from anywhere in your app, when access changes:

```php
Lightspeed::revoke('project:7');
```

On their next message Lightspeed checks Redis, returns `stale`, and the client
re-subscribes, which re-runs your rule. That send-side check is one Redis read,
**about 0.03ms** on loopback, never a database query, and never cached: if Redis
cannot answer, the message is refused. They cannot successfully send application
messages in the meantime.

Revoked connections are also dropped from the fan-out as soon as the relay
notification arrives, so they stop receiving. That receive-side half is best
effort, and the grant lifetime is the fallback.

All of this is opt-in. A connection you never tagged carries no grant, pays no
Redis read, and behaves exactly as it did before this feature existed.

[More, including the tradeoffs](docs/authorization.md).

## Install

PHP 8.2+ with the [Swoole](https://swoole.com) extension, Redis, a PHP Redis
client, Laravel 11 or 12.

No Swoole yet? It is a PHP extension, so Composer cannot install it for you:

```bash
pecl install swoole
```

Then enable it in the php.ini your PHP binary reads; `php --ini` prints which
one that is. Without it, `composer require` refuses at the platform check.

**1. Install the package:**

```bash
composer require innerloop-dev/lightspeed
```

You also need a PHP client for Redis, which this package does not install for
you. Pick one: `ext-redis` (faster) or `predis/predis` (no extension). If you
have neither:

```bash
composer require predis/predis
```

Without one, the first thing you see is a crash in the relay, much later. Step 3
below checks for it.

**2. Set up broadcasting:**

```bash
php artisan lightspeed:install
```

That publishes `config/broadcasting.php` and `routes/channels.php` if your app
has neither, registers the channel routes in `bootstrap/app.php`, adds the
`lightspeed` connection, and writes `BROADCAST_CONNECTION` and a generated
`LIGHTSPEED_APP_ID`, `LIGHTSPEED_APP_KEY` and `LIGHTSPEED_APP_SECRET` into your
`.env`, with placeholders in `.env.example`. It never prompts, it never
overwrites an app secret you already have, and running it twice changes
nothing. Laravel's own `install:broadcasting` cannot do this: it insists on
Reverb, Pusher or Ably, and Lightspeed is none of them.

**3. Check it:**

```bash
php artisan lightspeed:doctor
```

It checks PHP, Swoole, a PHP Redis client, every Redis connection this package uses, your credentials, your broadcast connection, your channel routes and your handlers. Anything wrong is printed with the file, env var or command that fixes it. Worth running now.

**Coming from Reverb?** Your `REVERB_APP_*` values are picked up as fallbacks. Point Echo at the new port and your clients do not change.

## Commands

```bash
php artisan lightspeed:install              # set up broadcasting; safe to re-run
php artisan lightspeed:install --force      # also refresh the app id and key
php artisan lightspeed:install --without-config  # do not publish config/lightspeed.php

php artisan lightspeed:doctor               # check the setup before you serve
php artisan lightspeed:doctor --json        # the same report, machine readable
php artisan lightspeed:doctor --fix         # print the fixes; runs nothing

php artisan lightspeed:serve --workers=4    # more worker processes
php artisan lightspeed:serve --port=8000    # bind port (HTTP and websockets)
php artisan lightspeed:serve --log-verbose  # log requests and frames
php artisan lightspeed:stop
php artisan lightspeed:restart

php artisan lightspeed:probe                # Pusher protocol round trip
php artisan lightspeed:presence-probe       # shared presence across workers
php artisan lightspeed:relay-probe          # cross-worker delivery
php artisan lightspeed:load-probe           # fan-out latency under load
```

Every command supports `--help`.

`lightspeed:doctor` exits non-zero if anything is broken, so it works in a deploy script or a health check. `--fix` only prints commands; it never runs them.

## Status

Running in production on [Lightwave](https://innerloop.works/lightwave), the collaborative editor it was extracted from.

Load tested on an M1 Pro MacBook Pro, 16GB: one event, fanned out to every connected socket.

| Connections | Delivered | p50 | p95 |
| --- | --- | --- | --- |
| 1,000 | 1,000 | 32ms | 43ms |
| 2,000 | 2,000 | 28ms | 50ms |
| 3,000 | 3,000 | 37ms | 69ms |
| 4,000 | 4,000 | 44ms | 79ms |
| 5,000 | 5,000 | 56ms | 85ms |
| 7,500 | 7,500 | 137ms | 210ms |
| 10,000 | 10,000 | 141ms | 206ms |

The run used two instances on one laptop. To reproduce it, start both on the same ports:

```bash
php artisan lightspeed:serve --port=8000 --instance-id=a
php artisan lightspeed:serve --port=8001 --instance-id=b   # second terminal
```

Then run the probe. Above roughly 4,000 probe clients, run PHP with
`memory_limit=-1`. The memory goes to the load generator holding thousands of
coroutine clients in one process. It is not a Lightspeed server requirement.

```bash
php -d memory_limit=-1 artisan lightspeed:load-probe \
    --targets=127.0.0.1:8000,127.0.0.1:8001 --connections=10000
```

Full method, the per-size table, and what to read from it: [Verifying](docs/verifying.md).

Measured again on a production box, under a different shape of load: 1,000 sockets taking a 30Hz simulation from the arena on [innerloop.works/lightspeed](https://innerloop.works/lightspeed), with input round-tripping in 256ms at p95. [The 2026-09-16 arena load report](docs/reports/2026-09-16-arena-production-load.md).

## Learn more

- [Usage](docs/usage.md): your first real-time feature, both sides of the wire
- [Architecture](docs/architecture.md): the map of the system, every concept and its one job
- [Authorization](docs/authorization.md): tagging a connection, revoking, what the client does
- [Extending](docs/extending.md): handling a message, answering it, knowing when a connection goes, owner routing
- [Configuration](docs/configuration.md): every setting, Echo, health, diagnostics
- [Verifying](docs/verifying.md): the checks, and the measured numbers: round-trip latency and fan-out
- [Production](docs/PRODUCTION.md): topology, requirements, rollout and rollback
- [Build on Lightspeed](docs/build-on-lightspeed.md): an invitation, and the projects we would love to see
- [example/](example): the hello world above, eight small files worth reading

## Submitting a pull request

Know your reader. Your PR's first reviewer is an AI, briefed with
[AGENTS.md](AGENTS.md) and instructed to refute the change rather than
admire it. It does not get tired, it does not get impressed, and enthusiasm
in the description is wasted on it. What survives goes to the human, and
the human is worse.

For the full playbook, see [AGENTS.md](AGENTS.md).

## License

[MIT](LICENSE)

## Built by

[Innerloop Software](https://innerloop.works), which also makes [Lightwave](https://innerloop.works/lightwave) and [Breadcrumb](https://innerloop.works/breadcrumb).

- Follow [@justinvincent](https://x.com/justinvincent) for updates
