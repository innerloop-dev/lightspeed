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
 * FORWARDING WITHOUT A REPLY: THE PATH THAT DOES NOT WAIT.
 *
 * forwardIfOwnedByAnotherProcess() writes a command and then polls a response
 * key, and with `enable_coroutine` off that poll is a usleep() on the one event
 * loop serving every connection the worker holds. forwardWithoutReply() exists for the callers
 * that have no use for the answer, so what has to be proven about it is not just
 * that the command arrives: it is that the caller does not pay for it, and that
 * nothing about WHO is allowed to execute a command got cheaper along the way.
 *
 * So: it runs the handler when this worker owns the resource, it reaches the
 * owner's handler when another worker does, it leaves no response key behind for
 * nobody to read, it takes no write lease, and it comes back in a time that is
 * provably not a poll. Plus the two edges that only forwardWithoutReply() has: a resource with
 * no resolvable owner, and a disabled bus.
 *
 * These tests drive the private drain directly, exactly as the other owner-bus
 * tests do and for the same reason: the public drain needs a listening Swoole
 * server, which a unit test has no business starting.
 */
class OwnerNoReplyServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}

/** A router that answers with whatever owner claim a test wants to hand the bus. */
class OwnerNoReplyStubRouter extends ResourceRouter
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
function ownerNoReplyBus(string $instanceId, ?callable $executor = null, ?ResourceRouter $router = null): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext = new WorkerContext($config);
    $workerContext->boot(OwnerNoReplyServer::make(), 0);

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
    $attached->setValue($bus, OwnerNoReplyServer::make());

    return [$bus, $workerContext->currentProcessKey()];
}

function ownerNoReplyDrain(OwnerCommandBus $bus): void
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
 * forwardWithoutReply() actually does before it writes, and a timing test that skipped it would
 * be timing something else.
 */
function ownerNoReplyGiveOwnershipTo(string $resourceId, string $processKey): void
{
    Redis::connection()->set(
        "lightspeed:resource-owner:{$resourceId}",
        json_encode(['process_key' => $processKey, 'instance_id' => 'owner', 'worker_id' => 0], JSON_THROW_ON_ERROR),
        'EX',
        60,
    );
}

/** @return array<int, array<string, mixed>> the decoded messages on a process's stream */
function ownerNoReplyStreamMessages(string $processKey): array
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

test('forwardWithoutReply runs the handler here when this worker owns the resource', function () {
    // A local send routes nothing and drops nothing, so it has nothing to
    // report. Asserting that pins the early return that would otherwise fall
    // through into the unroutable branch below it.
    Log::shouldReceive('warning')->never();

    $handled = [];
    [$bus, $processKey] = ownerNoReplyBus('send-local', function (OwnerCommand $command) use (&$handled) {
        $handled[] = $command;

        return ['ok' => true];
    });
    $this->streams[] = $processKey;

    // Nobody owns it yet, so the claim inside forwardWithoutReply() takes it for this process.
    $bus->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 7]);

    expect($handled)->toHaveCount(1)
        ->and($handled[0]->command)->toBe('arena-input')
        ->and($handled[0]->resourceId)->toBe($this->resourceId)
        ->and($handled[0]->payload)->toBe(['seq' => 7])
        ->and($handled[0]->expectsReply)->toBeFalse();

    // And it went nowhere near a stream: local is local.
    expect(ownerNoReplyStreamMessages($processKey))->toBe([]);
});

