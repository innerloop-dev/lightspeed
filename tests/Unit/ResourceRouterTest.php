<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Workers\WorkerContext;

/**
 * Owner routing and the write lease are the package's coordination core: they
 * decide which worker may mutate a resource and how concurrent writers are
 * serialized. The reentrant lease in particular is easy to get subtly wrong, 
 * a token vended to the wrong holder, or a nested release dropping the Redis
 * key early, silently removes the exclusion the whole feature exists to give.
 * These tests run against a real Redis because the guarantees are Redis
 * semantics (SET NX EX, compare-and-delete), not PHP logic.
 *
 * One dimension is deliberately not covered here: the lease holder is the
 * coroutine id when a scheduler is running, so proving that two coroutines
 * sharing one router never share a lease needs a live scheduler. Running one
 * inside PHPUnit poisons the phpredis connection for every later test in the
 * process, so that case belongs in an integration probe, not this file. What
 * is covered is the property that matters just as much: two separate routers,
 * standing in for two workers, contend through Redis and never share a lease.
 */

/** Resource ids are unique per test so a shared Redis cannot be collided with. */
function routerResourceId(): string
{
    return 'lightspeed-test-'.bin2hex(random_bytes(8));
}

/**
 * A router bound to a chosen process key.
 *
 * Two routers in one PHP process would otherwise share an identity, and owner
 * claims are keyed by process key: overriding it is how a test stands in for a
 * second worker.
 */
function routerForProcess(string $processKey): ResourceRouter
{
    $workerContext = new class(app('config'), $processKey) extends WorkerContext
    {
        public function __construct(private $configRepository, private string $processKey)
        {
            parent::__construct($configRepository);
        }

        public function currentProcessKey(): string
        {
            return $this->processKey;
        }
    };

    return new ResourceRouter(app('config'), $workerContext);
}

/**
 * A router that lets a test run code in the window claimOwner() has to be
 * atomic across: between reading the current owner and refreshing its own
 * claim.
 *
 * The interleaving being reproduced is not exotic and needs no scheduler. The
 * owner key carries a TTL, and the read that says "this resource is mine" is a
 * separate round trip from the write that renews it, so any pause between them
 * is a window in which Redis can expire the key and another process can win a
 * clean SET NX. The hook is that pause, made to happen on demand instead of
 * being waited for. Everything else is the shipping code path: the failed
 * SET NX, the real read, the real refresh, and a second worker contending
 * through the same Redis.
 */
function interleavingRouterForProcess(string $processKey, Closure $betweenReadAndRefresh): ResourceRouter
{
    $workerContext = new class(app('config'), $processKey) extends WorkerContext
    {
        public function __construct(private $configRepository, private string $processKey)
        {
            parent::__construct($configRepository);
        }

        public function currentProcessKey(): string
        {
            return $this->processKey;
        }
    };

    return new class(app('config'), $workerContext, $betweenReadAndRefresh) extends ResourceRouter
    {
        public function __construct($config, $workerContext, private Closure $hook)
        {
            parent::__construct($config, $workerContext);
        }

        public function currentOwner(string $resourceId): ?array
        {
            $owner = parent::currentOwner($resourceId);

            ($this->hook)($resourceId);

            return $owner;
        }
    };
}

function forgetResource(string $resourceId): void
{
    Redis::connection()->del("lightspeed:resource-owner:{$resourceId}");
    Redis::connection()->del("lightspeed:resource-write:{$resourceId}:lease");
}

beforeEach(function () {
    // Contention must fail fast here; the shipped defaults retry for a second.
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);

    $this->resourceId = routerResourceId();
});

afterEach(function () {
    forgetResource($this->resourceId);
});

test('the first claimer owns the resource locally', function () {
    $claim = routerForProcess('worker-a')->claimOwner($this->resourceId, 'test-claim');

    expect($claim['claimed'])->toBeTrue()
        ->and($claim['local'])->toBeTrue()
        ->and($claim['owner']['process_key'])->toBe('worker-a')
        ->and($claim['owner']['reason'])->toBe('test-claim');
});

test('a claimer from another process sees the existing owner and is not local', function () {
    routerForProcess('worker-a')->claimOwner($this->resourceId);

    $claim = routerForProcess('worker-b')->claimOwner($this->resourceId);

    expect($claim['claimed'])->toBeFalse()
        ->and($claim['local'])->toBeFalse()
        ->and($claim['owner']['process_key'])->toBe('worker-a');
});

