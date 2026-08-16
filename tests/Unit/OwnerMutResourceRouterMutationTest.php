<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Workers\WorkerContext;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * THE ROUND TRIPS THE CLAIM AND THE LEASE ARE ALLOWED TO MAKE.
 *
 * ResourceRouterTest pins the OUTCOMES of owner claims and write leases: who
 * owns what, who is refused, and how nesting unwinds. Several of the decisions
 * that produce those outcomes are invisible to an outcome assertion, because
 * the wrong path arrives at the same answer by a longer route: a claim won
 * cleanly and then re-read and refreshed reports the same thing, a foreign
 * owner discovered once and then contended for twice more reports the same
 * thing, and a retry loop that gives up early reports the same thing as one
 * that never needed the retry.
 *
 * They are not the same in production. Every one of those is Redis round trips
 * on the event loop that serves every connection this worker holds, taken per
 * claim, and the retry that is skipped is the one that recovers from a lost
 * race. So these tests count the reads and pin the bounds, alongside the TTLs
 * and the retry ceilings, which are the numbers an operator's config lands on.
 */
class OwnerMutRouterContext extends WorkerContext
{
    public function __construct(private $configRepository, private string $ownProcessKey)
    {
        parent::__construct($configRepository);
    }

    public function currentProcessKey(): string
    {
        return $this->ownProcessKey;
    }
}

/**
 * A router that counts owner reads, can be made blind to the owner key, and can
 * run a test's code inside the window between reading the owner and refreshing
 * it.
 */
class OwnerMutRouterCounting extends ResourceRouter
{
    public int $ownerReads = 0;

    public bool $blind = false;

    public ?Closure $betweenReadAndRefresh = null;

    public function __construct($config, $workerContext)
    {
        parent::__construct($config, $workerContext);
    }

    public function currentOwner(string $resourceId): ?array
    {
        $this->ownerReads++;

        $owner = $this->blind ? null : parent::currentOwner($resourceId);

        if ($this->betweenReadAndRefresh !== null) {
            ($this->betweenReadAndRefresh)($resourceId);
        }

        return $owner;
    }
}

function ownerMutRouterFor(string $processKey): ResourceRouter
{
    return new ResourceRouter(app('config'), new OwnerMutRouterContext(app('config'), $processKey));
}

function ownerMutRouterCountingFor(string $processKey): OwnerMutRouterCounting
{
    return new OwnerMutRouterCounting(app('config'), new OwnerMutRouterContext(app('config'), $processKey));
}

function ownerMutRouterDrive(ResourceRouter $router, string $method, array $arguments = []): mixed
{
    $handle = new ReflectionMethod($router, $method);
    $handle->setAccessible(true);

    return $handle->invokeArgs($router, $arguments);
}

/** Take one key out of a config section, so the code's own default applies. */
function ownerMutRouterForgetConfig(string $section, string $key): void
{
    $values = config($section);
    unset($values[$key]);

    config()->set($section, $values);
}

beforeEach(function () {
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);

    $this->resourceId = 'lightspeed-test-'.bin2hex(random_bytes(8));
    $this->ownerKey = "lightspeed:resource-owner:{$this->resourceId}";
    $this->leaseKey = "lightspeed:resource-write:{$this->resourceId}:lease";
});

afterEach(function () {
    Redis::connection()->del($this->ownerKey);
    Redis::connection()->del($this->leaseKey);
});

