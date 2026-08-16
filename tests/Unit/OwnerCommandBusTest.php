<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\OwnerCommandSigner;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Workers\WorkerContext;

/**
 * The bus runs every forwarded mutation under the resource write lease, and the
 * lease is only a Redis key with a TTL: a handler that runs longer than the TTL
 * loses it mid-flight and another worker can mutate the same resource at the
 * same time. That failure used to be invisible. The release said "the lease
 * was gone" and the bus threw the answer away, returning a success for a
 * mutation that had raced. These tests pin down what the bus now does with it,
 * plus the ordinary held-lease and contended-lease paths around it.
 *
 * They drive executeCommand() directly. The public drain path needs an attached
 * Swoole server bound to a port, which a unit test has no business starting,
 * and the lease behaviour under test lives entirely inside that one method.
 */

function busForExecutor(callable $executor): OwnerCommandBus
{
    $config = app('config');
    $workerContext = new WorkerContext($config);

    $bus = new OwnerCommandBus(
        $config,
        new ResourceRouter($config, $workerContext),
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );
    $bus->useExecutor($executor);

    return $bus;
}

function executeOwnerCommand(OwnerCommandBus $bus, string $resourceId, string $command = 'test-command'): ?array
{
    $execute = new ReflectionMethod($bus, 'executeCommand');
    $execute->setAccessible(true);

    return $execute->invoke($bus, new OwnerCommand(
        requestId: 'request-'.bin2hex(random_bytes(4)),
        resourceId: $resourceId,
        command: $command,
        payload: [],
        originProcessKey: 'worker-origin',
    ));
}

beforeEach(function () {
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);

    $this->resourceId = 'lightspeed-test-'.bin2hex(random_bytes(8));
    $this->leaseKey = "lightspeed:resource-write:{$this->resourceId}:lease";
});

afterEach(function () {
    Redis::connection()->del($this->leaseKey);
    Redis::connection()->del("lightspeed:resource-owner:{$this->resourceId}");
});

test('a command that keeps its lease returns the handler result untouched', function () {
    $bus = busForExecutor(fn () => ['ok' => true, 'result' => ['nodes' => 3]]);

    expect(executeOwnerCommand($bus, $this->resourceId))
        ->toBe(['ok' => true, 'result' => ['nodes' => 3]]);
});

test('a command releases the lease so the next one can run', function () {
    $bus = busForExecutor(fn () => ['ok' => true]);

    executeOwnerCommand($bus, $this->resourceId);

    expect(Redis::connection()->get($this->leaseKey))->toBeNull();
});

