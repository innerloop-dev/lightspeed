<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Workers\WorkerContext;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * THE CALLER HALF OF THE BUS: WHO GETS FORWARDED TO, AND WHAT COMES BACK.
 *
 * forwardIfOwnedByAnotherProcess() answers null for "handle this yourself" and
 * an array for "the owner answered", and the decision between those two is made
 * from an owner claim it did not write. Every branch in that decision is a way
 * to send a mutation to the wrong process, or to run one locally that another
 * worker is already running, and none of them was pinned.
 *
 * The entry it appends is the other half. The drain refuses anything not
 * addressed to itself, outside the freshness window, or already spent, so the
 * fields this side writes are the fields that side checks: an entry missing one
 * of them is refused by every worker it reaches, which looks exactly like the
 * owner being down.
 *
 * And then the wait. A response key is a plain Redis key an attacker can write,
 * so waitForResponse() has to distinguish "the owner answered" from "somebody
 * put something here", and it has to come back at all when nobody answers.
 */
class OwnerMutForwardServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}

/** A router that answers with whatever owner claim a test wants to hand the bus. */
class OwnerMutForwardStubRouter extends ResourceRouter
{
    public array $claim = [];

    public function claimOwner(string $resourceId, string $reason = 'unknown'): array
    {
        return $this->claim;
    }
}

/**
 * A bus attached to one process key, with the router a test asks for.
 *
 * @return array{0: OwnerCommandBus, 1: string, 2: ResourceRouter}
 */
function ownerMutForwardBus(string $instanceId, ?ResourceRouter $router = null): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext = new WorkerContext($config);
    $workerContext->boot(OwnerMutForwardServer::make(), 0);

    $router ??= new ResourceRouter($config, $workerContext);

    $bus = new OwnerCommandBus(
        $config,
        $router,
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );
    $bus->useExecutor(fn () => null);

    return [$bus, $workerContext->currentProcessKey(), $router];
}

/** A router that claims resources under a process key of its own. */
function ownerMutForwardRouterFor(string $processKey): ResourceRouter
{
    $workerContext = new class(app('config'), $processKey) extends WorkerContext
    {
        public function __construct(private $configRepository, private string $ownProcessKey)
        {
            parent::__construct($configRepository);
        }

        public function currentProcessKey(): string
        {
            return $this->ownProcessKey;
        }
    };

    return new ResourceRouter(app('config'), $workerContext);
}

function ownerMutForwardStubRouter(array $claim): OwnerMutForwardStubRouter
{
    $router = new OwnerMutForwardStubRouter(app('config'), new WorkerContext(app('config')));
    $router->claim = $claim;

    return $router;
}

function ownerMutForwardEntries(string $processKey): array
{
    return Lightspeed\Relay\RedisStreams::read(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        '0-0',
        100,
    );
}

function ownerMutForwardDrive(OwnerCommandBus $bus, string $method, array $arguments = []): mixed
{
    $handle = new ReflectionMethod($bus, $method);
    $handle->setAccessible(true);

    return $handle->invokeArgs($bus, $arguments);
}

/** Take one key out of a config section, so the code's own default applies. */
function ownerMutForwardForgetConfig(string $section, string $key): void
{
    $values = config($section);
    unset($values[$key]);

    config()->set($section, $values);
}

function ownerMutForwardSignResponse(string $requestId, int $issuedAt, string $encodedResult): string
{
    return hash_hmac(
        'sha256',
        'lightspeed.owner-command-response.v2'
            .':'.strlen($requestId).':'.$requestId
            .':'.strlen((string) $issuedAt).':'.$issuedAt
            .':'.strlen($encodedResult).':'.$encodedResult,
        (string) config('lightspeed.reverb_compat.app_secret'),
    );
}

beforeEach(function () {
    config()->set('lightspeed.reverb_compat.app_secret', 'owner-mut-forward-secret');

    // The caller must not sit in a poll loop in any test that is not about the
    // wait; the tests that are about it raise this themselves.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 0);
    config()->set('lightspeed.owner_commands.wait_interval_us', 0);

    $this->resourceId = 'lightspeed-test-'.bin2hex(random_bytes(8));
    $this->requestId = 'owner-mut-forward-'.bin2hex(random_bytes(8));
    $this->streamKeys = [];
});

afterEach(function () {
    Redis::connection()->del("lightspeed:resource-owner:{$this->resourceId}");
    Redis::connection()->del("lightspeed:resource-write:{$this->resourceId}:lease");
    Redis::connection()->del("lightspeed:owner-command-response:{$this->requestId}");

    foreach ($this->streamKeys as $key) {
        Redis::connection()->del($key);
    }
});