test('forwardWithoutReply from a non-owner reaches the owner handler and writes no reply', function () {
    $handled = [];
    [$owner, $ownerProcessKey] = ownerNoReplyBus('send-owner', function (OwnerCommand $command) use (&$handled) {
        $handled[] = $command;

        return ['ok' => true, 'applied' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerNoReplyBus('send-caller', fn () => throw new RuntimeException('the caller must not execute a remote command'));
    $this->streams[] = $callerProcessKey;

    $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 42, 'th' => true]);

    // It landed on the OWNER's stream, addressed to the owner, marked as
    // wanting no reply.
    $messages = ownerNoReplyStreamMessages($ownerProcessKey);
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['target_process_key'])->toBe($ownerProcessKey)
        ->and($messages[0]['origin_process_key'])->toBe($callerProcessKey)
        ->and($messages[0]['expects_reply'])->toBeFalse();

    $requestId = $messages[0]['request_id'];

    ownerNoReplyDrain($owner);

    expect($handled)->toHaveCount(1)
        ->and($handled[0]->command)->toBe('arena-input')
        ->and($handled[0]->payload)->toBe(['seq' => 42, 'th' => true])
        ->and($handled[0]->originProcessKey)->toBe($callerProcessKey);

    // THE POINT: no response entry. Nobody is polling for one, and every one
    // written would sit in Redis for its whole TTL with no reader to delete it.
    expect(Redis::connection()->exists("lightspeed:owner-command-response:{$requestId}"))->toBe(0);
});

/**
 * THE BYTES OF A FORWARDED COMMAND DID NOT CHANGE, which is the one claim on
 * this branch that nothing else can check.
 *
 * Both ends of the signing scheme are Lightspeed, so a process talking to itself
 * cannot notice that the encoding moved: only a fleet MID-DEPLOY notices, by
 * refusing every command the other half sends it. `expects_reply` is therefore
 * written only when it is false. Writing it unconditionally would work perfectly
 * in every test here and break owner routing between two versions of the same
 * application for the length of a rolling deploy, which is exactly the failure
 * OwnerCommandSigningTest exists to stop and exactly the one a passing suite
 * hides.
 *
 * So the assertion is the absence of a key, not the value of one.
 */
test('a forwarded command carries no expects_reply field at all', function () {
    [, $ownerProcessKey] = ownerNoReplyBus('forward-bytes-owner');
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    // Nothing drains the owner here, so the forward will time out; the entry it
    // wrote on the way is what this test is about.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 1);
    config()->set('lightspeed.owner_commands.wait_interval_us', 0);

    [$caller, $callerProcessKey] = ownerNoReplyBus('forward-bytes-caller');
    $this->streams[] = $callerProcessKey;

    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);

    $messages = ownerNoReplyStreamMessages($ownerProcessKey);
    expect($messages)->toHaveCount(1)
        ->and(array_key_exists('expects_reply', $messages[0]))->toBeFalse(
            'a forwarded command now carries a field it did not carry before, so a worker on the old build refuses it',
        );

    // And the field is present, and false, on a no-reply one: the absence above is
    // the old shape being preserved, not the flag failing to be written.
    $caller->forwardWithoutReply($this->resourceId, 'arena-input', []);

    $messages = ownerNoReplyStreamMessages($ownerProcessKey);
    expect($messages)->toHaveCount(2)
        ->and(array_key_exists('expects_reply', $messages[1]))->toBeTrue()
        ->and($messages[1]['expects_reply'])->toBeFalse();
});

/**
 * A drain is a BATCH, and a no-reply entry has to step over the response write
 * without stepping out of the loop. Skipping the write with a break instead of a
 * continue drops every command queued behind the first sent one, which at input
 * rates is most of them, and nothing about a single-entry test can see it.
 */
test('a no-reply command does not abandon the entries queued behind it', function () {
    $seqs = [];
    [$owner, $ownerProcessKey] = ownerNoReplyBus('send-batch-owner', function (OwnerCommand $command) use (&$seqs) {
        $seqs[] = $command->payload['seq'];

        return ['ok' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerNoReplyBus('send-batch-caller');
    $this->streams[] = $callerProcessKey;

    $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 1]);
    $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 2]);
    $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 3]);

    ownerNoReplyDrain($owner);

    expect($seqs)->toBe([1, 2, 3]);
});

/**
 * The two ways remoteOwner() can name a process that is not another worker.
 * Both have to end somewhere other than "write it to a stream", and they end in
 * OPPOSITE places: our own key is this worker's work to do, and an unusable key
 * is a command with nowhere to go.
 */
