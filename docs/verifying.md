# Verifying and benchmarking

## Measured behaviour

One event, published once through the Pusher HTTP API, delivered to every subscribed socket across a 2-instance cluster (loopback, Redis relay and shared presence on, August 2026).

This is a **different run** from the one in [PRODUCTION.md](PRODUCTION.md), on a differently loaded laptop, which is why the two pages report different latencies for the same 1,000-connection topology: 32/43ms here, 17-30/27-42ms there. Neither is more correct. That spread *is* the measurement, and it is why the delivery column is the one to read.

| Connections | Delivered | Split across instances | Fan-out p50 | Fan-out p95 |
| --- | --- | --- | --- | --- |
| 1,000 | 1000/1000 | 500 / 500 | 32ms | 43ms |
| 2,000 | 2000/2000 | 1000 / 1000 | 28ms | 50ms |
| 3,000 | 3000/3000 | 1500 / 1500 | 37ms | 69ms |
| 4,000 | 4000/4000 | 2000 / 2000 | 44ms | 79ms |
| 5,000 | 5000/5000 | 2500 / 2500 | 56ms | 85ms |
| 7,500 | 7500/7500 | 3750 / 3750 | 137ms | 210ms |
| 10,000 | 10000/10000 | 5000 / 5000 | 141ms | 206ms |

**Read the delivery column, not the latency column.** Complete delivery with an even split is the point: every socket on both instances received the event, at every size, every run. One laptop ran both server instances and faked every client at once, so it was fighting itself for CPU. Real hardware should do better. Each figure is one socket's own publish-to-receipt time, measured in its own coroutine.

Above roughly 4,000 connections the load generator needs `php -d memory_limit=-1`. That is a limit of the test client holding 10,000 coroutine clients in one process, not of the server.

To reproduce, start two instances on the ports the run used:

```bash
php artisan lightspeed:serve --port=8000 --instance-id=a
php artisan lightspeed:serve --port=8001 --instance-id=b   # second terminal
```

then point the probe at both:

```bash
php -d memory_limit=-1 artisan lightspeed:load-probe \
    --targets=127.0.0.1:8000,127.0.0.1:8001 --connections=10000
```

`--targets` is a list of **bind** addresses, not the public address clients dial: the probe connects to the server directly, one target per instance, and splits the connections evenly between them.

## HTTP throughput

Lightspeed serves your application's HTTP as well as its websockets, through persistent Octane workers, so the framework is already booted when a request arrives.

Measured on a laptop (Apple silicon, PHP 8.2, 2 workers) against Laravel's own `/up` route, so the whole HTTP kernel runs: **2,801 requests/second, 7.1ms mean**.

That figure stands alone; nothing else has been measured here to compare it against.

```sh
ab -n 2000 -c 20 http://127.0.0.1:8000/up
```

The claim worth making is the mechanism, not a multiple: there is no per-request framework bootstrap, and concurrency comes from workers rather than a process forked per request. Benchmark your own application before quoting any of this.

## Round-trip latency: browser to Laravel to browser

The other direction, and the one the README's "fast enough to sit behind a keystroke" is about. A client event goes up the socket, the gate runs, the handler runs inside the booted application, and the reply arrives back on the same socket. Measured on a laptop (Apple silicon, PHP 8.2, Swoole 6.2, 2 workers, real Redis on loopback), 500 sequential round trips on one connection:

**p50 0.28ms, p95 0.6ms or better.** Four runs of 500, reported as the range rather than the best of them: p50 0.27-0.28ms every time, p95 0.37-0.60ms, min 0.25ms. The median is the stable number here.

**What that number is and is not.** It is the server-side round trip and nothing else: the client is on loopback, so no network is in it, and the handler answers from its arguments, so no database or application work is in it either. A real keystroke adds your network's round trip (the video at the top of the README is a real one, Los Angeles to Oregon) and whatever your handler does. A connection carrying a grant adds the per-message Redis read, about 0.03ms on loopback, measured separately in [authorization](authorization.md).

It is one connection sending the next event only after the last has answered, which is the shape a person typing produces. It is not a throughput measurement: for many clients at once, read the fan-out table above.

To reproduce it, run the [hello world example](../example), which gives you a server on a live Redis in about a minute. Add the handler the check needs (this is the same one CI writes into its skeleton app):

```php
// app/Realtime/RoundTripHandler.php
namespace App\Realtime;

use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;

class RoundTripHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        if ($event->event !== 'client-round-trip-check') {
            return null;
        }

        $data = (array) $event->data;

        return ClientEventResult::response(
            requestId: (string) $data['requestId'],
            response: ['echoed' => $data['say'] ?? null, 'userId' => $event->userId],
        );
    }
}
```

register it in `config/lightspeed.php` under `client_event_handlers`, restart the server, and run the check with a sample size:

```sh
php vendor/innerloop-dev/lightspeed/tests/integration/client-event-round-trip.php 127.0.0.1 8000 --round-trips=500
```

The check fails if any reply is wrong, including the identity the handler saw, so a number here means 500 correct replies.

## The probes and checks

Four probes ship with the package. Each one runs real clients against a live server and fails loudly:

```sh
# Pusher-protocol round trip: connect, subscribe (public/private/presence),
# client events, member added/removed
php artisan lightspeed:probe --connect-host=127.0.0.1 --port=8000 --tls=0

# Cross-worker delivery through the Redis relay
# (needs LIGHTSPEED_DIAGNOSTICS_ENABLED=true; see configuration.md)
php artisan lightspeed:relay-probe --connect-host=127.0.0.1 --port=8000 --tls=0

# Shared presence correctness across workers
php artisan lightspeed:presence-probe --connect-host=127.0.0.1 --port=8000 --tls=0

# Fan-out latency under load, publishing via the Pusher HTTP API
php artisan lightspeed:load-probe --targets=127.0.0.1:8000 --connections=100
```

Five more checks ship as scripts, because they assert things a probe cannot: they need your app booted around them. Each exits non-zero on failure, so they drop straight into a deploy pipeline:

```sh
# A Laravel broadcast() from a process with no server in it reaches a subscribed socket
php vendor/innerloop-dev/lightspeed/tests/integration/broadcast-reaches-socket.php 127.0.0.1 8000

# Only correctly signed subscriptions get onto private and presence channels
php vendor/innerloop-dev/lightspeed/tests/integration/subscription-authorization-enforced.php 127.0.0.1 8000

# The diagnostic protocol refuses an unauthenticated socket
php vendor/innerloop-dev/lightspeed/tests/security/diagnostic-protocol-refused.php 127.0.0.1 8000

# A client event goes UP the socket, your handler runs, and the reply comes back
# down it. Needs one handler in your app; the script prints the shape it expects.
php vendor/innerloop-dev/lightspeed/tests/integration/client-event-round-trip.php 127.0.0.1 8000

# A closing connection reaches your connection-closed handler with its identity intact.
# Needs one handler in your app; the script prints the shape it expects.
php vendor/innerloop-dev/lightspeed/tests/integration/connection-closed-handler.php 127.0.0.1 8000
```

CI in this repo runs all of them plus the probes against a fresh Laravel app on every push to any branch and on every pull request, on both Laravel 11 and 12, which is what the badge means.

The fastest human check is still the [hello world example](../example): one command, then two browser tabs. It exercises presence, a message up the socket and its reply, and a broadcast from a separate process, and you can see all three happen.

