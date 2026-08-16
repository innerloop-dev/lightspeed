<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;

/**
 * The claim this file exists to defend: no wait in this package can freeze the
 * shipped server for longer than a request budget.
 *
 * `enable_coroutine` ships FALSE, which means there is no scheduler to yield
 * to, which means every sleep in a wait loop is a real usleep() on the single
 * event loop that serves every connection on the worker. Both waiting loops
 * were sized as if the yielding branch were the normal case: an owner command
 * waited up to `wait_timeout_ms` (10 SECONDS by default) and a contended write
 * lease up to retries x wait (1 second). On the shipped configuration those
 * are not waits, they are outages, nothing else on the worker runs, including
 * the timers that sweep grants and drain the relay.
 *
 * The bounds below are the point, not the exact numbers: they are checked with
 * an order of magnitude of headroom so that a slow CI box cannot make them
 * flap, while still being far under what the uncapped waits cost.
 */

/** Call a private method on an object. */
function driveWait(object $target, string $method, array $arguments = []): mixed
{
    $handler = new ReflectionMethod($target::class, $method);
    $handler->setAccessible(true);

    return $handler->invokeArgs($target, $arguments);
}

test('an owner command wait outside a coroutine gives up long before the configured timeout', function () {
    // The shipped defaults, stated explicitly so this test still means
    // something if someone lowers wait_timeout_ms instead of fixing the wait.
    config()->set('lightspeed.owner_commands.wait_timeout_ms', 10000);
    config()->set('lightspeed.owner_commands.wait_interval_us', 10000);

    $bus = app(OwnerCommandBus::class);

    $startedAt = microtime(true);
    $result = driveWait($bus, 'waitForResponse', ['lightspeed-blocking-wait-'.bin2hex(random_bytes(8))]);
    $elapsed = microtime(true) - $startedAt;

    // 2.5s sits an order of magnitude above the 250ms capped wait and a
    // quarter of the 10s uncapped one. The old bound of 1.0s was only 4x the
    // cap, and ten parallel mutation processes stretched a 250ms usleep past
    // it: a flap, not a regression.
    expect($result['ok'])->toBeFalse()
        ->and($elapsed)->toBeLessThan(2.5);
});

test('a contended write lease outside a coroutine gives up long before the uncapped wait', function () {
    // 400 retries x 20ms puts the UNCAPPED path at 8 seconds, so the bound
    // below can be generous to machine load while still being unreachable by
    // code that ignores the blocking cap. With the old 50 x 20ms = 1s wrong
    // path, the bound had to sit at 0.5s, which was only 2x the capped
    // wait's 240ms, and parallel mutation runs flapped it.
    config()->set('lightspeed.resources.write_lease_retries', 400);
    config()->set('lightspeed.resources.write_lease_wait_us', 20000);

    $resourceId = 'lightspeed-blocking-wait-'.bin2hex(random_bytes(8));
    $leaseKey = "lightspeed:resource-write:{$resourceId}:lease";

    // Held by someone else, so every retry contends and none can win.
    Redis::connection()->set($leaseKey, 'held-elsewhere', 'EX', 30);

    try {
        $router = app(ResourceRouter::class);

        $startedAt = microtime(true);
        $token = $router->acquireWriteLease($resourceId);
        $elapsed = microtime(true) - $startedAt;

        expect($token)->toBeNull()
            ->and($elapsed)->toBeLessThan(2.0);
    } finally {
        Redis::connection()->del($leaseKey);
    }
});

test('the wait a worker cannot yield out of is capped by its own setting', function () {
    // Two settings, not one, because the two situations are genuinely
    // different: a coroutine worker can afford the long wait and a blocking
    // one cannot. Shipping only the long one is what made the docblocks
    // describe a configuration nobody runs.
    expect(config('lightspeed.owner_commands.blocking_wait_timeout_ms'))->toBe(250)
        ->and(config('lightspeed.resources.blocking_write_lease_retries'))->toBe(12);
});

test('a capped wait says why it gave up early', function () {
    // An operator reading "timed out" has no way to tell a slow owner from a
    // cap they can raise, so the refusal names the setting that produced it.
    config()->set('lightspeed.owner_commands.wait_timeout_ms', 10000);
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 50);

    $result = driveWait(app(OwnerCommandBus::class), 'waitForResponse', ['lightspeed-blocking-wait-'.bin2hex(random_bytes(8))]);

    expect($result['error'])->toContain('blocking_wait_timeout_ms');
});