test('an identity that cannot be encoded refuses the claim instead of writing an empty owner', function () {
    // json_encode answers false rather than throwing unless told to throw, and
    // false is a perfectly writable Redis value: the owner key would exist,
    // decode to nothing, and every reader would see an unowned resource that
    // cannot be claimed because the key is taken.
    $config = app('config');
    $config->set('lightspeed.server.instance_id', "instance-\xB1\x31");

    $workerContext = new WorkerContext($config);
    $workerContext->boot((new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor(), 0);

    $router = new ResourceRouter($config, $workerContext);

    expect(fn () => $router->claimOwner($this->resourceId, 'test'))->toThrow(JsonException::class);
    expect(Redis::connection()->exists($this->ownerKey))->toBe(0);
});

test('a claim won cleanly costs exactly one round trip', function () {
    // The SET NX already says this process owns the resource. Reading the key
    // back and refreshing it is two more round trips on the event loop, per
    // claim, to learn what the write just decided.
    $router = ownerMutRouterCountingFor('router-clean-claim');

    $claim = $router->claimOwner($this->resourceId, 'test');

    expect($claim['claimed'])->toBeTrue()
        ->and($claim['local'])->toBeTrue()
        ->and($router->ownerReads)->toBe(0);
});

test('a resource owned elsewhere is reported after a single read', function () {
    // The owner is known the moment it is read, and contending for it twice
    // more cannot change the answer: the key is held and its TTL has not
    // moved. What it does change is the cost of every forwarded command.
    ownerMutRouterFor('router-foreign-owner')->claimOwner($this->resourceId, 'test');

    $router = ownerMutRouterCountingFor('router-asking');
    $claim = $router->claimOwner($this->resourceId, 'test');

    expect($claim['claimed'])->toBeFalse()
        ->and($claim['owner']['process_key'])->toBe('router-foreign-owner')
        ->and($router->ownerReads)->toBe(1);
});

test('a claim that keeps losing the race gives up after three attempts', function () {
    // Someone else holds the key and this process cannot read who: every
    // attempt fails its SET NX and reads nothing back. The loop has to be
    // bounded, and it has to actually retry, because the case it exists for is
    // a key that expires between the failed write and the read.
    ownerMutRouterFor('router-holder')->claimOwner($this->resourceId, 'test');

    $router = ownerMutRouterCountingFor('router-blind');
    $router->blind = true;

    $claim = $router->claimOwner($this->resourceId, 'test');

    // Three reads inside the loop, and the final one that reports whatever the
    // key says now.
    expect($router->ownerReads)->toBe(4)
        ->and($claim['claimed'])->toBeFalse()
        ->and($claim['local'])->toBeFalse();
});

test('a refresh that loses its key retakes the claim on the next attempt', function () {
    // The compare-and-set refuses to overwrite a claim it did not verify, and
    // then the loop has to try again: giving up instead would report the
    // resource as owned by nobody while this process is perfectly able to
    // claim it, and the caller would forward a command to an owner that is not
    // there.
    $router = ownerMutRouterCountingFor('router-refresh-loses');

    $router->claimOwner($this->resourceId, 'first-claim');

    $interleaved = false;
    $router->betweenReadAndRefresh = function () use (&$interleaved): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        // The claim's TTL runs out inside the window between the read and the
        // refresh, so the compare-and-set finds no key at all.
        Redis::connection()->del($this->ownerKey);
    };

    $claim = $router->claimOwner($this->resourceId, 'refresh');

    expect($interleaved)->toBeTrue('the interleaving never happened, so this test proved nothing')
        ->and($claim['claimed'])->toBeTrue('a lost refresh gave up instead of retrying the claim')
        ->and($claim['local'])->toBeTrue()
        ->and($claim['owner']['process_key'])->toBe('router-refresh-loses');
});

test('an owner claim lives for thirty seconds unless configured otherwise', function () {
    ownerMutRouterForgetConfig('lightspeed.resources', 'owner_ttl_seconds');

    ownerMutRouterFor('router-owner-ttl')->claimOwner($this->resourceId, 'test');

    expect((int) Redis::connection()->ttl($this->ownerKey))
        ->toBeGreaterThan(29)
        ->toBeLessThanOrEqual(30);
});

test('a write lease lives for ten seconds unless configured otherwise', function () {
    // The lease is never renewed while a handler runs, so this number is the
    // ceiling on how long a mutation can take and still be serialized.
    ownerMutRouterForgetConfig('lightspeed.resources', 'write_lease_ttl_seconds');

    expect(ownerMutRouterFor('router-lease-ttl')->acquireWriteLease($this->resourceId))->toBeString();

    expect((int) Redis::connection()->ttl($this->leaseKey))
        ->toBeGreaterThan(9)
        ->toBeLessThanOrEqual(10);
});

test('a lease token is sixteen bytes of randomness, hex encoded', function () {
    // The token is the only thing that distinguishes this holder from the next
    // one at release time, and the release is a compare-and-delete: a shorter
    // token is a weaker guarantee that the lease being dropped is this one.
    $token = ownerMutRouterFor('router-token')->acquireWriteLease($this->resourceId);

    expect($token)->toBeString()
        ->and(strlen($token))->toBe(32);
});

