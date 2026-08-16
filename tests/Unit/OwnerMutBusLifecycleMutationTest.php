<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Relay\RedisStreams;
use Lightspeed\Workers\WorkerContext;
use Swoole\Timer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * WHAT ATTACHING THE BUS TO A WORKER HAS TO DO, AND WHAT ONE TICK MAY NOT DO.
 *
 * bootWorker() is three decisions in six lines: it must not leave the previous
 * attachment's timer running, it must not poll at all when owner commands are
 * off, and it must start from the stream's current head rather than from the
 * beginning of time. Each of those was invisible to the suite, and each fails
 * silently in production: a leaked timer drains the same stream twice per
 * interval forever, a poll on a disabled bus contradicts the setting, and a
 * lastCommandId of '0-0' re-executes every command still in the stream the
 * moment a worker restarts.
 *
 * The tick itself is the other half. An exception escaping a Swoole timer
 * callback kills the worker, and `worker_num` defaults to 1, so the reporting
 * inside the catch is as load-bearing as the drain it guards.
 */
class OwnerMutLifecycleServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}

/**
 * A worker context whose first process-key read fails, standing in for the
 * Redis outage that makes a drain throw from inside the timer callback.
 */
class OwnerMutLifecycleFailingContext extends WorkerContext
{
    public int $failuresLeft = 0;

    public function __construct(private $configRepository)
    {
        parent::__construct($configRepository);
    }

    public function currentProcessKey(): string
    {
        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;

            throw new RuntimeException('redis is down');
        }

        return parent::currentProcessKey();
    }
}

/**
 * A bus attached to one process key.
 *
 * @return array{0: OwnerCommandBus, 1: string, 2: WorkerContext}
 */
function ownerMutLifecycleBus(string $instanceId, ?callable $executor = null, ?WorkerContext $workerContext = null): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext ??= new WorkerContext($config);
    $workerContext->boot(OwnerMutLifecycleServer::make(), 0);

    $bus = new OwnerCommandBus(
        $config,
        new ResourceRouter($config, $workerContext),
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );
    $bus->useExecutor($executor ?? fn () => ['ok' => true]);

    return [$bus, $workerContext->currentProcessKey(), $workerContext];
}

function ownerMutLifecycleTimerId(OwnerCommandBus $bus): ?int
{
    $timerId = new ReflectionProperty($bus, 'timerId');
    $timerId->setAccessible(true);

    return $timerId->getValue($bus);
}

function ownerMutLifecycleSign(string $message): string
{
    return hash_hmac(
        'sha256',
        'lightspeed.owner-command.v2'.':'.strlen($message).':'.$message,
        (string) config('lightspeed.reverb_compat.app_secret'),
    );
}

function ownerMutLifecycleMessage(string $processKey, string $requestId): string
{
    return json_encode([
        'request_id' => $requestId,
        'resource_id' => '',
        'command' => 'apply-edit',
        'payload' => ['nodes' => 3],
        'origin_process_key' => 'caller-worker',
        'target_process_key' => $processKey,
        'issued_at' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ownerMutLifecyclePush(string $processKey, string $requestId): void
{
    $message = ownerMutLifecycleMessage($processKey, $requestId);

    RedisStreams::add(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        ['message' => $message, 'signature' => ownerMutLifecycleSign($message)],
        1000,
    );
}

/** Take one key out of a config section, so the code's own default applies. */
function ownerMutLifecycleForgetConfig(string $section, string $key): void
{
    $values = config($section);
    unset($values[$key]);

    config()->set($section, $values);
}

function ownerMutLifecycleDrive(OwnerCommandBus $bus, string $method, array $arguments = []): mixed
{
    $handle = new ReflectionMethod($bus, $method);
    $handle->setAccessible(true);

    return $handle->invokeArgs($bus, $arguments);
}

beforeEach(function () {
    config()->set('lightspeed.reverb_compat.app_secret', 'owner-mut-lifecycle-secret');

    $this->requestId = 'owner-mut-lifecycle-'.bin2hex(random_bytes(8));
    $this->busses = [];
    $this->streamKeys = [];
});

afterEach(function () {
    // A live Swoole timer makes the process wait on an event loop at shutdown,
    // so every bus this file attaches is detached again whatever the test did.
    foreach ($this->busses as $bus) {
        $bus->shutdownWorker();
    }

    // And any timer the bus itself failed to detach, or the event loop keeps
    // this process alive after the suite finishes.
    foreach (Timer::list() as $timerId) {
        Timer::clear($timerId);
    }

    foreach ($this->streamKeys as $key) {
        Redis::connection()->del($key);
    }

    Redis::connection()->del("lightspeed:owner-command-seen:{$this->requestId}");
    Redis::connection()->del("lightspeed:owner-command-response:{$this->requestId}");
});

test('attaching a worker twice leaves only the newest poll timer running', function () {
    [$bus] = ownerMutLifecycleBus('lifecycle-rebooted');
    $this->busses[] = $bus;

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);
    $first = ownerMutLifecycleTimerId($bus);

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);
    $second = ownerMutLifecycleTimerId($bus);

    expect($first)->toBeInt()
        ->and($second)->toBeInt()->not->toBe($first)
        ->and(Timer::exists($first))->toBeFalse('the previous attachment kept polling, so the stream is drained twice per interval');
});

test('a disabled bus starts no poll timer at all', function () {
    config()->set('lightspeed.owner_commands.enabled', false);

    [$bus] = ownerMutLifecycleBus('lifecycle-disabled');
    $this->busses[] = $bus;

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);

    expect(ownerMutLifecycleTimerId($bus))->toBeNull();
});

