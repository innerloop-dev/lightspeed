<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Workers\WorkerContext;

/**
 * WHICH COMMANDS TAKE THE WRITE LEASE, AND WHAT THE LOST-LEASE REPORT SAYS.
 *
 * OwnerCommandBusTest covers what happens to a command that holds, loses, or
 * cannot get the lease. What it does not cover is the decision ABOVE that: a
 * command with no resource id has nothing to serialize on and must run
 * unleased, and an operator who turned write leases off must actually get the
 * unleased path rather than a lease taken anyway. Both mistakes are invisible
 * from a passing result: the command still runs and still answers, it is just
 * serialized against the wrong thing, or against everything.
 */
function ownerMutLeaseBus(callable $executor): OwnerCommandBus
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

function ownerMutLeaseExecute(OwnerCommandBus $bus, string $resourceId): ?array
{
    $execute = new ReflectionMethod($bus, 'executeCommand');
    $execute->setAccessible(true);

    return $execute->invoke($bus, new OwnerCommand(
        requestId: 'owner-mut-lease-request',
        resourceId: $resourceId,
        command: 'apply-edit',
        payload: [],
        originProcessKey: 'caller:worker:0',
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

    // The lease of the resource that does not exist, which is the key a
    // command with no resource id must never touch.
    Redis::connection()->del('lightspeed:resource-write::lease');
});

test('a command with no resource id runs even while the empty resource is leased', function () {
    // "No resource id" means there is nothing to serialize on, not "serialize
    // on the empty string". Taking that lease would put every resource-less
    // command in the application behind one Redis key, so a single slow one
    // blocks all the others for the length of its TTL.
    Redis::connection()->set('lightspeed:resource-write::lease', 'another-holders-token', 'EX', 10);

    $bus = ownerMutLeaseBus(fn () => ['ok' => true, 'result' => 'unleased']);

    expect(ownerMutLeaseExecute($bus, ''))->toBe(['ok' => true, 'result' => 'unleased']);
});

test('with write leases off a leased resource is still executed', function () {
    // The setting says "do not take the lease", and the only way to see
    // whether it was honoured is to hold the lease elsewhere: a bus that took
    // it anyway reports contention for a resource the operator told it not to
    // serialize on.
    config()->set('lightspeed.owner_commands.write_lease', false);

    $otherWorker = new ResourceRouter(app('config'), new WorkerContext(app('config')));
    expect($otherWorker->acquireWriteLease($this->resourceId))->toBeString();

    $bus = ownerMutLeaseBus(fn () => ['ok' => true, 'result' => 'unserialized']);

    expect(ownerMutLeaseExecute($bus, $this->resourceId))->toBe(['ok' => true, 'result' => 'unserialized']);
});

test('a lost lease is reported with the process and the request as well as the resource', function () {
    // The operator has to be able to find the mutation that ran unserialized:
    // which worker ran it, and which request it answered. Without those, the
    // line says only that some resource somewhere lost its lease.
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $bus = ownerMutLeaseBus(function () {
        // Exactly what a handler slower than the lease TTL sees.
        Redis::connection()->del($this->leaseKey);

        return ['ok' => true];
    });

    ownerMutLeaseExecute($bus, $this->resourceId);

    expect($logged['context']['process_key'])->toBe((new WorkerContext(app('config')))->currentProcessKey())
        ->and($logged['context']['request_id'])->toBe('owner-mut-lease-request');
});
