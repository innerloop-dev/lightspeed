<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\OwnerCommandSigner;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Relay\RedisStreams;
use Lightspeed\Workers\WorkerContext;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * THE DRAIN, WHICH IS THE PACKAGE'S REMOTE EXECUTION PATH.
 *
 * Every entry that survives this loop becomes a call into the application's own
 * command handler, under the resource write lease, so each of the four refusals
 * (unsigned, addressed elsewhere, stale, already spent) is a gate on arbitrary
 * application writes. OwnerCommandSigningTest pins the signature. What is pinned
 * here is everything around it: that a refusal SKIPS one entry rather than
 * abandoning the drain, that the report an operator gets says which entry and
 * which request, that a flood of refusals costs one log line and not four
 * thousand a second, and that the answer written back to the caller is written
 * at all when the handler declines or its result cannot be encoded.
 *
 * These tests drive the private drain directly, exactly as
 * OwnerCommandSigningTest does and for the same reason: the public path needs a
 * listening Swoole server, which a unit test has no business starting.
 */
class OwnerMutDrainServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}

/**
 * A bus attached to one process key, ready to drain its own command stream.
 *
 * @return array{0: OwnerCommandBus, 1: string, 2: ResourceRouter}
 */
function ownerMutDrainBus(string $instanceId, ?callable $executor = null): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext = new WorkerContext($config);
    $workerContext->boot(OwnerMutDrainServer::make(), 0);

    $router = new ResourceRouter($config, $workerContext);

    $bus = new OwnerCommandBus($config, $router, new OwnerCommandDispatcher($config, app()), $workerContext);
    $bus->useExecutor($executor ?? fn () => ['ok' => true]);

    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, OwnerMutDrainServer::make());

    return [$bus, $workerContext->currentProcessKey(), $router];
}

/**
 * The signing scheme is OwnerCommandSigner's, and the bus holds one. Reached
 * through the bus rather than built beside it, so these tests still describe
 * the signer the bus actually signs with.
 */
function ownerMutDrainSigner(OwnerCommandBus $bus): OwnerCommandSigner
{
    $signer = new ReflectionProperty($bus, 'signer');
    $signer->setAccessible(true);

    return $signer->getValue($bus);
}

function ownerMutDrainDrive(OwnerCommandBus $bus, string $method, array $arguments = []): mixed
{
    $handle = new ReflectionMethod($bus, $method);
    $handle->setAccessible(true);

    return $handle->invokeArgs($bus, $arguments);
}

function ownerMutDrain(OwnerCommandBus $bus): void
{
    ownerMutDrainDrive($bus, 'drainCommands');
}

/**
 * One command message, with fields a test can override or leave out entirely.
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<int, string>  $without
 */
function ownerMutDrainMessage(string $processKey, string $requestId, array $overrides = [], array $without = []): string
{
    $message = array_merge([
        'request_id' => $requestId,
        'resource_id' => '',
        'command' => 'apply-edit',
        'payload' => ['nodes' => 3],
        'origin_process_key' => 'caller-worker',
        'target_process_key' => $processKey,
        'issued_at' => time(),
    ], $overrides);

    foreach ($without as $field) {
        unset($message[$field]);
    }

    return json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ownerMutDrainSign(string $message): string
{
    return hash_hmac(
        'sha256',
        'lightspeed.owner-command.v2'.':'.strlen($message).':'.$message,
        (string) config('lightspeed.reverb_compat.app_secret'),
    );
}

/** Append one entry to a process's command stream, exactly as a caller would. */
function ownerMutDrainPush(string $processKey, array $fields): void
{
    RedisStreams::add(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        $fields,
        1000,
    );
}

function ownerMutDrainPushSigned(string $processKey, string $message): void
{
    ownerMutDrainPush($processKey, ['message' => $message, 'signature' => ownerMutDrainSign($message)]);
}

/** The result the owner wrote back for one request, decoded. */
function ownerMutDrainResponse(string $requestId): array
{
    $raw = Redis::connection()->get("lightspeed:owner-command-response:{$requestId}");

    expect($raw)->toBeString('the owner wrote no response for this request');

    $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    return json_decode($envelope['result'], true, 512, JSON_THROW_ON_ERROR);
}

/** Take one key out of a config section, so the code's own default applies. */
function ownerMutDrainForgetConfig(string $section, string $key): void
{
    $values = config($section);
    unset($values[$key]);

    config()->set($section, $values);
}

beforeEach(function () {
    config()->set('lightspeed.reverb_compat.app_secret', 'owner-mut-drain-secret');
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);

    $this->requestId = 'owner-mut-drain-'.bin2hex(random_bytes(8));
    $this->resourceId = 'lightspeed-test-'.bin2hex(random_bytes(8));
    $this->streamKeys = [];
    $this->keys = [];
});

