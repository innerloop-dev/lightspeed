<?php

namespace Lightspeed\Tests;

use Illuminate\Support\Facades\Redis;
use Lightspeed\LightspeedServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LightspeedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Credentials the broadcaster needs to exist at all. They only ever
        // sign auth strings locally, so these values are arbitrary.
        $app['config']->set('lightspeed.reverb_compat.app_id', 'test-app');
        $app['config']->set('lightspeed.reverb_compat.app_key', 'test-key');
        $app['config']->set('lightspeed.reverb_compat.app_secret', 'test-secret');

        // One Redis namespace per suite RUN. The suite talks to a real Redis
        // on purpose, and without this two simultaneous runs share every key:
        // measured, run B's owner command was consumed by run A and timed out,
        // and every setUp() below deletes a relay stream the other run is
        // reading. The pid is the run: Pest is one process, and a parallel
        // worker getting its own namespace is isolation too, not a bug.
        // Every test that touches key names already reads this prefix from
        // config rather than assuming one.
        $app['config']->set('database.redis.options.prefix', 'lightspeed-suite-'.getmypid().':');

        // The suite's Redis is env-addressable like the demo's: REDIS_HOST and
        // REDIS_PORT reach every connection, instead of 6379 being the only
        // Redis the suite can ever test against.
        $app['config']->set('database.redis.default.host', (string) env('REDIS_HOST', '127.0.0.1'));
        $app['config']->set('database.redis.default.port', (int) env('REDIS_PORT', 6379));
    }

    /**
     * Clear the shared relay stream before every test.
     *
     * Ten tests publish through the relay, and with no override they all land
     * on the DEFAULT stream, `lightspeed:broadcasts`. A Redis stream has no
     * implicit expiry, nothing in the package trims this one (the relay only
     * reads forward from its own last id), and no test deleted it. So it was
     * the one piece of suite residue that grew without bound: every run
     * appended and nothing ever removed, observed at XLEN 84 on a developer
     * machine and rising by roughly ten entries a run, forever.
     *
     * Every other key the suite leaves behind carries a TTL and clears itself,
     * which is why this is the only cleanup here rather than a general sweep.
     *
     * THREE THINGS THIS MAY NOT DO, each of which it did once:
     *
     *   it may not be a bare `afterEach()` in `Pest.php`. That binds to that
     *   file's own tests, of which there are none, so it ran zero times and
     *   the stream kept growing exactly as before while looking like a fix.
     *
     *   it may not run in `tearDown()`. Resolving Redis there builds a
     *   connection against a container Testbench has already begun destroying,
     *   and the suite died at exit 255 partway through with no failure, no
     *   exception and nothing on stderr.
     *
     *   it may not touch the `Redis` facade. Doing so constructs Laravel's
     *   RedisManager, which snapshots `database.redis` as it stands, and
     *   several tests here ADD a connection at runtime (see
     *   RelayOutageRecoveryTest, which defines `database.redis.outage` inside
     *   the test body). Resolving the manager first left those connections
     *   permanently unconfigured.
     *
     * So it talks to phpredis directly, from the configuration rather than
     * through the container, and applies the key prefix itself the way the
     * client would. Nothing here is cached and nothing is registered, so the
     * container is in exactly the state the test would have found.
     *
     * Clearing on the way IN rather than out bounds the growth just as well:
     * the stream only ever holds what the running test put there. What is left
     * behind is the last relay test's few entries, which the next run clears,
     * rather than every entry the suite has ever written.
     *
     * Skipped silently whenever anything is not as expected. Redis being
     * unreachable is the subject of several tests here, and a cleanup that
     * failed the test it was preparing for would report the wrong problem.
     */
    /** Whether this process has already swept dead runs' namespaces. */
    private static bool $sweptDeadNamespaces = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sweepDeadSuiteNamespaces();
        $this->clearSharedRelayStream();
    }

    /**
     * Delete Redis keys left by suite runs whose process is gone.
     *
     * The per-run prefix (see defineEnvironment) is what keeps two live runs
     * out of each other's keys, and it carries a cost: a run that is killed
     * hard leaves its namespace behind, and the relay stream in it has no
     * TTL. The prefix embeds the run's pid, so residue is precisely
     * identifiable: a namespace whose pid is not alive belongs to nobody.
     * A live pid answers signal 0, or answers EPERM when it belongs to
     * another user; both read as alive here, because deleting a live run's
     * keys is the one failure this sweep may not have. The check reads THIS
     * pid namespace: two containers sharing one Redis can still misjudge
     * each other, which is a shared-Redis-across-containers arrangement this
     * suite does not claim to support. Once per process, not per test: the
     * answer cannot change mid-run except by another run exiting, whose next
     * run sweeps it.
     */
    private function sweepDeadSuiteNamespaces(): void
    {
        if (self::$sweptDeadNamespaces || ! class_exists(\Redis::class) || ! function_exists('posix_kill')) {
            self::$sweptDeadNamespaces = true;

            return;
        }

        self::$sweptDeadNamespaces = true;

        try {
            $client = $this->rawRedisClient();

            if ($client === null) {
                return;
            }

            $iterator = null;

            do {
                $keys = $client->scan($iterator, 'lightspeed-suite-*', 500);

                foreach ($keys === false ? [] : $keys as $key) {
                    if (preg_match('/^lightspeed-suite-(\d+):/', $key, $match) !== 1) {
                        continue;
                    }

                    if ((int) $match[1] === getmypid() || @posix_kill((int) $match[1], 0)) {
                        continue;
                    }

                    // EPERM is an answer FROM a live process: signal 0 was
                    // refused, not undeliverable. Reading it as dead would
                    // delete a run started by another user mid-flight.
                    if (posix_get_last_error() === 1) {
                        continue;
                    }

                    $client->del($key);
                }
            } while ($iterator !== 0 && $iterator !== null);

            $client->close();
        } catch (\Throwable) {
            // Residue is a cleanliness problem, never worth failing a test.
        }
    }

    private function clearSharedRelayStream(): void
    {
        if (! class_exists(\Redis::class)) {
            return;
        }

        try {
            $stream = (string) config('lightspeed.relay.broadcast_stream', 'lightspeed:broadcasts');

            if ($stream === '') {
                return;
            }

            $client = $this->rawRedisClient();

            if ($client === null) {
                return;
            }

            $client->del(((string) config('database.redis.options.prefix', '')).$stream);
            $client->close();
        } catch (\Throwable) {
            // Nothing here is worth failing a test over.
        }
    }

    /**
     * A phpredis client built from configuration, bypassing the container.
     *
     * See the setUp() docblock for why the container and the Redis facade may
     * not be involved here. Null when the configured connection is not there
     * to be read; connection errors throw to the caller's catch.
     */
    private function rawRedisClient(): ?\Redis
    {
        $connection = (string) config('lightspeed.relay.redis_connection', 'default');
        $server = config('database.redis.'.$connection);

        if (! is_array($server)) {
            return null;
        }

        $client = new \Redis();
        $client->connect(
            (string) ($server['host'] ?? '127.0.0.1'),
            (int) ($server['port'] ?? 6379),
            0.5,
        );

        if (($password = $server['password'] ?? null) !== null) {
            $client->auth($password);
        }

        $client->select((int) ($server['database'] ?? 0));

        return $client;
    }
}
