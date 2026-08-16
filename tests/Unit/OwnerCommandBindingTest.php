<?php

use Illuminate\Log\Events\MessageLogged;
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
 * The owner-command signature has to cover the ADDRESSING, not just the words.
 *
 * OwnerCommandSigningTest proves the bus refuses an entry nobody with the app
 * secret wrote. That is necessary and it is not sufficient: a signature that
 * covers only the instruction leaves every signed byte sequence reusable
 * somewhere it was never meant to go. Three attacks follow from the one defect,
 * and every one of them is mounted below with genuine signatures produced by
 * production code. The attacker never forges anything, it only moves valid
 * bytes to a place the signature does not name.
 *
 *   A. a captured response envelope re-served under a different request id
 *   B. one captured command entry re-appended and executed again
 *   C. one captured command entry executed on a process it was not addressed to
 *
 * Each is a whole-envelope copy, which is why fixing them one at a time does
 * not work: the fix is that the signed bytes have to say where they belong.
 */

/** Stands in for the attached websocket server the drain checks for. */
class OwnerCommandBindingServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}

/**
 * A bus attached to one process key, ready to drain its own command stream.
 *
 * @return array{0: OwnerCommandBus, 1: string} the bus and its process key
 */
function bindingBus(string $instanceId, callable $executor): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext = new WorkerContext($config);
    $workerContext->boot(OwnerCommandBindingServer::make(), 0);

    $bus = new OwnerCommandBus(
        $config,
        new ResourceRouter($config, $workerContext),
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );
    $bus->useExecutor($executor);

    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, OwnerCommandBindingServer::make());

    return [$bus, $workerContext->currentProcessKey()];
}

function drainBindingCommands(OwnerCommandBus $bus): void
{
    $drain = new ReflectionMethod($bus, 'drainCommands');
    $drain->setAccessible(true);
    $drain->invoke($bus);
}

function waitForBindingResponse(OwnerCommandBus $bus, string $requestId): array
{
    $wait = new ReflectionMethod($bus, 'waitForResponse');
    $wait->setAccessible(true);

    return $wait->invoke($bus, $requestId);
}

/**
 * Everything an attacker with Redis READ access sees on a command stream.
 *
 * Exactly the bytes the owning worker will read: the signed message and its
 * signature, verbatim. Nothing here needs the app secret.
 *
 * @return array<int, array<string, string|null>>
 */
function capturedCommandEntries(string $processKey): array
{
    $entries = RedisStreams::read(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        '0-0',
        100,
    );

    return array_map(
        fn (array $entry) => array_filter(RedisStreams::normalizeFields(is_array($entry[1] ?? null) ? $entry[1] : [])),
        $entries,
    );
}

/** Re-append captured bytes to a stream, which is all any of these attacks does. */
function replayCommandEntry(string $processKey, array $fields): void
{
    RedisStreams::add(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        $fields,
        1000,
    );
}

/** The request id an attacker reads straight out of the plaintext command stream. */
function requestIdFromEntry(array $fields): string
{
    return (string) (json_decode((string) $fields['message'], true)['request_id'] ?? '');
}

beforeEach(function () {
    config()->set('lightspeed.reverb_compat.app_secret', 'owner-command-binding-secret');
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 0);

    $this->resourceId = 'lightspeed-test-'.bin2hex(random_bytes(8));
    $this->streamKeys = [];
    $this->responseKeys = [];
});

afterEach(function () {
    Redis::connection()->del("lightspeed:resource-owner:{$this->resourceId}");
    Redis::connection()->del("lightspeed:resource-write:{$this->resourceId}:lease");

    foreach ($this->streamKeys as $key) {
        Redis::connection()->del($key);
    }

    foreach ($this->responseKeys as $requestId) {
        Redis::connection()->del("lightspeed:owner-command-response:{$requestId}");
        Redis::connection()->del("lightspeed:owner-command-seen:{$requestId}");
    }
});

test('ATTACK A: a captured response envelope cannot be re-served under another request id', function () {
    // The owner really runs, and really signs what it returns. The attacker
    // never forges an envelope; it keeps one.
    [$owner, $ownerProcessKey] = bindingBus('binding-a-owner', fn () => ['ok' => true, 'result' => ['applied' => true]]);
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = bindingBus('binding-a-caller', fn () => null);

    // 1. A real command. The caller's wait is shorter than the owner's drain
    //    (blocking_wait_timeout_ms ships at 250ms), so it gives up without ever
    //    reading. And therefore without ever DELing. The response.
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 1]);
    drainBindingCommands($owner);

    $capturedRequestId = requestIdFromEntry(capturedCommandEntries($ownerProcessKey)[0]);
    $this->responseKeys[] = $capturedRequestId;

    // 2. The orphan sits in Redis for response_ttl_seconds (30) and is validly
    //    signed. One GET is the whole capture.
    $capturedEnvelope = Redis::connection()->get("lightspeed:owner-command-response:{$capturedRequestId}");
    expect($capturedEnvelope)->toBeString();

    // 3. A second, unrelated command is in flight. Its request id is a field of
    //    the command entry, in plaintext, on a stream the attacker can read.
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 2]);
    $liveEntries = capturedCommandEntries($ownerProcessKey);
    $liveRequestId = requestIdFromEntry($liveEntries[count($liveEntries) - 1]);
    $this->responseKeys[] = $liveRequestId;

    expect($liveRequestId)->not->toBe($capturedRequestId);

    // 4. The captured envelope, byte for byte, at the live request's key. The
    //    owner has not run this command and never will.
    Redis::connection()->setex("lightspeed:owner-command-response:{$liveRequestId}", 30, $capturedEnvelope);

    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);
    $result = waitForBindingResponse($caller, $liveRequestId);

    expect($result['ok'] ?? null)->toBeFalse()
        ->and($result['error'] ?? '')->toContain('signed');
});