afterEach(function () {
    Redis::connection()->del("lightspeed:owner-command-seen:{$this->requestId}");
    Redis::connection()->del("lightspeed:owner-command-response:{$this->requestId}");
    Redis::connection()->del("lightspeed:resource-owner:{$this->resourceId}");
    Redis::connection()->del("lightspeed:resource-write:{$this->resourceId}:lease");
    Redis::connection()->del('lightspeed:resource-write::lease');

    foreach ([...$this->streamKeys, ...$this->keys] as $key) {
        Redis::connection()->del($key);
    }
});

test('a disabled bus drains nothing even with a worker attached', function () {
    // The setting is the operator's off switch for the whole feature, and a
    // drain that ignores it executes forwarded mutations on a worker whose
    // owner routing is supposed to be inert.
    config()->set('lightspeed.owner_commands.enabled', false);

    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-disabled', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId));

    ownerMutDrain($bus);

    expect($executed)->toBe([]);
});

test('an entry carrying no message is skipped without costing a log write', function () {
    // Refusing must not cost the server more than accepting. An entry with no
    // message at all is not evidence of anything an operator can act on, and
    // a log line per entry is a blocking write on the event loop that an
    // attacker chooses the volume of.
    Log::shouldReceive('warning')->never();

    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-no-message', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPush($processKey, ['signature' => 'a signature over nothing']);
    ownerMutDrainPush($processKey, ['message' => '', 'signature' => 'a signature over an empty message']);

    ownerMutDrain($bus);

    expect($executed)->toBe([]);
});

test('an entry carrying no message does not stop the ones behind it', function () {
    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-skips-messageless', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPush($processKey, ['signature' => 'a signature over nothing']);
    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId, ['command' => 'behind-nothing']));

    ownerMutDrain($bus);

    expect($executed)->toBe(['behind-nothing']);
});

test('an unsigned entry is reported with the entry it came from and the fact that it carried no signature', function () {
    // Either something is writing to this stream that should not be, or a
    // deployment is running two app secrets and owner routing is silently
    // broken between the halves that disagree. The context is what tells an
    // operator which.
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    [$bus, $processKey] = ownerMutDrainBus('drain-unsigned');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPush($processKey, ['message' => ownerMutDrainMessage($processKey, $this->requestId)]);

    ownerMutDrain($bus);

    expect($logged['message'])->toContain('unsigned or badly signed')
        ->and($logged['context']['signed'])->toBeFalse()
        ->and($logged['context']['entry'])->toBeString()->toContain('-')
        ->and($logged['context']['process_key'])->toBe($processKey)
        ->and($logged['context']['reason'])->toBe('unsigned or badly signed')
        ->and($logged['context']['note'])->toContain('not logged');
});

test('a badly signed entry is reported as having been signed, which is a different problem', function () {
    // "Signed but wrong" is a mismatched secret; "not signed at all" is
    // something else writing to the stream. Reporting both as unsigned sends
    // an operator looking in the wrong place.
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    [$bus, $processKey] = ownerMutDrainBus('drain-badly-signed');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPush($processKey, [
        'message' => ownerMutDrainMessage($processKey, $this->requestId),
        'signature' => hash_hmac('sha256', 'whatever this is', 'not-the-app-secret'),
    ]);

    ownerMutDrain($bus);

    expect($logged['context']['signed'])->toBeTrue();
});