test('a command whose resource is already leased elsewhere does not execute', function () {
    $otherWorker = new ResourceRouter(app('config'), new WorkerContext(app('config')));
    $otherWorker->acquireWriteLease($this->resourceId);

    $handlerRan = false;
    $bus = busForExecutor(function () use (&$handlerRan) {
        $handlerRan = true;

        return ['ok' => true];
    });

    $result = executeOwnerCommand($bus, $this->resourceId);

    expect($handlerRan)->toBeFalse()
        ->and($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('could not acquire');
});

test('a command that loses its lease mid-flight fails instead of reporting success', function () {
    // Exactly what a handler slower than the lease TTL sees: the key is gone by
    // the time it finishes, and another worker was free to take the resource.
    $bus = busForExecutor(function () {
        Redis::connection()->del($this->leaseKey);

        return ['ok' => true, 'result' => ['nodes' => 3]];
    });

    $result = executeOwnerCommand($bus, $this->resourceId);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('lost the resource write lease');
});

test('a command whose lease was taken over by another writer fails', function () {
    $bus = busForExecutor(function () {
        Redis::connection()->set($this->leaseKey, 'another-workers-token', 'EX', 10);

        return ['ok' => true];
    });

    $result = executeOwnerCommand($bus, $this->resourceId);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('lost the resource write lease');

    // The takeover must survive this command's release: compare-and-delete has
    // no business deleting a key it no longer owns.
    expect(Redis::connection()->get($this->leaseKey))->toBe('another-workers-token');
});

test('a lost lease is logged with the resource id so an operator can see it', function () {
    $logged = [];
    Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $bus = busForExecutor(function () {
        Redis::connection()->del($this->leaseKey);

        return ['ok' => true];
    });

    executeOwnerCommand($bus, $this->resourceId);

    expect($logged['message'])->toContain('lost its resource write lease')
        ->and($logged['context']['resource_id'])->toBe($this->resourceId)
        ->and($logged['context']['command'])->toBe('test-command');
});

test('a command with no resource id runs without a lease', function () {
    $bus = busForExecutor(fn () => ['ok' => true, 'result' => 'unleased']);

    expect(executeOwnerCommand($bus, ''))->toBe(['ok' => true, 'result' => 'unleased']);
});

test('the lease is skipped entirely when write leases are disabled', function () {
    config()->set('lightspeed.owner_commands.write_lease', false);
    $bus = busForExecutor(fn () => ['ok' => true]);

    executeOwnerCommand($bus, $this->resourceId);

    expect(Redis::connection()->exists($this->leaseKey))->toBe(0);
});

// ---------------------------------------------------------------------------
// Replay protection: the freshness window and the spent-id claim, together
// ---------------------------------------------------------------------------

/**
 * The two halves of replay protection have to overlap, and they did not.
 *
 * An owner command is deliverable while it is FRESH, and executable once
 * because its request id is CLAIMED. Neither is replay protection alone: a
 * window admits every copy inside it, and a claim set that never expired would
 * have to remember every id forever. Together the entry is deliverable during a
 * short window and exactly once within it.
 *
 * "Together" is the word that was not true. The claim's TTL was exactly twice
 * the window, and the window is inclusive at both ends, so there was one second
 *. The last acceptable one, on which the claim had already lapsed and the
 * entry was still accepted. The two mechanisms met exactly, with no overlap, and
 * a boundary that meets exactly is a boundary that is open.
 *
 * These tests derive the requirement rather than restating the constant, so
 * they still hold if the window changes and they fail if the relationship does.
 */
/**
 * The window and the claim both belong to OwnerCommandSigner. They are read off
 * the signer the bus actually holds, so these tests keep describing the pair the
 * bus really runs with rather than a second one built to look like it.
 */
function ownerCommandSigner(OwnerCommandBus $bus): OwnerCommandSigner
{
    $signer = new ReflectionProperty($bus, 'signer');
    $signer->setAccessible(true);

    return $signer->getValue($bus);
}

function ownerCommandFreshnessWindow(OwnerCommandBus $bus): int
{
    return ownerCommandSigner($bus)->freshnessSeconds();
}

function ownerCommandIsFresh(OwnerCommandBus $bus, int $issuedAt): bool
{
    return ownerCommandSigner($bus)->isFresh($issuedAt);
}

test('the freshness window is inclusive at both ends, which is what the claim has to cover', function () {
    $bus = busForExecutor(fn () => ['ok' => true]);
    $window = ownerCommandFreshnessWindow($bus);
    $now = time();

    // Both extremes are ACCEPTED, so the interval over which one signed entry
    // can be delivered is closed and `2 * window` seconds wide.
    expect(ownerCommandIsFresh($bus, $now - $window))->toBeTrue()
        ->and(ownerCommandIsFresh($bus, $now + $window))->toBeTrue()
        ->and(ownerCommandIsFresh($bus, $now - $window - 1))->toBeFalse()
        ->and(ownerCommandIsFresh($bus, $now + $window + 1))->toBeFalse();
});

test('a spent request id stays spent for longer than its entry can stay fresh', function () {
    $bus = busForExecutor(fn () => ['ok' => true]);
    $window = ownerCommandFreshnessWindow($bus);

    $requestId = 'replay-boundary-'.bin2hex(random_bytes(8));
    $key = "lightspeed:owner-command-seen:{$requestId}";

    try {
        expect(ownerCommandSigner($bus)->claimRequestId($requestId))->toBeTrue();

        $ttl = (int) Redis::connection()->ttl($key);

        // The widest gap between the first and last instants at which one
        // signed entry is accepted: issued at T, first delivered at T - window,
        // last delivered at T + window.
        $widestAcceptanceInterval = 2 * $window;

        // STRICTLY greater. Equal is the bug: a claim made at T - window would
        // expire at exactly T + window, the last second the entry is still
        // fresh, and the entry becomes executable a second time.
        expect($ttl)->toBeGreaterThan(
            $widestAcceptanceInterval,
            'the spent-id claim lapses while its own entry is still accepted as fresh, so the last second of the window has no replay protection',
        );
    } finally {
        Redis::connection()->del($key);
    }
});

/**
 * And the claim actually refuses the second copy, so the TTL above is guarding
 * something rather than being a number with a nice relationship to another
 * number.
 */
test('a request id can only be spent once', function () {
    $bus = busForExecutor(fn () => ['ok' => true]);

    $requestId = 'replay-once-'.bin2hex(random_bytes(8));
    $key = "lightspeed:owner-command-seen:{$requestId}";

    try {
        expect(ownerCommandSigner($bus)->claimRequestId($requestId))->toBeTrue()
            ->and(ownerCommandSigner($bus)->claimRequestId($requestId))->toBeFalse();
    } finally {
        Redis::connection()->del($key);
    }
});

/**
 * The docblock says a Redis failure must REFUSE, because the alternative is
 * executing a mutation that may already have run. Returning true from that catch
 * survived the whole suite: nothing asserted the direction the comment insists
 * on, and getting it backwards turns a Redis blip into every in-flight owner
 * command running twice.
 */
test('a claim that cannot reach Redis refuses rather than assuming the id is unspent', function () {
    // Probed, not assumed: a port that answers would make this test prove the
    // opposite of what it says.
    $closedPort = null;

    foreach (range(6490, 6589) as $candidate) {
        $socket = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.05);

        if ($socket === false) {
            $closedPort = $candidate;
            break;
        }

        fclose($socket);
    }

    expect($closedPort)->not->toBeNull('no closed port was available, so no Redis outage can be simulated');

    config()->set('database.redis.owner_command_unreachable', [
        'host' => '127.0.0.1',
        'port' => $closedPort,
        'database' => 0,
        'timeout' => 0.2,
    ]);
    config()->set('lightspeed.owner_commands.redis_connection', 'owner_command_unreachable');
    Redis::purge('owner_command_unreachable');

    $reached = true;

    try {
        Redis::connection('owner_command_unreachable')->ping();
    } catch (\Throwable) {
        $reached = false;
    }

    expect($reached)->toBeFalse('the "unreachable" Redis answered, so this test would prove nothing');

    $bus = busForExecutor(fn () => ['ok' => true]);

    expect(ownerCommandSigner($bus)->claimRequestId('unreachable-'.bin2hex(random_bytes(8))))->toBeFalse();
});
