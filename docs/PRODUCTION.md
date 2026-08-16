# Lightspeed Production Runbook

The concrete production shape for running Lightspeed as your app's HTTP and realtime runtime.

## Recommended topology

### Per instance

Run these on every application node:

- one `lightspeed:serve` process group
- one or more `queue:work` processes
- access to the shared Redis deployment used for:
  - sessions
  - cache
  - queues
  - Lightspeed relay
  - Lightspeed owner-command routing
  - presence state

### Public shape

The default and preferred shape is one public host: nginx terminates TLS on `443` and routes normal HTTP and websocket upgrades to the same Lightspeed upstream pool. That is the shape this serve command produces:

```sh
php artisan lightspeed:serve --workers=8 --relay=1 --instance-id="$(hostname)-lightspeed"
```

For migration or rollback isolation you can split the surfaces across two hostnames instead:

- `app.example.com`
  - nginx `443` -> Lightspeed HTTP upstream `127.0.0.1:8000`
- `realtime.example.com`
  - nginx `443` -> Lightspeed realtime upstream `127.0.0.1:8080`

The split shape requires binding the two surfaces to different ports:

```sh
php artisan lightspeed:serve --workers=8 --relay=1 --http-port=8000 --realtime-port=8080 --instance-id="$(hostname)-lightspeed"
```

### Horizontal scale

Start with:

- `2+` application nodes
- `4-8` Lightspeed workers per node

Then raise:

- node count for more memory / failure isolation
- worker count for more CPU concurrency

based on measured reconnect/auth/fan-out results.

## Non-negotiable environment requirements

Required for multi-instance correctness:

- `BROADCAST_CONNECTION=lightspeed` on every process that broadcasts, including queue workers. Channel-auth callbacks register on the lightspeed broadcaster, and detached processes publish into the cluster through the Redis relay.
- A Redis deployment reachable by every node. Relay delivery, shared presence, and owner forwarding all live in Redis; there is no single-node fallback for a cluster.
- Session, cache, and queue stores that are shared across all nodes, so a user's session authenticates on whichever node accepts their connection. Redis for all three is the usual choice (`SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`), but any shared store satisfies the requirement.

## Lightspeed process settings

Two dials, both tuned later by measurement:

- `--workers`
  Use a small multiple of CPU cores.
- `--task-workers`
  Leave at `0` unless your application calls `Octane::concurrently()`.

  Lightspeed dispatches no Swoole tasks of its own, so the realtime and HTTP
  surfaces are unaffected by this number. What it buys is the host application's
  own concurrency helper: Lightspeed binds the Swoole server into the
  application container, which is what makes Octane route `Octane::concurrently()`
  through Swoole tasks, and a task needs a task worker to run in. Each one is a
  separate process that boots its own copy of your application, so raise it the
  way you would raise `--workers`: by measurement, not by default.

## Health checks

Configure external health checks for both surfaces:

- app host:
  - `GET /up`
- realtime host:
  - `GET /healthz`

Expected:

- HTTP `200`
- fast response without hitting expensive app work

`GET /healthz` is answered by the worker that accepted the connection, and it
answers `200` only for a worker that finished starting. A worker whose start-up
failed partway (a Redis deployment that was unreachable at the moment it came
up is the usual cause) answers `503` with the reason in the body, and refuses
websocket upgrades with Pusher close code `4100`, which tells clients to
reconnect elsewhere.

Know what that worker can and cannot still do, because it is less than it
sounds. The relay is attached near the start of worker start-up and your
application is booted near the end, so the usual cause listed above hits before
there is an application in the process at all. That worker then answers `503`
to **every** request, not only `/healthz`. Application routes included, with
`{"error": "Lightspeed HTTP worker is not ready."}`. It also refuses every
websocket upgrade, the diagnostic protocol included. What is left to inspect it
with is `/healthz`, whose body names the cause, and the server's stdout, where
the same failure is printed once.

It is also terminal. Nothing retries the failed step and nothing marks the
worker healthy again; when Redis comes back, that worker does not. Only
replacing the process recovers it.

So configure **both**, the way a Kubernetes readiness/liveness pair is meant to
be configured:

- a readiness check on `/healthz` that drains the node quickly, so clients stop
  being sent to a worker that cannot serve them;
- a liveness check on `/healthz` with a much longer failure threshold, so a
  node that is still `503` after the dependency has had time to come back is
  restarted rather than left drained forever.