test('a forwardWithoutReply whose owner record names this process runs here rather than being written to a stream', function () {
    // The bus below boots as worker 0 of this instance, so its process key is
    // this string; the stub hands it back as the resource's owner.
    $ownProcessKey = 'send-self:worker:0';

    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => ['process_key' => $ownProcessKey]];

    $ran = 0;
    [$bus, $processKey] = ownerNoReplyBus('send-self', function () use (&$ran) {
        $ran++;

        return ['ok' => true];
    }, $router);
    $this->streams[] = $processKey;

    expect($processKey)->toBe($ownProcessKey);

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    expect($ran)->toBe(1)
        ->and(ownerNoReplyStreamMessages($processKey))->toBe([]);
});

test('a forwardWithoutReply whose owner record carries no usable process key is dropped, not run here', function () {
    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => ['process_key' => '']];

    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $ran = 0;
    [$bus, $processKey] = ownerNoReplyBus('send-blank-owner', function () use (&$ran) {
        $ran++;

        return ['ok' => true];
    }, $router);
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    // DROPPED, not run here. An owner record naming a process this worker
    // cannot address is not the same as this worker owning the resource, and
    // treating it as local would execute a command on a worker that does not
    // own it, which is the one thing the owner bus exists to prevent. The
    // headline is asserted too: a handler that throws is now also reported, so
    // "exactly one warning" on its own no longer tells the two apart.
    expect($ran)->toBe(0)
        ->and($logged['message'])->toBe('Lightspeed dropped a no-reply owner command: no resolvable owner')
        ->and(ownerNoReplyStreamMessages($processKey))->toBe([]);
});

/**
 * THE RATE LIMIT IS A LIMIT, NOT A MUTE. A no-reply command has no caller, so
 * these lines are the only evidence that a resource has stopped working, and
 * failing to expire an entry turns "one line a minute" into "one line ever" —
 * which looks identical in any test that only reports twice.
 */
test('a dropped resource reports again once its rate-limit window has passed', function () {
    config()->set('lightspeed.owner_commands.no_reply_report_interval_seconds', 1);

    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => null];

    $lines = 0;
    Log::shouldReceive('warning')->andReturnUsing(function () use (&$lines): void {
        $lines++;
    });

    [$bus, $processKey] = ownerNoReplyBus('send-ratelimit', fn () => null, $router);
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);
    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    expect($lines)->toBe(1, 'the second report inside the window was not suppressed');

    // Past the window. The entry has to be swept for this to report again, and
    // that sweep is also the only thing bounding the map.
    usleep(1_100_000);
    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    expect($lines)->toBe(2, 'the resource went dark again and said nothing, because its entry never expired');
});

/**
 * And the interval is the CONFIGURED one, not a constant that happens to look
 * like it: a deployment that widens the window gets a wider window.
 */
test('the report interval and the worker come from the bus, not from a constant', function () {
    config()->set('lightspeed.owner_commands.no_reply_report_interval_seconds', 3600);

    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => null];

    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    [$bus, $processKey] = ownerNoReplyBus('send-interval', fn () => null, $router);
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    // Without the process key an operator cannot tell one worker's silence from
    // another's; without the interval they cannot tell one drop from a thousand.
    expect($logged['context']['rate_limit'])->toContain('3600')
        ->and($logged['context']['process_key'])->toBe($processKey);
});

/**
 * And an owner claim that is not an owner record at all. currentOwner() decodes
 * whatever is in the Redis key, so "not an array" is reachable from one bad
 * value in `lightspeed:resource-owner:*`, and the shape check in front of it has
 * to stop the read rather than let a string be subscripted.
 */
test('a forwardWithoutReply whose owner claim is not a record at all is dropped without throwing', function () {
    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => 'not-an-owner-record'];

    Log::shouldReceive('warning')->once();

    [$bus, $processKey] = ownerNoReplyBus('send-junk-owner', fn () => throw new RuntimeException('an unroutable command must not execute here'), $router);
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    expect(ownerNoReplyStreamMessages($processKey))->toBe([]);
});