test('the owning process reclaims its own resource and stays local', function () {
    $router = routerForProcess('worker-a');
    $router->claimOwner($this->resourceId);

    $claim = $router->claimOwner($this->resourceId, 'second-pass');

    expect($claim['claimed'])->toBeTrue()
        ->and($claim['local'])->toBeTrue()
        ->and($claim['owner']['process_key'])->toBe('worker-a');
});

test('an owner claim expires so a dead worker cannot own a resource forever', function () {
    config()->set('lightspeed.resources.owner_ttl_seconds', 30);

    routerForProcess('worker-a')->claimOwner($this->resourceId);

    $ttl = (int) Redis::connection()->ttl("lightspeed:resource-owner:{$this->resourceId}");

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(30);
});

test('currentOwner reports nothing for a resource nobody has claimed', function () {
    // The negative assertion needs a positive one beside it, or it cannot tell
    // "correctly empty" from "always empty": a currentOwner() that returned
    // null unconditionally would have satisfied this test on its own.
    $router = routerForProcess('worker-a');
    $claimed = routerResourceId();

    $router->claimOwner($claimed);

    expect($router->currentOwner($claimed))->not->toBeNull()
        ->and($router->currentOwner($this->resourceId))->toBeNull();
});

test('it reads the resource id out of a channel using the configured prefix', function () {
    config()->set('lightspeed.resources.channel_prefix', 'document');
    $router = routerForProcess('worker-a');

    expect($router->resourceIdFromChannel('private-document.42'))->toBe('42')
        ->and($router->resourceIdFromChannel('presence-document.42'))->toBe('42');
});

test('it rejects channels that do not name a routed resource', function () {
    config()->set('lightspeed.resources.channel_prefix', 'document');
    $router = routerForProcess('worker-a');

    expect($router->resourceIdFromChannel('private-board.42'))->toBeNull()
        ->and($router->resourceIdFromChannel('private-document'))->toBeNull()
        ->and($router->resourceIdFromChannel('document.42'))->toBeNull()
        ->and($router->resourceIdFromChannel('public-document.42'))->toBeNull();
});

test('the write lease is held by one holder and blocks another', function () {
    $token = routerForProcess('worker-a')->acquireWriteLease($this->resourceId);

    expect($token)->toBeString()
        ->and(routerForProcess('worker-b')->acquireWriteLease($this->resourceId))->toBeNull();
});

test('a released write lease can be acquired again', function () {
    $holder = routerForProcess('worker-a');
    $token = $holder->acquireWriteLease($this->resourceId);

    expect($holder->releaseWriteLease($this->resourceId, $token))->toBeTrue()
        ->and(routerForProcess('worker-b')->acquireWriteLease($this->resourceId))->toBeString();
});

test('releasing a lease this holder does not own reports failure', function () {
    routerForProcess('worker-a')->acquireWriteLease($this->resourceId);

    expect(routerForProcess('worker-b')->releaseWriteLease($this->resourceId, 'not-my-token'))->toBeFalse();
});

test('the same holder acquiring twice gets the same lease token', function () {
    $holder = routerForProcess('worker-a');

    $outer = $holder->acquireWriteLease($this->resourceId);
    $inner = $holder->acquireWriteLease($this->resourceId);

    expect($inner)->toBe($outer);
});

test('a nested acquire does not write to Redis again', function () {
    $holder = routerForProcess('worker-a');
    $leaseKey = "lightspeed:resource-write:{$this->resourceId}:lease";

    $outer = $holder->acquireWriteLease($this->resourceId);

    // Removing the key behind the router's back makes any Redis write on the
    // nested path visible: a reentrant acquire must not recreate it.
    Redis::connection()->del($leaseKey);

    expect($holder->acquireWriteLease($this->resourceId))->toBe($outer)
        ->and(Redis::connection()->get($leaseKey))->toBeNull();
});

test('the inner release keeps the lease key so the outer level still holds it', function () {
    $holder = routerForProcess('worker-a');
    $leaseKey = "lightspeed:resource-write:{$this->resourceId}:lease";

    $token = $holder->acquireWriteLease($this->resourceId);
    $holder->acquireWriteLease($this->resourceId);

    expect($holder->releaseWriteLease($this->resourceId, $token))->toBeTrue()
        ->and(Redis::connection()->get($leaseKey))->toBe($token)
        ->and(routerForProcess('worker-b')->acquireWriteLease($this->resourceId))->toBeNull();
});

