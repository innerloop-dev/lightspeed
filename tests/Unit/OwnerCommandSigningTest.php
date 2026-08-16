<?php

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Relay\RedisStreams;
use Lightspeed\Workers\WorkerContext;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The owner-command stream is a REMOTE EXECUTION path, so it is signed.
 *
 * A drained entry becomes a call into the application's own
 * `OwnerCommandHandler` with the command string and payload array the entry
 * carried, taken under the resource write lease so it is cleanly serialized
 * against every real mutation. Unsigned, that is arbitrary application command
 * execution for anyone who can write one Redis key. And no secret is needed to
 * find the key, because the stream is named after a process key that sits in
 * plaintext in `lightspeed:resource-owner:*` and is SCAN-able.
 *
 * That is categorically worse than the broadcast injection SECURITY.md admits
 * to: a forged broadcast puts a message on a socket, and a forged owner command
 * runs the application's write path.
 *
 * The response is signed for the same reason and by the same mechanism. The
 * request id an attacker would need in order to forge a response is not a
 * secret either, it is a field of the command entry they can already read.
 *
 * These tests drive the private drain and wait directly. The public path needs
 * a listening Swoole server, which a unit test has no business starting, and the
 * behaviour under test is entirely inside those two methods.
 */

/** Stands in for the attached websocket server the drain checks for. */
class OwnerCommandSigningServer extends SwooleServer
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
function signingBus(string $instanceId, callable $executor): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);

    $workerContext = new WorkerContext($config);
    $workerContext->boot(OwnerCommandSigningServer::make(), 0);

    $bus = new OwnerCommandBus(
        $config,
        new ResourceRouter($config, $workerContext),
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );
    $bus->useExecutor($executor);

    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, OwnerCommandSigningServer::make());

    return [$bus, $workerContext->currentProcessKey()];
}

function drainOwnerCommands(OwnerCommandBus $bus): void
{
    $drain = new ReflectionMethod($bus, 'drainCommands');
    $drain->setAccessible(true);
    $drain->invoke($bus);
}

/** Append one raw entry to a process's command stream, exactly as a caller would. */
function pushOwnerCommandEntry(string $processKey, array $fields): void
{
    RedisStreams::add(
        Redis::connection()->client(),
        "lightspeed:owner-commands:{$processKey}",
        $fields,
        1000,
    );
}

function ownerCommandMessage(string $requestId, string $resourceId, string $command = 'delete-everything'): string
{
    return json_encode([
        'request_id' => $requestId,
        'resource_id' => $resourceId,
        'command' => $command,
        'payload' => ['scope' => 'all'],
        'origin_process_key' => 'attacker',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    config()->set('lightspeed.reverb_compat.app_secret', 'owner-command-signing-secret');
    config()->set('lightspeed.resources.write_lease_retries', 1);
    config()->set('lightspeed.resources.write_lease_wait_us', 0);
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 0);

    $this->resourceId = 'lightspeed-test-'.bin2hex(random_bytes(8));
    $this->requestId = 'request-'.bin2hex(random_bytes(8));
    $this->streamKeys = [];
});

afterEach(function () {
    Redis::connection()->del("lightspeed:resource-owner:{$this->resourceId}");
    Redis::connection()->del("lightspeed:resource-write:{$this->resourceId}:lease");
    Redis::connection()->del("lightspeed:owner-command-response:{$this->requestId}");
    Redis::connection()->del("lightspeed:owner-command-seen:{$this->requestId}");

    foreach ($this->streamKeys as $key) {
        Redis::connection()->del($key);
    }
});

