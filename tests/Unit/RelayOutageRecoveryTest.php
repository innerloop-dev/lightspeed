<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Relay\RedisStreams;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * F9. A Redis outage must not kill a worker's relay for good.
 *
 * Stop Redis for twenty seconds and start it again, with no server restart: on
 * a live two-worker server one worker came back and the other never did. It
 * received no broadcasts, no presence events and no revocation control entries
 * ever again, while the server carried on serving and the log said nothing. A
 * worker in that state cannot drop a revoked connection at all, which is how an
 * outage in a dependency turns into an authorization failure. But the damage
 * is much wider than authorization, because every broadcast to those sockets is
 * lost too.
 *
 * This is the second time this exact shape has appeared: "Redis blipped, the
 * relay never reconnected" is one of the failures that killed the reverted
 * design (637d331).
 *
 * REAL REDIS, STOPPED AND STARTED. Nothing here simulates a failure with a
 * mock or an unroutable port, because what is under test is precisely what a
 * client library does with a socket that died under it. Which is the detail
 * every simulation gets to choose and therefore cannot prove.
 */

/**
 * Chosen at runtime, never hardcoded.
 *
 * This test starts a Redis, kills it, restarts it, and shuts it down again. A
 * fixed port means that if anything else is already listening there, the
 * bind fails, the probe connects to the stranger, the test passes without
 * ever starting a server of its own, and the teardown then issues SHUTDOWN
 * against somebody else's data. That was demonstrated, not imagined: a probe
 * server on 6397 with a value in it was destroyed by a passing run.
 */
function relayOutagePort(): int
{
    static $port = null;

    if ($port !== null) {
        return $port;
    }

    // The scan starts at a pid-derived offset so two suite runs probing at
    // the same moment walk the range in different orders. Both seeing the
    // same port free and both starting a redis-server on it was the last
    // way one run could still trip the other.
    $span = 6497 - 6397 + 1;
    $offset = getmypid() % $span;

    for ($i = 0; $i < $span; $i++) {
        $candidate = 6397 + (($offset + $i) % $span);
        $socket = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);

        if ($socket === false) {
            return $port = $candidate;
        }

        fclose($socket);
    }

    throw new RuntimeException(
        'no free port for the outage Redis between 6397 and 6497; refusing to '
        .'run rather than risk shutting down something that is not ours',
    );
}

function relayOutageRedisAvailable(): bool
{
    exec('command -v redis-server', $output, $status);

    return $status === 0;
}

/**
 * Where the started server records its own pid.
 *
 * The teardown must not depend on being able to TALK to the server it is trying
 * to stop, because "the server is in a state I cannot talk to" is exactly the
 * condition under which stranding one matters. redis-cli is also a second
 * binary that only `redis-server` was ever checked for.
 */
function relayOutagePidFile(): string
{
    return sys_get_temp_dir().'/lightspeed-outage-redis-'.relayOutagePort().'.pid';
}

function startOutageRedis(): void
{
    exec(sprintf(
        // Loopback only. This server exists for the length of one test and has
        // no password; a stranded one used to be reachable from the network.
        "redis-server --port %d --bind 127.0.0.1 --daemonize yes --save '' --appendonly no --pidfile %s",
        relayOutagePort(),
        escapeshellarg(relayOutagePidFile()),
    ), $output, $status);

    if ($status !== 0) {
        throw new RuntimeException(
            'redis-server refused to start on port '.relayOutagePort().': '
            .implode(' ', $output),
        );
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $probe = new \Redis();
            $probe->connect('127.0.0.1', relayOutagePort(), 0.2);

            if ($probe->ping()) {
                $probe->close();

                return;
            }
        } catch (\Throwable) {
            // Not up yet.
        }

        usleep(100_000);
    }

    throw new RuntimeException('the test Redis would not start');
}