test('the outer release drops the lease key', function () {
    $holder = routerForProcess('worker-a');
    $leaseKey = "lightspeed:resource-write:{$this->resourceId}:lease";

    $token = $holder->acquireWriteLease($this->resourceId);
    $holder->acquireWriteLease($this->resourceId);
    $holder->releaseWriteLease($this->resourceId, $token);

    expect($holder->releaseWriteLease($this->resourceId, $token))->toBeTrue()
        ->and(Redis::connection()->get($leaseKey))->toBeNull();
});

test('a different holder never piggybacks on a held lease', function () {
    $holder = routerForProcess('worker-a');
    $token = $holder->acquireWriteLease($this->resourceId);
    $holder->acquireWriteLease($this->resourceId);

    $other = routerForProcess('worker-b')->acquireWriteLease($this->resourceId);

    expect($other)->toBeNull()->not->toBe($token);
});

/**
 * THE SAME QUESTION, ACROSS COROUTINES RATHER THAN ACROSS WORKERS.
 *
 * The test above uses two ROUTERS, which stand in for two processes: they have
 * separate `heldLeases` maps and contend entirely through Redis, so the
 * reentrancy check is never consulted. Dropping `$held['holder'] === $holder`
 * from acquireWriteLease() therefore survived it, and survived the whole suite
 *, while handing the same lease token to a different coroutine, which is
 * precisely the exclusion the write lease exists to provide.
 *
 * Coroutines are the case that matters, because they are ONE router. A
 * coroutine can be suspended mid-write while another runs, that is what makes
 * it a separate execution context. And both see the same map. Without the
 * holder comparison the second one finds an entry for the resource, takes the
 * reentrant branch, and is vended the token the first one is still writing
 * under. Two writers, one lease, and no Redis call on the path that would have
 * noticed.
 *
 * WHY THE SECOND CONTEXT IS CONSTRUCTED RATHER THAN SCHEDULED. This file's
 * header explains that running a real Swoole scheduler inside PHPUnit poisons
 * the phpredis connection for every later test in the process, which is why
 * this dimension was left to an integration probe. And then covered nowhere.
 * It does not need a scheduler to be reproduced. What a second coroutine
 * changes is exactly one thing: `currentHolderId()` returns a different value,
 * and the state that produces is a map entry whose recorded holder is not the
 * current one. That state is written here directly. Nothing else is simulated:
 * the lease is genuinely held in Redis, the contention is the real retry loop,
 * and the answer comes from the shipping code.
 */
test('a second coroutine sharing one router is never vended the lease another holds', function () {
    config()->set('lightspeed.resources.write_lease_retries', 2);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);

    $router = routerForProcess('worker-a');

    $token = $router->acquireWriteLease($this->resourceId);

    expect($token)->toBeString();

    // The first holder is a coroutine that is still running: its entry stays in
    // the map and its Redis key stays held, exactly as they are while it is
    // suspended mid-write.
    $held = new ReflectionProperty(ResourceRouter::class, 'heldLeases');
    $held->setAccessible(true);

    $leases = $held->getValue($router);
    $leases[$this->resourceId]['holder'] = 4242;
    $held->setValue($router, $leases);

    // And now a DIFFERENT execution context asks the same router for the same
    // resource.
    $second = $router->acquireWriteLease($this->resourceId);

    expect($second)
        ->toBeNull('a second execution context was vended the lease another one is still holding')
        ->not->toBe($token);

    // The reentrancy counter must not have moved either. A depth incremented on
    // another context's behalf turns the real holder's outer release into an
    // inner one, so the Redis key is never dropped and the resource stays
    // locked to everybody until its TTL runs out.
    expect($held->getValue($router)[$this->resourceId]['depth'])->toBe(1);
});