test('a flood of refusals costs one log line per reason and no more', function () {
    // `read_count` is 100 and the poll interval 25ms, so an unthrottled
    // warning per entry is thousands of blocking log writes a second onto the
    // one event loop this worker serves every connection from.
    $warnings = 0;
    Log::shouldReceive('warning')->andReturnUsing(function () use (&$warnings): void {
        $warnings++;
    });

    [$bus, $processKey] = ownerMutDrainBus('drain-refusal-flood');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    foreach (range(1, 3) as $index) {
        ownerMutDrainPush($processKey, ['message' => ownerMutDrainMessage($processKey, $this->requestId.$index)]);
    }

    ownerMutDrain($bus);

    expect($warnings)->toBe(1);
});

test('a command addressed to another process is refused and named', function () {
    // The signature says the application wrote these bytes. It does not say
    // they were written for this worker, and a command copied onto another
    // process's stream is a mutation run by a worker that does not own the
    // resource.
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-misaddressed', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId, [
        'target_process_key' => 'somebody-else:worker:0',
    ]));

    ownerMutDrain($bus);

    expect($executed)->toBe([])
        ->and($logged['message'])->toContain('addressed to another process')
        ->and($logged['context']['entry'])->toBeString()->toContain('-')
        ->and($logged['context']['request_id'])->toBe($this->requestId);
});

test('a command from outside the freshness window is refused and named', function () {
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-stale', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    config()->set('lightspeed.owner_commands.freshness_seconds', 5);

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId, [
        'issued_at' => time() - 6,
    ]));

    ownerMutDrain($bus);

    expect($executed)->toBe([])
        ->and($logged['message'])->toContain('outside the freshness window')
        ->and($logged['context']['entry'])->toBeString()->toContain('-')
        ->and($logged['context']['request_id'])->toBe($this->requestId);
});

test('a replayed command is refused the second time and named', function () {
    // The freshness window admits every copy inside it; the spent request id
    // is what makes a signed entry deliverable exactly once.
    $logged = [];
    Log::shouldReceive('warning')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-replayed', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $message = ownerMutDrainMessage($processKey, $this->requestId);
    ownerMutDrainPushSigned($processKey, $message);
    ownerMutDrainPushSigned($processKey, $message);

    ownerMutDrain($bus);

    expect($executed)->toBe(['apply-edit'])
        ->and($logged['message'])->toContain('already executed')
        ->and($logged['context']['entry'])->toBeString()->toContain('-')
        ->and($logged['context']['request_id'])->toBe($this->requestId);
});

test('a refused entry is skipped rather than abandoning the rest of the drain', function () {
    // One bad entry per tick would otherwise stall every command behind it for
    // as long as whoever is writing them keeps writing, which is a denial of
    // service costing one Redis write per tick.
    $refusals = [
        'unsigned' => ['signed' => false, 'overrides' => []],
        'misaddressed' => ['signed' => true, 'overrides' => ['target_process_key' => 'elsewhere:worker:0']],
        'stale' => ['signed' => true, 'overrides' => ['issued_at' => time() - 3600]],
    ];

    foreach ($refusals as $label => $refusal) {
        $executed = [];
        [$bus, $processKey] = ownerMutDrainBus("drain-skips-{$label}", function (OwnerCommand $command) use (&$executed) {
            $executed[] = $command->command;

            return ['ok' => true];
        });
        $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

        $refused = ownerMutDrainMessage($processKey, $this->requestId.'-refused', $refusal['overrides']);

        if ($refusal['signed']) {
            ownerMutDrainPushSigned($processKey, $refused);
        } else {
            ownerMutDrainPush($processKey, ['message' => $refused]);
        }

        $good = $this->requestId.'-'.$label;
        $this->keys[] = "lightspeed:owner-command-seen:{$good}";
        $this->keys[] = "lightspeed:owner-command-response:{$good}";
        ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $good, ['command' => 'behind-'.$label]));

        ownerMutDrain($bus);

        expect($executed)->toBe(["behind-{$label}"], "a {$label} entry stopped the drain instead of being skipped");
    }
});