test('an enabled bus polls no faster than the ten millisecond floor', function () {
    // A poll interval below the floor is what an operator asking for "as fast
    // as possible" writes, and honouring it literally puts a Redis round trip
    // on the event loop every millisecond.
    config()->set('lightspeed.owner_commands.poll_interval_ms', 1);

    [$bus] = ownerMutLifecycleBus('lifecycle-floor');
    $this->busses[] = $bus;

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);

    expect(Timer::info(ownerMutLifecycleTimerId($bus))['interval'])->toBe(10);
});

test('the shipped default poll interval is twenty five milliseconds', function () {
    ownerMutLifecycleForgetConfig('lightspeed.owner_commands', 'poll_interval_ms');

    [$bus] = ownerMutLifecycleBus('lifecycle-default-interval');
    $this->busses[] = $bus;

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);

    expect(Timer::info(ownerMutLifecycleTimerId($bus))['interval'])->toBe(25);
});

test('a poll interval that is not a number falls back to the floor instead of refusing to boot', function () {
    // `LIGHTSPEED_OWNER_COMMANDS_POLL_INTERVAL_MS=fast` reaches this as a
    // string. Swoole's timer takes an int, so an uncast value is a TypeError
    // on the worker start path rather than a slow poll.
    config()->set('lightspeed.owner_commands.poll_interval_ms', 'as-fast-as-you-can');

    [$bus] = ownerMutLifecycleBus('lifecycle-garbage-interval');
    $this->busses[] = $bus;

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);

    expect(Timer::info(ownerMutLifecycleTimerId($bus))['interval'])->toBe(10);
});

test('one tick drains the commands waiting on this worker stream', function () {
    $executed = [];
    [$bus, $processKey] = ownerMutLifecycleBus('lifecycle-tick', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, OwnerMutLifecycleServer::make());

    ownerMutLifecyclePush($processKey, $this->requestId);

    ownerMutLifecycleDrive($bus, 'tick');

    expect($executed)->toBe(['apply-edit']);
});

test('a drain that throws is reported from inside the tick rather than killing the worker', function () {
    // An exception escaping a Swoole timer callback is fatal to the worker, and
    // `worker_num` defaults to 1, so this catch is the difference between a
    // Redis blip and a dead server. The report has to name the process whose
    // drain failed, or an operator running several workers cannot tell which.
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $failing = new OwnerMutLifecycleFailingContext(app('config'));
    [$bus, $processKey] = ownerMutLifecycleBus('lifecycle-drain-throws', null, $failing);

    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, OwnerMutLifecycleServer::make());

    $failing->failuresLeft = 1;

    ownerMutLifecycleDrive($bus, 'tick');

    expect($logged['message'])->toContain('drain failed')
        ->and($logged['context']['process_key'])->toBe($processKey)
        ->and($logged['context']['message'])->toBe('redis is down');
});

test('a worker starts from the head of its stream instead of replaying what is already there', function () {
    // A restarted worker inherits whatever its predecessor never drained. Those
    // commands were signed for this process key and are still inside the
    // freshness window, so a lastCommandId of '0-0' executes every one of them
    // again at the first tick.
    $executed = [];
    [$bus, $processKey] = ownerMutLifecycleBus('lifecycle-head', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";
    $this->busses[] = $bus;

    ownerMutLifecyclePush($processKey, $this->requestId);

    $bus->bootWorker(OwnerMutLifecycleServer::make(), 0);

    ownerMutLifecycleDrive($bus, 'drainCommands');

    expect($executed)->toBe([]);
});

test('owner commands and the write lease are on unless an operator turns them off', function () {
    // Both defaults live in the code as well as in the published config file,
    // and an app that trimmed its config file gets the code's answer. Off by
    // default would mean owner routing silently stops forwarding, or that
    // forwarded mutations stop being serialized against each other.
    ownerMutLifecycleForgetConfig('lightspeed.owner_commands', 'enabled');
    ownerMutLifecycleForgetConfig('lightspeed.owner_commands', 'write_lease');

    [$bus] = ownerMutLifecycleBus('lifecycle-defaults-on');

    expect(ownerMutLifecycleDrive($bus, 'enabled'))->toBeTrue()
        ->and(ownerMutLifecycleDrive($bus, 'writeLeaseEnabled'))->toBeTrue();
});

test('a non-boolean toggle is coerced rather than fataling the worker', function () {
    // Config values arrive from env and from application config files, and a
    // list is what an operator writes by accident. The declared bool return
    // type makes an uncoerced value a TypeError on the boot path.
    config()->set('lightspeed.owner_commands.enabled', ['on']);
    config()->set('lightspeed.owner_commands.write_lease', ['on']);

    [$bus] = ownerMutLifecycleBus('lifecycle-garbage-toggles');

    expect(ownerMutLifecycleDrive($bus, 'enabled'))->toBeTrue()
        ->and(ownerMutLifecycleDrive($bus, 'writeLeaseEnabled'))->toBeTrue();
});