test('ATTACK B: a captured command entry cannot be replayed onto the stream it came from', function () {
    $executed = [];
    [$owner, $ownerProcessKey] = bindingBus('binding-b-owner', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = bindingBus('binding-b-caller', fn () => null);
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'transfer-funds', ['amount' => 100]);

    $entry = capturedCommandEntries($ownerProcessKey)[0];
    $this->responseKeys[] = requestIdFromEntry($entry);

    // Five verbatim re-appends of bytes the application itself signed.
    for ($i = 0; $i < 5; $i++) {
        replayCommandEntry($ownerProcessKey, $entry);
    }

    drainBindingCommands($owner);

    // One legitimate delivery. The replays are spent ids, not new work.
    expect($executed)->toBe(['transfer-funds']);
});

test('ATTACK C: a captured command entry cannot be executed on a process it was not addressed to', function () {
    $ownerExecuted = [];
    [$owner, $ownerProcessKey] = bindingBus('binding-c-owner', function (OwnerCommand $command) use (&$ownerExecuted) {
        $ownerExecuted[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = bindingBus('binding-c-caller', fn () => null);
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);

    $entry = capturedCommandEntries($ownerProcessKey)[0];
    $this->responseKeys[] = requestIdFromEntry($entry);

    // A third process, which owns nothing and was never addressed. The entry is
    // copied verbatim onto its stream.
    $bystanderExecuted = [];
    [$bystander, $bystanderProcessKey] = bindingBus('binding-c-bystander', function (OwnerCommand $command) use (&$bystanderExecuted) {
        $bystanderExecuted[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$bystanderProcessKey}";

    replayCommandEntry($bystanderProcessKey, $entry);
    drainBindingCommands($bystander);

    expect($bystanderExecuted)->toBe([]);
});

test('a genuine owner command still executes once, end to end, with the binding in place', function () {
    // The positive half. Every assertion above is satisfied by a bus that
    // refuses everything, and this is what says it does not.
    $executed = [];
    [$owner, $ownerProcessKey] = bindingBus('binding-positive-owner', function (OwnerCommand $command) use (&$executed) {
        $executed[] = [$command->command, $command->payload];

        return ['ok' => true, 'result' => ['nodes' => 3]];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = bindingBus('binding-positive-caller', fn () => null);
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);

    $requestId = requestIdFromEntry(capturedCommandEntries($ownerProcessKey)[0]);
    $this->responseKeys[] = $requestId;

    drainBindingCommands($owner);

    expect($executed)->toBe([['apply-edit', ['nodes' => 3]]]);

    // And the caller can still read the answer the owner wrote for it.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);
    expect(waitForBindingResponse($caller, $requestId))->toBe(['ok' => true, 'result' => ['nodes' => 3]]);
});

test('a stale command is refused even though its signature is genuine', function () {
    $executed = [];
    [$owner, $ownerProcessKey] = bindingBus('binding-stale-owner', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = bindingBus('binding-stale-caller', fn () => null);
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 1]);

    $this->responseKeys[] = requestIdFromEntry(capturedCommandEntries($ownerProcessKey)[0]);

    // A capture held past the freshness window is no longer deliverable, so the
    // window is what bounds how long one is worth keeping.
    config()->set('lightspeed.owner_commands.freshness_seconds', 1);
    sleep(2);

    drainBindingCommands($owner);

    expect($executed)->toBe([]);
});

test('a flood of refused commands does not become a flood of log writes', function () {
    // read_count is 100 and poll_interval_ms is 25, so an unthrottled warning
    // per refused entry is ~4000 blocking log writes a second on one worker's
    // event loop. The signature refuses the entry correctly; the point here is
    // that refusing must not cost more than accepting.
    [$bus, $processKey] = bindingBus('binding-log-flood', fn () => ['ok' => true]);
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    for ($i = 0; $i < 50; $i++) {
        replayCommandEntry($processKey, [
            'message' => json_encode(['request_id' => "flood-{$i}", 'command' => 'x', 'payload' => []]),
            'signature' => 'not-a-signature',
        ]);
    }

    $logged = 0;
    Log::listen(function (MessageLogged $message) use (&$logged) {
        if (str_contains($message->message, 'owner command')) {
            $logged++;
        }
    });

    drainBindingCommands($bus);

    expect($logged)->toBeLessThanOrEqual(2);
});

test('a handler result that cannot be encoded answers the caller instead of leaving it to time out', function () {
    // Invalid UTF-8 makes json_encode throw. That throw used to escape the
    // per-entry try, so no response was written at all and the caller sat out
    // its whole timeout for a failure the owner already knew about.
    [$owner, $ownerProcessKey] = bindingBus('binding-encode-owner', fn () => ['ok' => true, 'data' => "\xB1\x31"]);
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = bindingBus('binding-encode-caller', fn () => null);
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 1]);

    $requestId = requestIdFromEntry(capturedCommandEntries($ownerProcessKey)[0]);
    $this->responseKeys[] = $requestId;

    drainBindingCommands($owner);

    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);
    $result = waitForBindingResponse($caller, $requestId);

    expect($result['ok'] ?? null)->toBeFalse()
        ->and($result['error'] ?? '')->toContain('encode');
});
