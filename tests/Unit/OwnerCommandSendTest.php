<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Relay\RedisStreams;
use Lightspeed\Workers\WorkerContext;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * SEND: THE PATH THAT DOES NOT WAIT.
 *
 * forwardIfOwnedByAnotherProcess() writes a command and then polls a response
 * key, and with `enable_coroutine` off that poll is a usleep() on the one event
 * loop serving every connection the worker holds. send() exists for the callers
 * that have no use for the answer, so what has to be proven about it is not just
 * that the command arrives: it is that the caller does not pay for it, and that
 * nothing about WHO is allowed to execute a command got cheaper along the way.
 *
 * So: it runs the handler when this worker owns the resource, it reaches the
 * owner's handler when another worker does, it leaves no response key behind for
 * nobody to read, it takes no write lease, and it comes back in a time that is
 * provably not a poll. Plus the two edges that only send() has: a resource with
 * no resolvable owner, and a disabled bus.
 *
 * These tests drive the private drain directly, exactly as the other owner-bus
 * tests do and for the same reason: the public drain needs a listening Swoole
 * server, which a unit test has no business starting.
 */
class OwnerSendServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}

/** A router that answers with whatever owner claim a test wants to hand the bus. */
class OwnerSendStubRouter extends ResourceRouter
{
    public array $claim = [];

    public function claimOwner(string $resourceId, string $reason = 'unknown'): array
    {
        return $this->claim;
    }
}

/**
 * A bus attached to one process key, with a handler a test can watch.
 *
 * @return array{0: OwnerCommandBus, 1: string}
 */
function ownerSendBus(string $instanceId, ?callable $executor = null, ?ResourceRouter $router = null): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext = new WorkerContext($config);
    $workerContext->boot(OwnerSendServer::make(), 0);

    $bus = new OwnerCommandBus(
        $config,
        $router ?? new ResourceRouter($config, $workerContext),
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );
    $bus->useExecutor($executor ?? fn () => ['ok' => true]);

    // The drain refuses to run without an attached server, and a unit test has
    // no business binding a port to get one.
    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, OwnerSendServer::make());

    return [$bus, $workerContext->currentProcessKey()];
}

function ownerSendDrain(OwnerCommandBus $bus): void
{
    $drain = new ReflectionMethod($bus, 'drainCommands');
    $drain->setAccessible(true);
    $drain->invoke($bus);
}

/**
 * Hand a resource to another process, the way that process's own claim would.
 *
 * Written as the real key rather than stubbed, so the caller under test runs the
 * real claimOwner(): SET NX that loses, then the read back. That is the work
 * send() actually does before it writes, and a timing test that skipped it would
 * be timing something else.
 */
function ownerSendGiveOwnershipTo(string $resourceId, string $processKey): void
{
    Redis::connection()->set(
        "lightspeed:resource-owner:{$resourceId}",
        json_encode(['process_key' => $processKey, 'instance_id' => 'owner', 'worker_id' => 0], JSON_THROW_ON_ERROR),
        'EX',
        60,
    );
}

/** @return array<int, array<string, mixed>> the decoded messages on a process's stream */
function ownerSendStreamMessages(string $processKey): array
{
    $entries = RedisStreams::read(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        '0-0',
        100,
    );

    return array_map(static function (array $entry): array {
        $fields = RedisStreams::normalizeFields(is_array($entry[1] ?? null) ? $entry[1] : []);

        return json_decode((string) $fields['message'], true, 512, JSON_THROW_ON_ERROR);
    }, $entries);
}

beforeEach(function () {
    config()->set('lightspeed.owner_commands.enabled', true);
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);

    $this->resourceId = 'lightspeed-send-'.bin2hex(random_bytes(8));
    $this->ownerKey = "lightspeed:resource-owner:{$this->resourceId}";
    $this->leaseKey = "lightspeed:resource-write:{$this->resourceId}:lease";
    $this->streams = [];
});