Draining alone is not a recovery strategy here. Restarting alone turns a
dependency outage into a restart loop. The long liveness threshold is what
separates the two.

## Queue workers

Keep queue workers separate from Lightspeed.

Recommended initial worker command:

```sh
php artisan queue:work --sleep=0.1 --tries=3
```

Queue workers should use `BROADCAST_CONNECTION=lightspeed` so broadcast events publish through Redis to the running Lightspeed nodes.

## Nginx shape

See:

- [two-hosts.conf.example](../deploy/nginx/two-hosts.conf.example)

Preferred end-state:

- one public host on `443`
- normal HTTP and websocket upgrades routed to the same Lightspeed upstream pool
- TLS termination at nginx

The two-host example is still useful for migration or rollback isolation. It keeps:

- app traffic on the app host
- websocket upgrade traffic on the realtime host
- TLS termination at nginx

## Process supervision

See:

- [lightspeed.service.example](../deploy/systemd/lightspeed.service.example)
- [queue-worker.service.example](../deploy/systemd/queue-worker.service.example)

The important production rule is:

- Lightspeed must restart automatically
- queue workers must restart automatically
- pid/log files must be per-instance and not shared blindly

## Rollout order (migrating from an existing runtime)

1. Deploy code and config with Lightspeed installed.
2. Start Lightspeed on all nodes behind internal ports only.
3. Run the probe suite against the internal cluster.
4. Switch the public host websocket upgrade path from your current websocket server (e.g. Reverb) to Lightspeed upstreams.
5. Switch the public host HTTP path from your current app server to Lightspeed HTTP upstreams.
6. Verify your app's realtime flows end to end.
7. Stop the old runtimes only after the above is green.

## Rollback

Rollback target:

- public host HTTP path back to the previous app server upstreams
- public host websocket upgrade path back to the previous websocket upstreams
- queue workers may keep running if they still target the old broadcast path

Keep rollback simple:

- do not mutate client contracts during the first cut
- keep nginx upstream definitions for both old and new runtimes ready

## Validation matrix before production

Run these against the internal cluster before the first live cut:

- `php artisan lightspeed:probe --connect-host=... --port=... --tls=0`
- `php artisan lightspeed:relay-probe --connect-host=... --port=... --tls=0`
  (needs `LIGHTSPEED_DIAGNOSTICS_ENABLED=true`, which SECURITY.md tells you not
  to leave on for an internet-facing host: enable it, run the probe, turn it off)
- `php artisan lightspeed:presence-probe --connect-host=... --port=... --tls=0`
- `php artisan lightspeed:load-probe --targets=... --connections=100`
- `php artisan lightspeed:load-probe --targets=... --connections=500`
- `php artisan lightspeed:load-probe --targets=... --connections=1000`

Then exercise your own app's realtime flows on top.

## Observed local validation

Observed August 2026 with:

- `2` local Lightspeed instances
- `2` workers per instance
- loopback upstreams on `8000` and `8001`
- Redis relay, shared presence, and owner forwarding enabled

Load probe results (one event published via the Pusher HTTP API, delivered to every socket):

- `100` connections: delivered `100/100`, split `50`/`50` across instances, fan-out p50 `26ms`, p95 `27ms`
- `500` connections: delivered `500/500`, split `250`/`250`, fan-out p50 `49ms`, p95 `58ms`
- `1000` connections: delivered `1000/1000`, split `500`/`500`, fan-out p50 `17-30ms`, p95 `27-42ms` across three consecutive runs

The delivery figures are the meaningful ones: every socket on both instances received every event. The latencies come from a laptop hosting both server instances and the load generator at once, which is why the 1000-connection row is a range rather than a single number. Each figure is one socket's own publish-to-receipt time.

[verifying.md](verifying.md) reports `32ms`/`43ms` for the same 1,000-connection topology. That is a **different run** on a differently loaded machine, not a different configuration, and the gap between the two is a fair picture of how much the latency column is worth.

Presence sweep cost, measured separately: about 1.1-1.2 microseconds per marker, where a marker is one membership (one connection on one channel). A bench holding 10,000 channels of roughly 6 members each rewrote 60,000 markers in about 67ms of blocked event loop per tick, plus roughly 2.4ms per 10,000 channels to build the map of what the worker holds. At that rate a 200ms tick is around 180,000 markers. Past that, split the channels across more workers or more instances.

These are local proof numbers, not production promises. Validate on your own hardware with the probe suite before trusting any of them.