test('a resource this process already owns is handled here rather than forwarded', function () {
    [$caller, $processKey, $router] = ownerMutForwardBus('forward-local');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    // The caller's own claim wins, so the answer is "you own this, do the work".
    $router->claimOwner($this->resourceId, 'test');

    expect($caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', []))->toBeNull()
        ->and(ownerMutForwardEntries($processKey))->toBe([], 'a locally owned resource was forwarded to a stream anyway');
});

test('a claim that says local is honoured even when it names some other owner', function () {
    // The two fields can disagree: `local` is this process's answer to "did I
    // win the claim", and `owner` is the payload that was in the key, which on
    // a refresh is the claim as it was written rather than as it is now.
    // Forwarding on the strength of the payload would send a command away from
    // the worker that just won the resource, to one that no longer holds it.
    $router = ownerMutForwardStubRouter([
        'claimed' => true,
        'local' => true,
        'owner' => ['process_key' => 'forward-stale-owner-payload'],
    ]);
    [$caller] = ownerMutForwardBus('forward-local-flag-wins', $router);
    $this->streamKeys[] = 'lightspeed:owner-commands:forward-stale-owner-payload';

    expect($caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', []))->toBeNull()
        ->and(ownerMutForwardEntries('forward-stale-owner-payload'))->toBe([]);
});

test('a claim that does not say it is local is forwarded to the owner it names', function () {
    // The flag is read out of an array, so its absence has to mean something.
    // Defaulting to "local" would keep every mutation on the calling worker
    // while another process holds the resource, which is the double-writer the
    // routing exists to prevent.
    $router = ownerMutForwardStubRouter(['owner' => ['process_key' => 'forward-absent-flag-owner']]);
    [$caller] = ownerMutForwardBus('forward-absent-flag', $router);
    $this->streamKeys[] = 'lightspeed:owner-commands:forward-absent-flag-owner';

    $result = $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', []);

    expect($result)->toBeArray()
        ->and($result['ok'])->toBeFalse()
        ->and(ownerMutForwardEntries('forward-absent-flag-owner'))->toHaveCount(1);
});

test('an owner that names no usable process is never forwarded to', function () {
    // Each of these is a claim the bus can genuinely read back: a payload
    // written by an older version, a truncated one, and this process's own.
    // Addressing a command to any of them either sends it to a stream nobody
    // drains or has this worker talk to itself.
    [$probe, $processKey] = ownerMutForwardBus('forward-unusable');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $unusable = [
        'not a string' => ['claimed' => false, 'local' => false, 'owner' => ['process_key' => 12345]],
        'empty' => ['claimed' => false, 'local' => false, 'owner' => ['process_key' => '']],
        'this very process' => ['claimed' => false, 'local' => false, 'owner' => ['process_key' => $processKey]],
    ];

    foreach ($unusable as $label => $claim) {
        [$caller] = ownerMutForwardBus('forward-unusable', ownerMutForwardStubRouter($claim));

        expect($caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', []))
            ->toBeNull("a command was forwarded to an owner whose process key is {$label}");
    }

    expect(ownerMutForwardEntries($processKey))->toBe([]);
});

test('the forwarded entry carries every field the drain checks before it executes', function () {
    // The drain refuses anything not addressed to its own process key, outside
    // the freshness window, or already spent, and it answers on the response
    // key named by the request id. A field missing here is a command every
    // worker refuses, which from the caller looks like an owner that is down.
    $owner = ownerMutForwardRouterFor('forward-fields-owner');
    $owner->claimOwner($this->resourceId, 'test');

    [$caller, $callerKey] = ownerMutForwardBus('forward-fields-caller');
    $this->streamKeys[] = 'lightspeed:owner-commands:forward-fields-owner';

    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);

    $entries = ownerMutForwardEntries('forward-fields-owner');
    expect($entries)->toHaveCount(1);

    $fields = Lightspeed\Relay\RedisStreams::normalizeFields($entries[0][1]);
    $message = json_decode($fields['message'], true, 512, JSON_THROW_ON_ERROR);

    expect($message['resource_id'])->toBe($this->resourceId)
        ->and($message['command'])->toBe('apply-edit')
        ->and($message['payload'])->toBe(['nodes' => 3])
        ->and($message['origin_process_key'])->toBe($callerKey)
        ->and($message['target_process_key'])->toBe('forward-fields-owner')
        ->and($message['issued_at'])->toBeInt()
        ->and($message['request_id'])->toBeString()->not->toBe('');
});

test('a payload that cannot be encoded fails loudly instead of appending an empty entry', function () {
    // json_encode answers false rather than throwing unless it is told to
    // throw, and false is a perfectly appendable stream field: the owner would
    // read an empty message, skip it, and the caller would wait out its whole
    // timeout for a command that was never really written.
    $owner = ownerMutForwardRouterFor('forward-unencodable-owner');
    $owner->claimOwner($this->resourceId, 'test');

    [$caller] = ownerMutForwardBus('forward-unencodable-caller');
    $this->streamKeys[] = 'lightspeed:owner-commands:forward-unencodable-owner';

    expect(fn () => $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['bytes' => "\xB1\x31"]))
        ->toThrow(JsonException::class);

    expect(ownerMutForwardEntries('forward-unencodable-owner'))->toBe([]);
});

test('each append keeps the command stream alive for another hour', function () {
    // Every server start mints a new process key, so without this the streams
    // of dead processes live until the Redis instance is flushed. An hour is
    // orders of magnitude above the gap between appends it has to outlive,
    // which is what makes it safe for the stream of a live but idle owner to
    // expire while empty.
    ownerMutForwardForgetConfig('lightspeed.owner_commands', 'stream_ttl_seconds');

    $owner = ownerMutForwardRouterFor('forward-ttl-owner');
    $owner->claimOwner($this->resourceId, 'test');

    [$caller] = ownerMutForwardBus('forward-ttl-caller');
    $this->streamKeys[] = 'lightspeed:owner-commands:forward-ttl-owner';

    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', []);

    expect((int) Redis::connection()->ttl('lightspeed:owner-commands:forward-ttl-owner'))
        ->toBeGreaterThan(3599)
        ->toBeLessThanOrEqual(3600);
});

test('an empty response value is not an answer and the caller keeps waiting', function () {
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 40);

    [$caller] = ownerMutForwardBus('forward-empty-response');

    Redis::connection()->setex("lightspeed:owner-command-response:{$this->requestId}", 30, '');

    $result = ownerMutForwardDrive($caller, 'waitForResponse', [$this->requestId]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('timed out');
});

test('a consumed response is deleted so no second caller can read it', function () {
    // The response key is the one piece of caller state an attacker can watch:
    // an envelope left behind after it is read is a validly signed capture,
    // free for the whole response TTL.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = ownerMutForwardBus('forward-consumed-response');

    $issuedAt = time();
    $encodedResult = json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    Redis::connection()->setex(
        "lightspeed:owner-command-response:{$this->requestId}",
        30,
        json_encode([
            'result' => $encodedResult,
            'issued_at' => $issuedAt,
            'signature' => ownerMutForwardSignResponse($this->requestId, $issuedAt, $encodedResult),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    expect(ownerMutForwardDrive($caller, 'waitForResponse', [$this->requestId]))->toBe(['ok' => true])
        ->and(Redis::connection()->exists("lightspeed:owner-command-response:{$this->requestId}"))->toBe(0);
});

test('a response whose result is not a string is refused before anything decodes it', function () {
    // The three shape checks and the signature check are one guard, not four
    // independent ones: a result that is not a string never reaches the
    // signature comparison, and an envelope that fails any of them is refused.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = ownerMutForwardBus('forward-nonstring-result');

    // Signed correctly, and for this very request: the shape check is the only
    // thing standing between the caller and a result it cannot read, so it has
    // to hold on an envelope whose signature is genuine.
    $issuedAt = time();

    Redis::connection()->setex(
        "lightspeed:owner-command-response:{$this->requestId}",
        30,
        json_encode([
            'result' => 123,
            'issued_at' => $issuedAt,
            'signature' => ownerMutForwardSignResponse($this->requestId, $issuedAt, '123'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $result = ownerMutForwardDrive($caller, 'waitForResponse', [$this->requestId]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('not signed by this application');
});

test('a signed response that decodes to something other than an array is reported as such', function () {
    // The owner's handler contract is an array, so a validly signed envelope
    // carrying a scalar is a broken handler rather than an attack, and the
    // caller has to be told which. Returning it as-is would hand the caller a
    // value it cannot read as a result.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = ownerMutForwardBus('forward-invalid-shape');

    $issuedAt = time();
    $encodedResult = '123';

    Redis::connection()->setex(
        "lightspeed:owner-command-response:{$this->requestId}",
        30,
        json_encode([
            'result' => $encodedResult,
            'issued_at' => $issuedAt,
            'signature' => ownerMutForwardSignResponse($this->requestId, $issuedAt, $encodedResult),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $result = ownerMutForwardDrive($caller, 'waitForResponse', [$this->requestId]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Owner command returned an invalid response shape.');
});

test('a timeout on a worker that cannot yield says which setting bounded it and why', function () {
    // Two settings bound this wait and only one of them applies on a default
    // install. An operator reading "timed out after 40ms" while
    // `wait_timeout_ms` says 10000 has no way to find the one that acted
    // unless the message names both, in the order that says which is which.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 40);

    [$caller] = ownerMutForwardBus('forward-timeout-message');

    $result = ownerMutForwardDrive($caller, 'waitForResponse', [$this->requestId]);

    expect($result['error'])->toBe(
        'Owner command timed out waiting for a response after 40ms. This worker cannot yield while it waits '
            .'(`lightspeed.server.enable_coroutine` is off), so the wait is bounded by '
            .'`lightspeed.owner_commands.blocking_wait_timeout_ms` rather than by `wait_timeout_ms`.'
    );
});

test('a wait interval that is negative or not a number does not crash the poll loop', function () {
    // usleep() throws on a negative argument and takes an int, so an
    // unsanitised `wait_interval_us` turns every forwarded command into a
    // fatal on the caller rather than a slow one.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 30);

    [$caller] = ownerMutForwardBus('forward-hostile-wait-interval');

    foreach ([-1, 'as slow as you like'] as $interval) {
        config()->set('lightspeed.owner_commands.wait_interval_us', $interval);

        $result = ownerMutForwardDrive($caller, 'waitForResponse', [$this->requestId]);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toContain('timed out');
    }
});

test('a positive wait interval really sleeps between polls', function () {
    // Without the sleep the wait is a spin on the event loop: the same wall
    // clock, every cycle of the CPU, and a Redis GET as fast as the socket
    // will answer for the whole timeout.
    [$caller] = ownerMutForwardBus('forward-sleeps');

    $startedAt = microtime(true);
    ownerMutForwardDrive($caller, 'sleepMicroseconds', [50000]);

    expect(microtime(true) - $startedAt)->toBeGreaterThan(0.04);
});

test('config values that are not numbers degrade to zero instead of fataling the worker', function () {
    // Every one of these arrives from env, where everything is a string, and
    // each is used where an int is required: a Redis TTL, a stream trim, a
    // deadline. Uncast, they are a TypeError on a path with no caller left to
    // report it to, or worse, a comparison a non-numeric string WINS: "in a
    // bit" is greater than 10000 to PHP, so an uncast wait becomes the longest
    // one on offer rather than none.
    config()->set('lightspeed.owner_commands.wait_timeout_ms', 'in a bit');
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 10000);
    config()->set('lightspeed.owner_commands.max_entries', 'as many as it takes');
    config()->set('lightspeed.owner_commands.stream_ttl_seconds', 'a while');

    [$caller] = ownerMutForwardBus('forward-hostile-numbers');

    expect(ownerMutForwardDrive($caller, 'waitTimeoutMs'))->toBe(0)
        ->and(ownerMutForwardDrive($caller, 'maxStreamEntries'))->toBe(0)
        ->and(ownerMutForwardDrive($caller, 'streamTtlSeconds'))->toBe(0);

    // And the blocking ceiling on its own, which is a min() of two settings:
    // an uncast ceiling loses the comparison and the wait becomes the ten
    // second one this worker cannot afford.
    config()->set('lightspeed.owner_commands.wait_timeout_ms', 10000);
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 'not long');

    expect(ownerMutForwardDrive($caller, 'waitTimeoutMs'))->toBe(0);
});

test('the shipped wait ceilings are ten seconds yielding and a quarter of a second blocked', function () {
    // Two numbers because the wait is two different things, and both live in
    // the code as well as in the published config file. This worker cannot
    // yield, so the smaller of the two is what actually bounds it; the larger
    // is what an application gets by turning `enable_coroutine` on.
    ownerMutForwardForgetConfig('lightspeed.owner_commands', 'wait_timeout_ms');
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 20000);

    [$caller] = ownerMutForwardBus('forward-default-wait');

    expect(ownerMutForwardDrive($caller, 'waitTimeoutMs'))->toBe(10000);

    config()->set('lightspeed.owner_commands.wait_timeout_ms', 10000);
    ownerMutForwardForgetConfig('lightspeed.owner_commands', 'blocking_wait_timeout_ms');

    expect(ownerMutForwardDrive($caller, 'waitTimeoutMs'))->toBe(250);
});

test('a blocking ceiling of zero or less means no wait rather than a deadline in the past', function () {
    // A cap must never lengthen a wait, and it must never turn one negative
    // either: a deadline behind now is the same "do not wait" the operator
    // asked for, but arrived at by arithmetic nobody checked.
    config()->set('lightspeed.owner_commands.wait_timeout_ms', 10000);

    [$caller] = ownerMutForwardBus('forward-zero-ceiling');

    foreach ([0, -5] as $ceiling) {
        config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', $ceiling);

        expect(ownerMutForwardDrive($caller, 'waitTimeoutMs'))->toBe(0);
    }
});