afterEach(function () {
    Redis::connection()->del($this->ownerKey);
    Redis::connection()->del($this->leaseKey);

    foreach ($this->streams as $processKey) {
        Redis::connection()->del("lightspeed:owner-commands:{$processKey}");
    }
});

test('send runs the handler here when this worker owns the resource', function () {
    // A local send routes nothing and drops nothing, so it has nothing to
    // report. Asserting that pins the early return that would otherwise fall
    // through into the unroutable branch below it.
    Log::shouldReceive('warning')->never();

    $handled = [];
    [$bus, $processKey] = ownerSendBus('send-local', function (OwnerCommand $command) use (&$handled) {
        $handled[] = $command;

        return ['ok' => true];
    });
    $this->streams[] = $processKey;

    // Nobody owns it yet, so the claim inside send() takes it for this process.
    $bus->send($this->resourceId, 'arena-input', ['seq' => 7]);

    expect($handled)->toHaveCount(1)
        ->and($handled[0]->command)->toBe('arena-input')
        ->and($handled[0]->resourceId)->toBe($this->resourceId)
        ->and($handled[0]->payload)->toBe(['seq' => 7])
        ->and($handled[0]->expectsReply)->toBeFalse();

    // And it went nowhere near a stream: local is local.
    expect(ownerSendStreamMessages($processKey))->toBe([]);
});