/** Is anything answering on the outage port right now? */
function outageRedisIsUp(): bool
{
    try {
        $probe = new \Redis();
        $probe->connect('127.0.0.1', relayOutagePort(), 0.2);
        $probe->ping();
        $probe->close();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Stop the server this test started, and do not come back until it is stopped.
 *
 * ASKING NICELY IS NOT A TEARDOWN. This used to be a `redis-cli shutdown` and a
 * three second wait, and it was only ever reached on the test's success path, so
 * every failing run left a real Redis daemon listening for the rest of the
 * machine's life. Three of them were found running from earlier runs. The next
 * run then picks the next port up, strands another, and walks up the range, 
 * which is a hundred spare servers, a slower port scan every time, and finally a
 * suite that refuses to run at all because there is no free port left. A test
 * that starts a process owns stopping it under every exit, including the ones
 * where its own assertions blew up.
 *
 * So: ask, then kill the pid it wrote for us, then say so if even that failed.
 * Safe to call on a server that is already gone, which is what makes it usable
 * from a `finally` that does not know how the test ended.
 */
function stopOutageRedis(): void
{
    if (!outageRedisIsUp()) {
        @unlink(relayOutagePidFile());

        return;
    }

    exec(sprintf('redis-cli -p %d shutdown nosave 2>/dev/null', relayOutagePort()));

    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (!outageRedisIsUp()) {
            @unlink(relayOutagePidFile());

            return;
        }

        usleep(100_000);
    }

    // It is still there, so stop asking. The pid is the one redis-server wrote
    // itself, so this cannot reach a process this test did not start.
    $pid = (int) trim((string) @file_get_contents(relayOutagePidFile()));

    if ($pid > 0) {
        exec(sprintf('kill -9 %d 2>/dev/null', $pid));

        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (!outageRedisIsUp()) {
                @unlink(relayOutagePidFile());

                return;
            }

            usleep(100_000);
        }
    }

    throw new RuntimeException(
        'the test Redis on port '.relayOutagePort().' would not stop, and is still running',
    );
}

/** Write one entry the way a peer worker's publishControl() writes it. */
function outagePeerControl(string $stream, array $payload): void
{
    $encoded = json_encode($payload);

    RedisStreams::add(
        Redis::connection('outage')->client(),
        $stream,
        [
            'origin_process_key' => 'a-peer-worker',
            'control' => 'lightspeed-test-control',
            'payload' => $encoded,
            'signature' => hash_hmac('sha256', 'lightspeed-test-control'."\0".$encoded, 'test-secret'),
        ],
    );
}