test('an unsigned owner command is refused rather than executed', function () {
    $executed = [];
    [$bus, $processKey] = signingBus('signing-unsigned', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    pushOwnerCommandEntry($processKey, [
        'message' => ownerCommandMessage($this->requestId, $this->resourceId),
    ]);

    drainOwnerCommands($bus);

    expect($executed)->toBe([]);
});

test('an owner command signed with the wrong secret is refused', function () {
    $executed = [];
    [$bus, $processKey] = signingBus('signing-wrong-secret', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $message = ownerCommandMessage($this->requestId, $this->resourceId);

    pushOwnerCommandEntry($processKey, [
        'message' => $message,
        'signature' => hash_hmac('sha256', "lightspeed.owner-command.v1\0".$message, 'not-the-app-secret'),
    ]);

    drainOwnerCommands($bus);

    expect($executed)->toBe([]);
});

test('an owner command edited after signing is refused', function () {
    $executed = [];
    [$bus, $processKey] = signingBus('signing-tampered', function (OwnerCommand $command) use (&$executed) {
        $executed[] = $command->command;

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $signed = ownerCommandMessage($this->requestId, $this->resourceId, 'harmless');

    pushOwnerCommandEntry($processKey, [
        // The signature is genuine; the message it travels with is not the one
        // it covers. This is the shape of the attack the signature exists for.
        'message' => ownerCommandMessage($this->requestId, $this->resourceId, 'delete-everything'),
        'signature' => hash_hmac('sha256', "lightspeed.owner-command.v1\0".$signed, 'owner-command-signing-secret'),
    ]);

    drainOwnerCommands($bus);

    expect($executed)->toBe([]);
});

test('a refused owner command writes no response for the caller to read', function () {
    [$bus, $processKey] = signingBus('signing-no-response', fn () => ['ok' => true]);
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    pushOwnerCommandEntry($processKey, [
        'message' => ownerCommandMessage($this->requestId, $this->resourceId),
    ]);

    drainOwnerCommands($bus);

    expect(Redis::connection()->get("lightspeed:owner-command-response:{$this->requestId}"))->toBeNull();
});

test('a genuinely forwarded owner command still executes end to end', function () {
    // The positive half. Without it a verifier that refused everything would
    // satisfy every assertion above while breaking owner routing outright.
    $executed = [];
    [$owner, $ownerProcessKey] = signingBus('signing-owner', function (OwnerCommand $command) use (&$executed) {
        $executed[] = [$command->command, $command->payload];

        return ['ok' => true, 'result' => ['nodes' => 3]];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    // The owner claims the resource, so the caller's own claim resolves to it
    // and the command is forwarded rather than handled locally.
    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = signingBus('signing-caller', fn () => null);

    // The response is not there yet, so this returns the timeout error; what it
    // leaves behind on the owner's stream is what matters.
    $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);

    drainOwnerCommands($owner);

    expect($executed)->toBe([['apply-edit', ['nodes' => 3]]]);
});

/**
 * THE RESPONSE HAPPY PATH, WHICH NOTHING TESTED AT ALL.
 *
 * Every test above this line is a REFUSAL, and the "end to end" one stops at the
 * owner's handler: it never waits for the response, so nothing in the suite ever
 * saw a legitimate response accepted. That is not a gap in coverage of an edge,
 * it is the whole feature. Swapping the signing prefix on EITHER end. The sign
 * side in drainCommands(), or the verify side in waitForResponse(), left the
 * suite entirely green, while in production every forwarded owner command would
 * come back "response was not signed by this application for this request".
 *
 * So this drives the public entry point and asserts the value it returns. The
 * request id is frozen so the same id is minted twice: once to put a genuine
 * signed command on the owner's stream, and once to wait on the response the
 * owner signed for it. Nothing else is substituted. The command is signed by
 * the package, drained by the package, executed under the real write lease, and
 * the response is signed and verified by the package.
 *
 * The wait cannot happen concurrently with the drain in a unit test (there is no
 * scheduler: `enable_coroutine` is off and `sleepMicroseconds()` is a usleep on
 * this very process), so the two passes are what "the caller waited and the
 * owner answered" has to look like here. The second pass appends a command entry
 * that is never drained, which is harmless: the response it reads is the one the
 * first pass's command produced.
 */
test('a forwarded owner command returns the owner\'s signed response to its caller', function () {
    $executed = [];
    [$owner, $ownerProcessKey] = signingBus('signing-roundtrip-owner', function (OwnerCommand $command) use (&$executed) {
        $executed[] = [$command->command, $command->payload];

        return ['ok' => true, 'result' => ['nodes' => 3]];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$ownerProcessKey}";

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($this->resourceId, 'test');

    [$caller] = signingBus('signing-roundtrip-caller', fn () => null);

    $requestId = $this->requestId;
    Str::createUuidsUsing(fn () => $requestId);

    try {
        // Pass one writes the command. `blocking_wait_timeout_ms` is 0, so the
        // caller does not wait at all and this is the timeout error.
        $unanswered = $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);

        expect($unanswered['ok'])->toBeFalse()
            ->and($unanswered['error'])->toContain('timed out');

        // The owner drains it, runs it under the write lease, and signs what it
        // returns.
        drainOwnerCommands($owner);

        // Pass two waits, and this time there is a response to verify.
        config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

        $answered = $caller->forwardIfOwnedByAnotherProcess($this->resourceId, 'apply-edit', ['nodes' => 3]);
    } finally {
        Str::createUuidsNormally();
    }

    expect($executed)->toBe([['apply-edit', ['nodes' => 3]]])
        ->and($answered)->toBe(['ok' => true, 'result' => ['nodes' => 3]]);
});

/**
 * THE ON-WIRE BYTES, PINNED.
 *
 * Both ends of each direction share one constant, so a process stays
 * self-consistent no matter what the constant says: emptying
 * COMMAND_SIGNING_PREFIX, or changing its version suffix, is invisible to any
 * test that only ever has the package talk to itself. It is NOT invisible in
 * production, where a rolling deploy has an unmutated peer on the other end of
 * the same Redis stream and every command between the two halves is refused.
 *
 * These two tests are therefore the only ones in the file that write the
 * signature by hand, with the prefix and the length-prefixed field encoding
 * spelled out literally. They are the peer.
 */
test('a command signed with the exact bytes a peer would use is accepted', function () {
    $executed = [];
    [$bus, $processKey] = signingBus('signing-command-bytes', function (OwnerCommand $command) use (&$executed) {
        $executed[] = [$command->command, $command->payload];

        return ['ok' => true];
    });
    $this->streamKeys[] = "lightspeed:owner-commands:{$processKey}";

    $message = json_encode([
        'request_id' => $this->requestId,
        'resource_id' => $this->resourceId,
        'command' => 'apply-edit',
        'payload' => ['nodes' => 3],
        'origin_process_key' => 'a-peer-worker',
        'target_process_key' => $processKey,
        'issued_at' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    pushOwnerCommandEntry($processKey, [
        'message' => $message,
        'signature' => hash_hmac(
            'sha256',
            'lightspeed.owner-command.v2'.':'.strlen($message).':'.$message,
            'owner-command-signing-secret',
        ),
    ]);

    drainOwnerCommands($bus);

    expect($executed)->toBe([['apply-edit', ['nodes' => 3]]]);
});

test('a response signed with the exact bytes a peer would use is accepted', function () {
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = signingBus('signing-response-bytes', fn () => null);

    $issuedAt = time();
    $encodedResult = json_encode(['ok' => true, 'result' => 'from a peer'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    Redis::connection()->setex(
        "lightspeed:owner-command-response:{$this->requestId}",
        30,
        json_encode([
            'result' => $encodedResult,
            'issued_at' => $issuedAt,
            'signature' => hash_hmac(
                'sha256',
                'lightspeed.owner-command-response.v2'
                    .':'.strlen($this->requestId).':'.$this->requestId
                    .':'.strlen((string) $issuedAt).':'.$issuedAt
                    .':'.strlen($encodedResult).':'.$encodedResult,
                'owner-command-signing-secret',
            ),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $wait = new ReflectionMethod($caller, 'waitForResponse');
    $wait->setAccessible(true);

    expect($wait->invoke($caller, $this->requestId))->toBe(['ok' => true, 'result' => 'from a peer']);
});

test('a forged owner command response is refused rather than returned to the caller', function () {
    // Long enough for the poll loop to run at all; the response is already
    // there, so it is read on the first pass.
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = signingBus('signing-forged-response', fn () => null);

    Redis::connection()->setex(
        "lightspeed:owner-command-response:{$this->requestId}",
        30,
        json_encode(['ok' => true, 'result' => 'forged'], JSON_UNESCAPED_SLASHES),
    );

    $wait = new ReflectionMethod($caller, 'waitForResponse');
    $wait->setAccessible(true);
    $result = $wait->invoke($caller, $this->requestId);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('signed');
});

/**
 * RESPONSE FRESHNESS, WHICH NOTHING TESTED AT ALL.
 *
 * `waitForResponse()` refuses an envelope signed outside the freshness window,
 * and three independent mutations of that check all survived the suite:
 * deleting it outright, dropping the `abs()` so only the past half is bounded,
 * and setting the window to PHP_INT_MAX. Every one of those makes a captured
 * response envelope a PERMANENT credential for its request id.
 *
 * Why that matters even though the envelope is signed: the request id is not a
 * secret. It travels in the command entry any reader of the owner's stream can
 * see. Signing binds an envelope to one request id and one instant; without the
 * instant, anyone who ever captured a legitimate response can replay it at that
 * id's key forever and tell a caller that a mutation succeeded which never ran
 * this time. Freshness is what makes the signature a one-shot answer rather
 * than a standing one.
 *
 * The envelopes below are signed with the bytes a real peer signs, so what is
 * under test is the freshness check alone and not an accidental signature
 * failure.
 */
function writeSignedOwnerResponse(string $requestId, int $issuedAt, array $result): void
{
    $encodedResult = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    Redis::connection()->setex(
        "lightspeed:owner-command-response:{$requestId}",
        30,
        json_encode([
            'result' => $encodedResult,
            'issued_at' => $issuedAt,
            'signature' => hash_hmac(
                'sha256',
                'lightspeed.owner-command-response.v2'
                    .':'.strlen($requestId).':'.$requestId
                    .':'.strlen((string) $issuedAt).':'.$issuedAt
                    .':'.strlen($encodedResult).':'.$encodedResult,
                'owner-command-signing-secret',
            ),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}

function awaitOwnerResponse(OwnerCommandBus $bus, string $requestId): array
{
    $wait = new ReflectionMethod($bus, 'waitForResponse');
    $wait->setAccessible(true);

    return $wait->invoke($bus, $requestId);
}

/** The window belongs to OwnerCommandSigner; this is the one the bus holds. */
function ownerResponseWindow(OwnerCommandBus $bus): int
{
    $signer = new ReflectionProperty($bus, 'signer');
    $signer->setAccessible(true);

    return $signer->getValue($bus)->freshnessSeconds();
}

test('a correctly signed response from too long ago is refused', function () {
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = signingBus('signing-response-stale', fn () => null);
    $window = ownerResponseWindow($caller);

    writeSignedOwnerResponse($this->requestId, time() - $window - 1, ['ok' => true, 'result' => 'replayed']);

    $result = awaitOwnerResponse($caller, $this->requestId);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('freshness window');
});

/**
 * The other half of the window, and the one the missing `abs()` opens.
 *
 * Without `abs()`, `time() - issuedAt <= window` is satisfied by every instant
 * in the future, however distant: an envelope stamped a decade ahead is
 * accepted, and stays accepted for a decade. Symmetry is not tidiness here,
 * it is the difference between a bounded window and a half-open one.
 */
test('a correctly signed response stamped far in the future is refused', function () {
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = signingBus('signing-response-future', fn () => null);
    $window = ownerResponseWindow($caller);

    writeSignedOwnerResponse($this->requestId, time() + $window + 1, ['ok' => true, 'result' => 'from the future']);

    $result = awaitOwnerResponse($caller, $this->requestId);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('freshness window');
});

/**
 * And both edges are ACCEPTED, so none of the above is satisfied by a check
 * that refuses everything. Which would break owner routing outright while
 * every refusal test above passed.
 */
test('a correctly signed response at either edge of the window is accepted', function () {
    config()->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 250);

    [$caller] = signingBus('signing-response-edges', fn () => null);
    $window = ownerResponseWindow($caller);

    foreach ([-$window, 0, $window] as $offset) {
        $requestId = $this->requestId.'-'.($offset + $window);
        $this->streamKeys[] = "lightspeed:owner-command-response:{$requestId}";

        writeSignedOwnerResponse($requestId, time() + $offset, ['ok' => true, 'result' => "offset {$offset}"]);

        expect(awaitOwnerResponse($caller, $requestId))
            ->toBe(['ok' => true, 'result' => "offset {$offset}"]);
    }
});