test('send from a non-owner reaches the owner handler and writes no reply', function () {
    $handled = [];
    [$owner, $ownerProcessKey] = ownerSendBus('send-owner', function (OwnerCommand $command) use (&$handled) {
        $handled[] = $command;

        return ['ok' => true, 'applied' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerSendGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerSendBus('send-caller', fn () => throw new RuntimeException('the caller must not execute a remote command'));
    $this->streams[] = $callerProcessKey;

    $caller->send($this->resourceId, 'arena-input', ['seq' => 42, 'th' => true]);

    // It landed on the OWNER's stream, addressed to the owner, marked as
    // wanting no reply.
    $messages = ownerSendStreamMessages($ownerProcessKey);
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['target_process_key'])->toBe($ownerProcessKey)
        ->and($messages[0]['origin_process_key'])->toBe($callerProcessKey)
        ->and($messages[0]['expects_reply'])->toBeFalse();

    $requestId = $messages[0]['request_id'];

    ownerSendDrain($owner);

    expect($handled)->toHaveCount(1)
        ->and($handled[0]->command)->toBe('arena-input')
        ->and($handled[0]->payload)->toBe(['seq' => 42, 'th' => true])
        ->and($handled[0]->originProcessKey)->toBe($callerProcessKey);

    // THE POINT: no response entry. Nobody is polling for one, and every one
    // written would sit in Redis for its whole TTL with no reader to delete it.
    expect(Redis::connection()->exists("lightspeed:owner-command-response:{$requestId}"))->toBe(0);
});

/**
 * A drain is a BATCH, and a sent entry has to step over the response write
 * without stepping out of the loop. Skipping the write with a break instead of a
 * continue drops every command queued behind the first sent one, which at input
 * rates is most of them, and nothing about a single-entry test can see it.
 */
test('a sent command does not abandon the entries queued behind it', function () {
    $seqs = [];
    [$owner, $ownerProcessKey] = ownerSendBus('send-batch-owner', function (OwnerCommand $command) use (&$seqs) {
        $seqs[] = $command->payload['seq'];

        return ['ok' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerSendGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerSendBus('send-batch-caller');
    $this->streams[] = $callerProcessKey;

    $caller->send($this->resourceId, 'arena-input', ['seq' => 1]);
    $caller->send($this->resourceId, 'arena-input', ['seq' => 2]);
    $caller->send($this->resourceId, 'arena-input', ['seq' => 3]);

    ownerSendDrain($owner);

    expect($seqs)->toBe([1, 2, 3]);
});

/**
 * The two ways remoteOwner() can name a process that is not another worker.
 * Both have to end somewhere other than "write it to a stream", and they end in
 * OPPOSITE places: our own key is this worker's work to do, and an unusable key
 * is a command with nowhere to go.
 */
test('a send whose owner record names this process runs here rather than being written to a stream', function () {
    // The bus below boots as worker 0 of this instance, so its process key is
    // this string; the stub hands it back as the resource's owner.
    $ownProcessKey = 'send-self:worker:0';

    $router = new OwnerSendStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => ['process_key' => $ownProcessKey]];

    $ran = 0;
    [$bus, $processKey] = ownerSendBus('send-self', function () use (&$ran) {
        $ran++;

        return ['ok' => true];
    }, $router);
    $this->streams[] = $processKey;

    expect($processKey)->toBe($ownProcessKey);

    $bus->send($this->resourceId, 'arena-input', []);

    expect($ran)->toBe(1)
        ->and(ownerSendStreamMessages($processKey))->toBe([]);
});

test('a send whose owner record carries no usable process key is dropped, not run here', function () {
    $router = new OwnerSendStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => ['process_key' => '']];

    Log::shouldReceive('warning')->once();

    [$bus, $processKey] = ownerSendBus('send-blank-owner', fn () => throw new RuntimeException('an unroutable command must not execute here'), $router);
    $this->streams[] = $processKey;

    $bus->send($this->resourceId, 'arena-input', []);

    expect(ownerSendStreamMessages($processKey))->toBe([]);
});

/**
 * And an owner claim that is not an owner record at all. currentOwner() decodes
 * whatever is in the Redis key, so "not an array" is reachable from one bad
 * value in `lightspeed:resource-owner:*`, and the shape check in front of it has
 * to stop the read rather than let a string be subscripted.
 */
test('a send whose owner claim is not a record at all is dropped without throwing', function () {
    $router = new OwnerSendStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => 'not-an-owner-record'];

    Log::shouldReceive('warning')->once();

    [$bus, $processKey] = ownerSendBus('send-junk-owner', fn () => throw new RuntimeException('an unroutable command must not execute here'), $router);
    $this->streams[] = $processKey;

    $bus->send($this->resourceId, 'arena-input', []);

    expect(ownerSendStreamMessages($processKey))->toBe([]);
});

test('send returns without waiting on the owner', function () {
    [, $ownerProcessKey] = ownerSendBus('send-timing-owner');
    $this->streams[] = $ownerProcessKey;

    ownerSendGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerSendBus('send-timing-caller');
    $this->streams[] = $callerProcessKey;

    // Nothing drains the owner's stream here, so every one of these is the
    // worst case for a forward: the owner never answers at all. A forward would
    // sit out `blocking_wait_timeout_ms` (250ms shipped) on each one.
    $samples = [];
    for ($i = 0; $i < 20; $i++) {
        $startedAt = hrtime(true);
        $caller->send($this->resourceId, 'arena-input', ['seq' => $i]);
        $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    sort($samples);
    $median = $samples[intdiv(count($samples), 2)];

    // Derived, not a magic number: one poll of the response key sleeps
    // `wait_interval_us`, so a send that is still polling cannot come in under
    // one of those. The flat 5ms is the brief's ceiling, and both have to hold.
    $onePollMs = (int) config('lightspeed.owner_commands.wait_interval_us') / 1000;

    expect($median)->toBeLessThan(
        min(5.0, $onePollMs),
        "send() took {$median}ms at the median, which is not a write-and-return",
    );
});

test('a sent command takes no write lease', function () {
    $leaseKey = $this->leaseKey;
    $leasedDuringHandler = null;

    [$owner, $ownerProcessKey] = ownerSendBus('send-lease-owner', function () use ($leaseKey, &$leasedDuringHandler) {
        // Read INSIDE the handler. The lease is acquired and released around the
        // dispatch, so a check afterwards would see no key either way and prove
        // nothing.
        $leasedDuringHandler = (int) Redis::connection()->exists($leaseKey) === 1;

        return ['ok' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerSendGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerSendBus('send-lease-caller');
    $this->streams[] = $callerProcessKey;

    $caller->send($this->resourceId, 'arena-input', ['seq' => 1]);
    ownerSendDrain($owner);

    expect($leasedDuringHandler)->toBeFalse('a sent command held the resource write lease it is documented not to take');
});

test('a sent command whose resource is leased elsewhere still executes', function () {
    $ran = false;
    [$owner, $ownerProcessKey] = ownerSendBus('send-contended-owner', function () use (&$ran) {
        $ran = true;

        return ['ok' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerSendGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerSendBus('send-contended-caller');
    $this->streams[] = $callerProcessKey;

    $otherWriter = new ResourceRouter(app('config'), new WorkerContext(app('config')));
    $otherWriter->acquireWriteLease($this->resourceId);

    $caller->send($this->resourceId, 'arena-input', []);
    ownerSendDrain($owner);

    expect($ran)->toBeTrue('a sent command was refused by a lease it is documented not to take');
});

/**
 * The other half of the same field: a FORWARDED command still gets its answer.
 * The reply is skipped on an explicit false and on nothing else, so an entry
 * written by a worker that predates the field is answered exactly as before.
 */
test('a command that does not say otherwise is still answered', function () {
    [$owner, $ownerProcessKey] = ownerSendBus('send-compat-owner', fn () => ['ok' => true, 'v' => 9]);
    $this->streams[] = $ownerProcessKey;

    $requestId = 'compat-'.bin2hex(random_bytes(8));
    $message = json_encode([
        'request_id' => $requestId,
        'resource_id' => '',
        'command' => 'apply-edit',
        'payload' => [],
        'origin_process_key' => 'caller-worker',
        'target_process_key' => $ownerProcessKey,
        'issued_at' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    RedisStreams::add(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$ownerProcessKey}",
        [
            'message' => $message,
            'signature' => hash_hmac(
                'sha256',
                'lightspeed.owner-command.v2'.':'.strlen($message).':'.$message,
                (string) config('lightspeed.reverb_compat.app_secret'),
            ),
        ],
        1000,
    );

    try {
        ownerSendDrain($owner);

        expect(Redis::connection()->exists("lightspeed:owner-command-response:{$requestId}"))->toBe(1);
    } finally {
        Redis::connection()->del("lightspeed:owner-command-response:{$requestId}");
    }
});

test('a send whose resource has no resolvable owner is dropped and reported', function () {
    $router = new OwnerSendStubRouter(app('config'), new WorkerContext(app('config')));

    // The residual case: the claim won nothing and could not read an owner back
    // either, which is Redis failing to answer usefully.
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => null];

    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    [$bus, $processKey] = ownerSendBus('send-unowned', fn () => throw new RuntimeException('nothing should execute'), $router);
    $this->streams[] = $processKey;

    $bus->send($this->resourceId, 'arena-input', ['seq' => 1]);

    expect($logged['message'])->toContain('no resolvable owner')
        ->and($logged['context']['resource_id'])->toBe($this->resourceId)
        ->and($logged['context']['command'])->toBe('arena-input')
        // The line has to say the command is GONE. An operator who reads
        // "refused" without it is entitled to assume it is retried somewhere.
        ->and($logged['context']['note'])->toContain('dropped');
});

test('send runs locally when the bus is disabled, because there is nowhere else', function () {
    config()->set('lightspeed.owner_commands.enabled', false);

    $ran = 0;
    [$bus, $processKey] = ownerSendBus('send-disabled', function () use (&$ran) {
        $ran++;

        return ['ok' => true];
    });
    $this->streams[] = $processKey;

    $bus->send($this->resourceId, 'arena-input', []);

    // ONCE, not "at least once": falling out of the disabled branch into the
    // routing below it dispatches the same command a second time.
    expect($ran)->toBe(1)
        ->and(ownerSendStreamMessages($processKey))->toBe([]);
});