test('a worker relay recovers from Redis being stopped and started again', function () {
    if (!relayOutageRedisAvailable()) {
        $this->markTestSkipped('redis-server is not on PATH');
    }

    // THE START IS INSIDE THE TRY, and that is not a stylistic preference.
    //
    // It used to sit above it, one line outside the block whose `finally` stops
    // the server, with a comment directly beneath claiming everything from
    // there was covered. It was not, and the gap is real rather than
    // theoretical: startOutageRedis() daemonizes redis-server with exec() and
    // THEN polls up to five seconds for it to answer, so the daemon exists
    // before the function can succeed. Every way that poll can end badly. the
    // server comes up slowly under load, binds and then dies, answers on a
    // socket this process cannot use, throws from OUTSIDE the finally, and
    // leaves a real, unauthenticated redis-server listening on loopback with
    // nothing left in the world that knows how to stop it. Four had accumulated
    // on one developer machine, and each one pushes the next run to the next
    // port up until the range is exhausted and the suite refuses to run.
    //
    // stopOutageRedis() is safe to call on a server that was never started (it
    // probes first and returns), so moving the start inside costs nothing and
    // closes the hole for every failure mode, including the throw from the
    // starter itself.
    try {
        startOutageRedis();

        $stream = 'lightspeed:test:outage:'.bin2hex(random_bytes(6));

        config()->set('database.redis.outage', [
            'host' => '127.0.0.1',
            'port' => relayOutagePort(),
            'database' => 0,
            'timeout' => 0.5,
        ]);
        config()->set('lightspeed.relay.enabled', true);
        config()->set('lightspeed.relay.redis_connection', 'outage');
        config()->set('lightspeed.relay.broadcast_stream', $stream);

        $relay = app(RedisRelay::class);

        $server = new ReflectionProperty(RedisRelay::class, 'server');
        $server->setAccessible(true);
        $server->setValue($relay, (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor());

        $drain = new ReflectionMethod(RedisRelay::class, 'drainBroadcasts');
        $drain->setAccessible(true);

        $heard = [];
        $relay->onControl('lightspeed-test-control', function (array $payload) use (&$heard) {
            $heard[] = $payload;
        });

        // Healthy: an entry from a peer arrives.
        outagePeerControl($stream, ['n' => 1]);
        $drain->invoke($relay);

        expect($heard)->toBe([['n' => 1]]);

        // The outage. The poll keeps running throughout, exactly as the timer does.
        stopOutageRedis();

        for ($poll = 0; $poll < 5; $poll++) {
            $drain->invoke($relay);
        }

        // Redis comes back. Nothing restarts, nothing is reconfigured: this is
        // the whole of what a worker gets in production.
        startOutageRedis();

        outagePeerControl($stream, ['n' => 2]);

        for ($poll = 0; $poll < 5; $poll++) {
            $drain->invoke($relay);
        }

        expect($heard)->toBe([['n' => 1], ['n' => 2]]);
    } finally {
        stopOutageRedis();
    }
});

/**
 * ONE BLIP AT BOOT MUST NOT KILL CROSS-WORKER BROADCAST FOR THE PROCESS.
 *
 * `latestBroadcastId()` is the first thing a worker's relay does: it reads the
 * tail of the shared stream so the poll loop starts from now rather than
 * replaying history. It takes the RAW phpredis handle, and a raw handle whose
 * socket died stays dead forever, phpredis keeps failing on it and nothing in
 * this process ever rebuilds it. So its catch does two things, and only one of
 * them is obvious: it falls back to '0-0', and it PURGES the connection.
 *
 * Deleting that purge survived the whole suite. What it leaves behind is the
 * exact failure this file exists for, moved one step earlier: a worker that
 * boots during a Redis blip inherits the dead handle into its poll loop and
 * receives no broadcast, no presence event and no revocation control entry ever
 * again, while the server carries on serving and the log says nothing. The
 * recovery test above cannot see it, because by then the relay has already
 * booted against a healthy Redis.
 *
 * The purge is observed through the connection manager's own cache rather than
 * by reconnecting: after a purge the connection is no longer registered, and
 * the next `Redis::connection()` runs the connector again. Asserting on the
 * cache is what distinguishes "purged" from "happened to work anyway".
 */
test('a relay whose first stream read fails at boot does not keep the dead handle', function () {
    if (!relayOutageRedisAvailable()) {
        $this->markTestSkipped('redis-server is not on PATH');
    }

    try {
        startOutageRedis();

        config()->set('database.redis.outage', [
            'host' => '127.0.0.1',
            'port' => relayOutagePort(),
            'database' => 0,
            'timeout' => 0.5,
        ]);
        config()->set('lightspeed.relay.enabled', true);
        config()->set('lightspeed.relay.redis_connection', 'outage');
        config()->set('lightspeed.relay.broadcast_stream', 'lightspeed:test:boot:'.bin2hex(random_bytes(6)));

        Redis::purge('outage');

        // Resolved and cached while Redis is healthy, which is what a worker
        // that has already done anything at all is holding.
        Redis::connection('outage')->ping();

        expect(array_key_exists('outage', app('redis')->connections()))
            ->toBeTrue('the connection was not cached, so purging it would prove nothing');

        // The blip: Redis goes away between that and the relay's first read.
        stopOutageRedis();

        $relay = app(RedisRelay::class);

        $latest = new ReflectionMethod(RedisRelay::class, 'latestBroadcastId');
        $latest->setAccessible(true);

        // Falls back to replaying from the beginning rather than skipping ahead
        // to an id it could not read...
        expect($latest->invoke($relay))->toBe('0-0');

        // ...and does not hand the poll loop the handle that just died.
        expect(array_key_exists('outage', app('redis')->connections()))
            ->toBeFalse('the dead connection is still cached, so every later poll inherits a handle that can never recover');
    } finally {
        stopOutageRedis();
    }
});
