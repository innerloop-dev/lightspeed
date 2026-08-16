<?php

use Lightspeed\Workers\WorkerContext;
use Lightspeed\Workers\WorkerHealth;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The identity payload every coordination key in the package is written under,
 * and the one sentence a failed worker start leaves behind.
 *
 * tests/Unit/ServerIdentityTest.php pins WHICH pid the instance id is derived
 * from. This file pins the shape either side of that: what a half-bound
 * identity answers, which fields the payload carries, and what a health check
 * is told when a worker could not come up.
 */

function coreMutWorkerContext(): WorkerContext
{
    return new WorkerContext(app('config'));
}

function coreMutBareSwooleServer(): SwooleServer
{
    return (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor();
}

/** Put one private field of a worker context into a state boot() alone cannot reach. */
function coreMutSetContextField(WorkerContext $context, string $field, mixed $value): void
{
    $handle = new ReflectionProperty(WorkerContext::class, $field);
    $handle->setAccessible(true);
    $handle->setValue($context, $value);
}

// ---------------------------------------------------------------------------
// The worker key.
// ---------------------------------------------------------------------------

test('a context that was never booted has no worker key at all', function () {
    // The absence is what makes currentProcessKey() fall back to the external
    // identity. A worker key built out of nulls would be `:worker:`, which is
    // a real-looking key that every process outside a worker would share.
    expect(coreMutWorkerContext()->workerKey())->toBeNull();
});

test('half an identity is no identity: either half missing means no worker key', function () {
    // Worker id zero is a legitimate worker, so the guard cannot lean on
    // truthiness, and it has to refuse when EITHER half is missing rather than
    // only when both are. An instance id that is silently empty produces
    // `:worker:0`, which two different servers would both publish under.
    $noInstance = coreMutWorkerContext();
    coreMutSetContextField($noInstance, 'workerId', 0);

    $noWorker = coreMutWorkerContext();
    coreMutSetContextField($noWorker, 'instanceId', 'host:4242');

    expect($noInstance->workerKey())->toBeNull()
        ->and($noWorker->workerKey())->toBeNull();
});

test('a fully bound context answers with the instance and the worker together', function () {
    // The positive control for both refusals above.
    config()->set('lightspeed.server.instance_id', 'named-by-operator');

    $context = coreMutWorkerContext();
    $context->boot(coreMutBareSwooleServer(), 3);

    expect($context->workerKey())->toBe('named-by-operator:worker:3');
});

test('an instance id configured as an empty string is not an instance id', function () {
    // An env var that is set but empty is the ordinary way this arrives.
    // Accepting it names every worker of every server `:worker:0`.
    config()->set('lightspeed.server.instance_id', '');

    $context = coreMutWorkerContext();
    $context->bindServingPid(4242);
    $context->boot(coreMutBareSwooleServer(), 0);

    expect($context->instanceId())->toBe((gethostname() ?: 'localhost').':4242');
});

// ---------------------------------------------------------------------------
// The payload.
// ---------------------------------------------------------------------------

test('the identity payload carries every field a reader outside the process needs', function () {
    // This array is what ConnectionRegistry, the presence store and the owner
    // command bus all write into Redis, and it is the only thing a probe, a
    // relay consumer or an operator tool has to go on. A dropped field is a
    // question those readers can no longer answer, with nothing to show that
    // it used to be answerable.
    config()->set('lightspeed.server.instance_id', 'named-by-operator');

    $context = coreMutWorkerContext();
    $context->boot(coreMutBareSwooleServer(), 2);

    $identity = $context->currentIdentity('socket-connect');

    expect($identity['process_key'])->toBe('named-by-operator:worker:2')
        ->and($identity['instance_id'])->toBe('named-by-operator')
        ->and($identity['worker_id'])->toBe(2)
        ->and($identity['pid'])->toBe(getmypid())
        ->and($identity['reason'])->toBe('socket-connect')
        ->and($identity['claimed_at'])->toBeString()
        ->and(array_keys($identity))->toBe([
            'process_key',
            'instance_id',
            'worker_id',
            'pid',
            'reason',
            'claimed_at',
        ]);
});

test('an unbooted process still publishes a real identity, keyed on its own pid', function () {
    // The fallback is a real identity and not a placeholder: HTTP-only
    // requests, artisan probes and tests legitimately run outside a booted
    // worker and still need a stable key.
    $identity = coreMutWorkerContext()->currentIdentity('socket-sweep');

    expect($identity['process_key'])->toBe('external:'.getmypid())
        ->and($identity['instance_id'])->toBeNull()
        ->and($identity['worker_id'])->toBeNull()
        ->and($identity['reason'])->toBe('socket-sweep');
});

// ---------------------------------------------------------------------------
// What a failed start leaves behind.
// ---------------------------------------------------------------------------

test('a failed worker start is summarised as its class and its message', function () {
    // This string is written into a /healthz body that a health checker logs,
    // so it is the class and the message and deliberately not the trace: a
    // stack trace there is a detail nobody reads and a shape somebody could
    // parse. Both halves are load bearing, because "RedisException" without
    // the message names no cause and the message without the class often
    // names no component.
    $health = new WorkerHealth();

    $health->markFailed(new \RedisException('Connection refused'));

    expect($health->failure())->toBe('RedisException: Connection refused')
        ->and($health->isReady())->toBeFalse();
});

test('a worker that starts cleanly reports ready and carries no failure', function () {
    $health = new WorkerHealth();
    $health->markFailed(new \RedisException('Connection refused'));

    $health->markReady();

    expect($health->isReady())->toBeTrue()
        ->and($health->failure())->toBeNull();
});