test('a locally tracked lease whose Redis key has expired is not vended again', function () {
    config()->set('lightspeed.resources.write_lease_ttl_seconds', 1);

    $holder = routerForProcess('worker-a');
    $token = $holder->acquireWriteLease($this->resourceId);

    // Outrun the TTL, exactly as a slow mutation does, and let another worker
    // take the resource while this one still believes it holds the lease.
    //
    // A full second past a one second TTL, not the 200ms this used to allow.
    // Redis expires a key set with `EX 1` a whole second after the SET, so
    // 1.2s left 200ms to cover the round trip, the scheduler, and a loaded CI
    // box, and a margin that thin is a test that fails for no reason.
    sleep(2);
    $other = routerForProcess('worker-b');
    expect($other->acquireWriteLease($this->resourceId))->toBeString();

    expect($holder->acquireWriteLease($this->resourceId))->toBeNull()->not->toBe($token);
});

test('it sweeps lapsed leases so the in-process map stays bounded', function () {
    // A caller that drops tokens without releasing used to leave one entry per
    // resource behind for the life of the process. A lapsed entry is dead in
    // Redis too, so any acquire now clears all of them.
    config()->set('lightspeed.resources.write_lease_ttl_seconds', 1);

    $router = routerForProcess('sweeper:worker:0');

    $held = function (ResourceRouter $router): array {
        $property = new ReflectionProperty($router, 'heldLeases');
        $property->setAccessible(true);

        return $property->getValue($router);
    };

    foreach (range(1, 5) as $ignored) {
        expect($router->acquireWriteLease(routerResourceId()))->toBeString();
    }

    expect($held($router))->toHaveCount(5);

    // Let them lapse in Redis, then acquire anything at all. Two seconds
    // against a one second TTL, so the margin is as large as the interval
    // being waited out.
    sleep(2);
    $trigger = routerResourceId();
    $token = $router->acquireWriteLease($trigger);

    expect($held($router))->toHaveCount(1);

    $router->releaseWriteLease($trigger, (string) $token);
});

/**
 * TWO PROCESSES, ONE RESOURCE, BOTH CONVINCED THEY OWN IT.
 *
 * claimOwner()'s refresh used to be a read followed by an unconditional write:
 * SET NX fails, GET says the owner is me, so SET (no NX) renews it. Those are
 * two round trips, and the key between them is a key with a TTL on it. Expire
 * it in that window and another worker takes the resource with a clean SET NX
 * -- and then the refresh overwrites the claim it never looked at again.
 *
 * What makes this worth a test rather than a comment is the failure it
 * produces. Both processes are told `claimed`, `local`, and
 * forwardIfOwnedByAnotherProcess() then answers null on both, so both handle
 * writes for the resource in their own worker. The single-writer guarantee is
 * the entire reason this class exists, and it is gone with nothing anywhere
 * reporting that it went.
 *
 * The interleaving is constructed rather than asserted around: the expiry and
 * the competing claim happen inside the window, once, from the hook. See
 * interleavingRouterForProcess().
 */
test('an owner refresh cannot overwrite a claim another process won in the expiry window', function () {
    $ownerKey = "lightspeed:resource-owner:{$this->resourceId}";
    $interleaved = false;

    $victim = interleavingRouterForProcess('worker-a', function (string $resourceId) use (&$interleaved, $ownerKey): void {
        // Once: the window opens on the first read, and a retry must find the
        // world as the competing worker left it.
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        // The key's TTL runs out, exactly as it does when a claim ages past
        // `owner_ttl_seconds` while a slow round trip is in flight...
        Redis::connection()->del($ownerKey);

        // ...and another worker claims the resource cleanly, with the SET NX
        // this one just failed.
        routerForProcess('worker-b')->claimOwner($resourceId, 'competing-claim');
    });

    // The claim that puts worker-a in the map to begin with, so the next one
    // takes the refresh path.
    $victim->claimOwner($this->resourceId, 'first-claim');

    $claim = $victim->claimOwner($this->resourceId, 'refresh');

    expect($interleaved)->toBeTrue('the interleaving never happened, so this test proved nothing');

    // Worker-b holds the key and was told so. Worker-a must be told the truth
    // about a resource it no longer owns, whatever it believed a round trip
    // ago.
    expect($claim['claimed'])->toBeFalse('two processes were both told they own the same resource')
        ->and($claim['local'])->toBeFalse()
        ->and($claim['owner']['process_key'])->toBe('worker-b');

    expect($victim->currentOwner($this->resourceId)['process_key'])
        ->toBe('worker-b', 'the refresh overwrote a claim it had not verified');
});