test('forwardWithoutReply returns without waiting on the owner', function () {
    [, $ownerProcessKey] = ownerNoReplyBus('send-timing-owner');
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerNoReplyBus('send-timing-caller');
    $this->streams[] = $callerProcessKey;

    // Nothing drains the owner's stream here, so every one of these is the
    // worst case for a forward: the owner never answers at all. A forward would
    // sit out `blocking_wait_timeout_ms` (250ms shipped) on each one.
    $samples = [];
    for ($i = 0; $i < 20; $i++) {
        $startedAt = hrtime(true);
        $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => $i]);
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
        "forwardWithoutReply() took {$median}ms at the median, which is not a write-and-return",
    );
});

test('a no-reply command takes no write lease', function () {
    $leaseKey = $this->leaseKey;
    $leasedDuringHandler = null;

    [$owner, $ownerProcessKey] = ownerNoReplyBus('send-lease-owner', function () use ($leaseKey, &$leasedDuringHandler) {
        // Read INSIDE the handler. The lease is acquired and released around the
        // dispatch, so a check afterwards would see no key either way and prove
        // nothing.
        $leasedDuringHandler = (int) Redis::connection()->exists($leaseKey) === 1;

        return ['ok' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerNoReplyBus('send-lease-caller');
    $this->streams[] = $callerProcessKey;

    $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 1]);
    ownerNoReplyDrain($owner);

    expect($leasedDuringHandler)->toBeFalse('a no-reply command held the resource write lease it is documented not to take');
});

test('a no-reply command whose resource is leased elsewhere still executes', function () {
    $ran = false;
    [$owner, $ownerProcessKey] = ownerNoReplyBus('send-contended-owner', function () use (&$ran) {
        $ran = true;

        return ['ok' => true];
    });
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerNoReplyBus('send-contended-caller');
    $this->streams[] = $callerProcessKey;

    $otherWriter = new ResourceRouter(app('config'), new WorkerContext(app('config')));
    $otherWriter->acquireWriteLease($this->resourceId);

    $caller->forwardWithoutReply($this->resourceId, 'arena-input', []);
    ownerNoReplyDrain($owner);

    expect($ran)->toBeTrue('a no-reply command was refused by a lease it is documented not to take');
});

/**
 * The other half of the same field: a FORWARDED command still gets its answer.
 * The reply is skipped on an explicit false and on nothing else, so an entry
 * written by a worker that predates the field is answered exactly as before.
 */
test('a command that does not say otherwise is still answered', function () {
    [$owner, $ownerProcessKey] = ownerNoReplyBus('send-compat-owner', fn () => ['ok' => true, 'v' => 9]);
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
        ownerNoReplyDrain($owner);

        expect(Redis::connection()->exists("lightspeed:owner-command-response:{$requestId}"))->toBe(1);
    } finally {
        Redis::connection()->del("lightspeed:owner-command-response:{$requestId}");
    }
});

test('a forwardWithoutReply whose resource has no resolvable owner is dropped and reported', function () {
    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));

    // The residual case: the claim won nothing and could not read an owner back
    // either, which is Redis failing to answer usefully.
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => null];

    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    [$bus, $processKey] = ownerNoReplyBus('send-unowned', fn () => throw new RuntimeException('nothing should execute'), $router);
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 1]);

    // THE HEADLINE has to say what happened, because a headline is the whole of
    // what most operators read. The bus refused nothing here: it could not find
    // anywhere to deliver the command and threw it away, and a line that says
    // "refused" invites the reader to assume something upstream will retry it.
    expect($logged['message'])->toBe('Lightspeed dropped a no-reply owner command: no resolvable owner')
        ->and($logged['context']['resource_id'])->toBe($this->resourceId)
        ->and($logged['context']['command'])->toBe('arena-input')
        ->and($logged['context']['note'])->toContain('dropped');
});

/**
 * ONE RESOURCE GOING DARK MUST NOT HIDE BEHIND ANOTHER'S LINE. The refusal
 * report this used to borrow dedupes on the reason and keeps it for the life of
 * the worker, so the second arena to lose its owner would have been silent for
 * as long as the process lived.
 */