test('a replayed entry is skipped rather than abandoning the rest of the drain', function () {
    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-skips-replay', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $replayed = ownerMutDrainMessage($processKey, $this->requestId);
    ownerMutDrainPushSigned($processKey, $replayed);
    ownerMutDrainPushSigned($processKey, $replayed);

    $good = $this->requestId.'-behind';
    $this->keys[] = "lightspeed:owner-command-seen:{$good}";
    $this->keys[] = "lightspeed:owner-command-response:{$good}";
    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $good, ['command' => 'behind-replay']));

    ownerMutDrain($bus);

    expect($executed)->toBe(['apply-edit', 'behind-replay']);
});

test('a command with no usable request id is refused, because the response has nowhere to go', function () {
    // The request id names the response key and is the token that gets SPENT
    // to stop replays. An entry without one cannot be answered and cannot be
    // made single-use, so it must not execute at all.
    $executed = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-no-request-id', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->requestId;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, '', [], ['request_id']));
    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, ''));
    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, '', ['request_id' => false]));

    // And the drain carries on: one unanswerable entry per tick would
    // otherwise hold up every real command behind it.
    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId));

    ownerMutDrain($bus);

    expect($executed)->toBe([$this->requestId]);
});

test('the handler is given the command exactly as the caller addressed it', function () {
    $received = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-command-fields', function (OwnerCommand $command) use (&$received) {
        $received = [
            'request_id' => $command->requestId,
            'resource_id' => $command->resourceId,
            'command' => $command->command,
            'payload' => $command->payload,
            'origin' => $command->originProcessKey,
        ];

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId, [
        'resource_id' => $this->resourceId,
        'command' => 'apply-edit',
        'payload' => ['nodes' => 3],
        'origin_process_key' => 'caller:worker:0',
    ]));

    ownerMutDrain($bus);

    expect($received)->toBe([
        'request_id' => $this->requestId,
        'resource_id' => $this->resourceId,
        'command' => 'apply-edit',
        'payload' => ['nodes' => 3],
        'origin' => 'caller:worker:0',
    ]);
});

test('a command that names no resource, command or origin is executed with empty ones rather than invented values', function () {
    // A missing resource id means "nothing to serialize on", which is a
    // decision the lease path reads: any substitute value would take the write
    // lease of a resource that does not exist and serialize unrelated
    // mutations behind it.
    $received = [];
    [$bus, $processKey] = ownerMutDrainBus('drain-absent-fields', function (OwnerCommand $command) use (&$received) {
        $received = [
            'resource_id' => $command->resourceId,
            'command' => $command->command,
            'origin' => $command->originProcessKey,
        ];

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage(
        $processKey,
        $this->requestId,
        [],
        ['resource_id', 'command', 'origin_process_key'],
    ));

    ownerMutDrain($bus);

    expect($received)->toBe(['resource_id' => '', 'command' => '', 'origin' => null]);
});

test('a handler that accepts nothing is reported to the caller instead of leaving it to time out', function () {
    [$bus, $processKey] = ownerMutDrainBus('drain-unhandled', fn () => null);
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId));

    ownerMutDrain($bus);

    expect(ownerMutDrainResponse($this->requestId))
        ->toBe(['ok' => false, 'error' => 'No owner command handler accepted the message.']);
});

test('a result that cannot be encoded is answered with why, not thrown past the loop', function () {
    // A handler result carrying invalid UTF-8 used to throw out of the drain
    // with no response written at all, so the caller sat out its entire
    // timeout for a failure the owner already knew about.
    [$bus, $processKey] = ownerMutDrainBus('drain-unencodable-result', fn () => ['ok' => true, 'name' => "\xB1\x31"]);
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId));

    ownerMutDrain($bus);

    $response = ownerMutDrainResponse($this->requestId);

    expect($response['ok'])->toBeFalse()
        ->and($response['error'])->toStartWith('Owner command result could not be encoded for the caller: ')
        ->and($response['error'])->toContain('UTF-8');
});

test('a response is kept for thirty seconds, which is how long a caller has to read it', function () {
    ownerMutDrainForgetConfig('lightspeed.owner_commands', 'response_ttl_seconds');

    [$bus, $processKey] = ownerMutDrainBus('drain-response-ttl');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId));

    ownerMutDrain($bus);

    expect((int) Redis::connection()->ttl("lightspeed:owner-command-response:{$this->requestId}"))
        ->toBeGreaterThan(29)
        ->toBeLessThanOrEqual(30);
});