test('a retry count of zero acquires nothing at all, even an unheld lease', function () {
    // Zero attempts means zero, not one: the loop is the only thing that
    // writes the lease key, so an off-by-one at its edge is a lease taken by a
    // caller that asked not to contend.
    config()->set('lightspeed.resources.write_lease_retries', 0);

    expect(ownerMutRouterFor('router-zero-retries')->acquireWriteLease($this->resourceId))->toBeNull()
        ->and(Redis::connection()->exists($this->leaseKey))->toBe(0);
});

test('the blocking ceiling never removes the one attempt every caller is owed', function () {
    // `blocking_write_lease_retries` bounds how long a non-yielding worker
    // sits on the event loop, but zero of it would mean no caller can ever
    // take a lease at all, which turns every owner command into "busy".
    config()->set('lightspeed.resources.write_lease_retries', 5);
    config()->set('lightspeed.resources.blocking_write_lease_retries', 0);

    expect(ownerMutRouterDrive(ownerMutRouterFor('router-floor'), 'writeLeaseRetries'))->toBe(1);

    expect(ownerMutRouterFor('router-floor')->acquireWriteLease($this->resourceId))->toBeString();
});

test('retry counts that are not numbers collapse to no retries rather than the configured other one', function () {
    // Both settings arrive from env as strings, and they are compared against
    // each other: uncast, "many" wins a comparison against 12 and a caller
    // that asked for nothing gets the ceiling instead.
    config()->set('lightspeed.resources.write_lease_retries', 'many');
    config()->set('lightspeed.resources.blocking_write_lease_retries', 12);

    expect(ownerMutRouterDrive(ownerMutRouterFor('router-garbage-retries'), 'writeLeaseRetries'))->toBe(0);

    config()->set('lightspeed.resources.write_lease_retries', 5);
    config()->set('lightspeed.resources.blocking_write_lease_retries', 'lots');

    expect(ownerMutRouterDrive(ownerMutRouterFor('router-garbage-ceiling'), 'writeLeaseRetries'))->toBe(1);
});

test('an owner TTL that is not a number degrades to zero instead of fataling the claim', function () {
    config()->set('lightspeed.resources.owner_ttl_seconds', 'a while');

    expect(ownerMutRouterDrive(ownerMutRouterFor('router-garbage-ttl'), 'ownerTtlSeconds'))->toBe(0);
});

test('contending for a held lease waits between attempts instead of spinning', function () {
    // Each retry is a real usleep() on the event loop when there is no
    // scheduler, which is the shipped configuration. Without the wait the
    // retries are three Redis round trips back to back, which is not
    // contention handling, it is a burst.
    config()->set('lightspeed.resources.write_lease_retries', 3);
    config()->set('lightspeed.resources.blocking_write_lease_retries', 3);
    config()->set('lightspeed.resources.write_lease_wait_us', 50000);

    expect(ownerMutRouterFor('router-holder')->acquireWriteLease($this->resourceId))->toBeString();

    $startedAt = microtime(true);
    expect(ownerMutRouterFor('router-contender')->acquireWriteLease($this->resourceId))->toBeNull();

    expect(microtime(true) - $startedAt)->toBeGreaterThan(0.1);
});

test('a retry wait that is negative or not a number does not crash the acquire', function () {
    // usleep() throws on a negative argument and takes an int, so an
    // unsanitised `write_lease_wait_us` turns contention, which is the normal
    // case this method exists for, into a fatal.
    expect(ownerMutRouterFor('router-holder')->acquireWriteLease($this->resourceId))->toBeString();

    foreach ([-1, 'as long as it takes'] as $wait) {
        config()->set('lightspeed.resources.write_lease_wait_us', $wait);

        expect(ownerMutRouterFor('router-hostile-wait')->acquireWriteLease($this->resourceId))
            ->toBeNull('contention with a hostile wait interval did not come back cleanly');
    }
});

test('no channel names a resource while the channel prefix is missing', function () {
    // The prefix is wire protocol: browser clients subscribe to these exact
    // names. With nothing configured, the pattern would be `private-.42`, so
    // every channel whose name happens to start with a dot after the visibility
    // marker would route to a resource nobody meant to expose.
    foreach ([null, ''] as $prefix) {
        config()->set('lightspeed.resources.channel_prefix', $prefix);
        $router = ownerMutRouterFor('router-prefixless');

        expect($router->resourceIdFromChannel('private-resource.42'))->toBeNull()
            ->and($router->resourceIdFromChannel('private-.42'))->toBeNull()
            ->and($router->resourceIdFromChannel('presence-.42'))->toBeNull();
    }
});