test('a drop is reported per resource, not once for the whole worker', function () {
    $router = new OwnerNoReplyStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = ['claimed' => false, 'local' => false, 'owner' => null];

    $resources = [];
    Log::shouldReceive('warning')
        ->twice()
        ->andReturnUsing(function (string $message, array $context) use (&$resources): void {
            $resources[] = $context['resource_id'];
        });

    [$bus, $processKey] = ownerNoReplyBus('send-two-unowned', fn () => null, $router);
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply('arena-7', 'arena-input', []);
    $bus->forwardWithoutReply('arena-9', 'arena-input', []);

    // And the same resource again is the one that is rate-limited: two lines,
    // not three.
    $bus->forwardWithoutReply('arena-7', 'arena-input', []);

    expect($resources)->toBe(['arena-7', 'arena-9']);
});

/**
 * A NO-REPLY COMMAND'S HANDLER CAN FAIL AND THERE IS NOWHERE FOR IT TO GO. A
 * forwarded command's failure lands in the response key its caller is polling.
 * A no-reply one has neither, so without this the application whose owner handler is
 * broken sees its commands quietly do nothing, on the path built to carry the
 * most of them.
 */
test('a no-reply command whose handler throws on the owning worker is reported', function () {
    [$owner, $ownerProcessKey] = ownerNoReplyBus('send-throwing-owner', function () {
        throw new RuntimeException('the arena handler exploded');
    });
    $this->streams[] = $ownerProcessKey;

    ownerNoReplyGiveOwnershipTo($this->resourceId, $ownerProcessKey);

    [$caller, $callerProcessKey] = ownerNoReplyBus('send-throwing-caller');
    $this->streams[] = $callerProcessKey;

    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $caller->forwardWithoutReply($this->resourceId, 'arena-input', ['seq' => 1]);
    ownerNoReplyDrain($owner);

    expect($logged['message'])->toBe('Lightspeed no-reply owner command failed on the owning worker')
        ->and($logged['context']['resource_id'])->toBe($this->resourceId)
        ->and($logged['context']['command'])->toBe('arena-input')
        ->and($logged['context']['error'])->toContain('the arena handler exploded')
        // The line has to say that it IS the report. A reader who assumes a
        // failed command surfaced somewhere else will go looking for a response
        // key that was never written.
        ->and($logged['context']['note'])->toContain('only report');
});

/**
 * And the same failure on the worker that happens to hold the socket. Which
 * worker a connection lands on is Swoole's choice, so a handler that throws must
 * not be silent on one path and fatal on the other: an application cannot write
 * code against a failure mode that depends on load balancing.
 */
test('a no-reply command whose handler throws locally is reported, not thrown at the caller', function () {
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    [$bus, $processKey] = ownerNoReplyBus('send-local-throwing', function () {
        throw new RuntimeException('the arena handler exploded');
    });
    $this->streams[] = $processKey;

    // Nobody owns it, so the claim inside forwardWithoutReply() takes it for this process and
    // the handler runs right here.
    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    expect($logged['message'])->toBe('Lightspeed no-reply owner command failed on the owning worker')
        ->and($logged['context']['resource_id'])->toBe($this->resourceId)
        ->and($logged['context']['error'])->toContain('the arena handler exploded');
});


test('forwardWithoutReply runs locally when the bus is disabled, because there is nowhere else', function () {
    config()->set('lightspeed.owner_commands.enabled', false);

    $ran = 0;
    [$bus, $processKey] = ownerNoReplyBus('send-disabled', function () use (&$ran) {
        $ran++;

        return ['ok' => true];
    });
    $this->streams[] = $processKey;

    $bus->forwardWithoutReply($this->resourceId, 'arena-input', []);

    // ONCE, not "at least once": falling out of the disabled branch into the
    // routing below it dispatches the same command a second time.
    expect($ran)->toBe(1)
        ->and(ownerNoReplyStreamMessages($processKey))->toBe([]);
});