test('the default freshness window is thirty seconds either side of now', function () {
    // Symmetric because the two ends are different machines and the one that
    // is ahead is as likely as the one that is behind.
    ownerMutDrainForgetConfig('lightspeed.owner_commands', 'freshness_seconds');

    [$bus] = ownerMutDrainBus('drain-default-freshness');
    $now = time();

    expect(ownerMutDrainSigner($bus)->isFresh($now - 30))->toBeTrue()
        ->and(ownerMutDrainSigner($bus)->isFresh($now + 30))->toBeTrue()
        ->and(ownerMutDrainSigner($bus)->isFresh($now - 31))->toBeFalse()
        ->and(ownerMutDrainSigner($bus)->isFresh($now + 31))->toBeFalse();
});

test('a freshness window configured below one second still admits one second of skew', function () {
    // Zero would refuse a command whose clock is one tick ahead of this one,
    // which is a clock problem rather than an attack, and it would break owner
    // routing for a reason the operator cannot see from here.
    config()->set('lightspeed.owner_commands.freshness_seconds', 0);

    [$bus] = ownerMutDrainBus('drain-floored-freshness');
    $now = time();

    expect(ownerMutDrainSigner($bus)->freshnessSeconds())->toBe(1)
        ->and(ownerMutDrainSigner($bus)->isFresh($now - 1))->toBeTrue()
        ->and(ownerMutDrainSigner($bus)->isFresh($now - 2))->toBeFalse();

    // Same floor for a window that is not a number at all. Uncast, "a while"
    // beats 1 in PHP's comparison and becomes the window itself, which is a
    // TypeError the moment anything asks for the value as an int.
    config()->set('lightspeed.owner_commands.freshness_seconds', 'a while');

    expect(ownerMutDrainSigner($bus)->freshnessSeconds())->toBe(1);
});

test('a spent request id outlives the last instant its entry can be accepted by exactly one second', function () {
    // Twice the window covers the interval up to but NOT including its far
    // end, and the second where only one of the two mechanisms holds is a
    // second with no replay protection at all. More than one second of slack
    // is not wrong, but it is not the derivation this constant claims either.
    config()->set('lightspeed.owner_commands.freshness_seconds', 7);

    [$bus] = ownerMutDrainBus('drain-spent-ttl');

    expect(ownerMutDrainSigner($bus)->spentIdTtlSeconds())->toBe(15);
});

test('commands are signed with the application secret, and an absent secret is an empty one', function () {
    // Both ends share this constant, so a process talking to itself cannot
    // notice a substituted fallback. A non-empty default would be a shipped
    // secret every install shares.
    ownerMutDrainForgetConfig('lightspeed.reverb_compat', 'app_secret');

    [$bus] = ownerMutDrainBus('drain-app-secret');

    expect(ownerMutDrainSigner($bus)->commandSignature('lightspeed.owner-command.v2', ['abc']))
        ->toBe(hash_hmac('sha256', 'lightspeed.owner-command.v2:3:abc', ''));
});

test('one drain reads at most a hundred entries and leaves the rest for the next tick', function () {
    // The bound is what keeps one tick from becoming an unbounded blocking
    // loop on the event loop that serves every connection this worker holds.
    ownerMutDrainForgetConfig('lightspeed.owner_commands', 'read_count');

    $executed = 0;
    [$bus, $processKey] = ownerMutDrainBus('drain-read-count', function () use (&$executed) {
        $executed++;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    foreach (range(1, 101) as $index) {
        ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, "{$this->requestId}-{$index}"));
    }

    ownerMutDrain($bus);
    expect($executed)->toBe(100);

    ownerMutDrain($bus);
    expect($executed)->toBe(101);
});

test('a read count that is not a number does not fatal the drain', function () {
    config()->set('lightspeed.owner_commands.read_count', 'all of them');

    [$bus, $processKey] = ownerMutDrainBus('drain-garbage-read-count');
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    ownerMutDrainPushSigned($processKey, ownerMutDrainMessage($processKey, $this->requestId));

    expect(fn () => ownerMutDrain($bus))->not->toThrow(TypeError::class);
});
