<?php

use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\OwnerCommandDispatcher;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Protocol\PublishRequestVerifier;
use Lightspeed\Protocol\SubscriptionAuthorizer;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Tests\Support\ConstantTimeProbe;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Every signature comparison in this package is constant-time, and it is the
 * comparison that DECIDES.
 *
 * Replacing `hash_equals($expected, $signature)` with `$expected == $signature`
 * changes nothing a behavioural test can see: the same signatures are accepted
 * and the same ones refused, on every input a test can construct. What is lost
 * is the property the comparison was chosen for, that the time it takes does
 * not depend on how many leading bytes of the candidate were right. And a test
 * cannot observe that, because a PHP test process measuring nanoseconds of its
 * own string comparison measures its garbage collector.
 *
 * WHAT THIS FILE USED TO BE, AND WHY IT WAS NOT ENOUGH. It asserted that each
 * verifying file CONTAINS its `hash_equals(...)` expression. A reviewer left
 * every one of those lines exactly where it was, added a `==` above that decided
 * the result first, and all 308 tests passed. The `hash_equals` line was still
 * present, still matched, and completely unreachable. Worse, the file with the
 * comparison an anonymous socket can reach, 
 * Protocol/SubscriptionAuthorizer.php, the websocket channel-auth check, was
 * not in the pinned list at all.
 *
 * So presence is now the SECOND of two checks, and the first is reachability:
 * each comparison is located in the source, and then its answer is inverted at
 * that exact file and line while a REAL verification runs. If flipping the
 * comparison's answer flips the verification's outcome, that comparison is what
 * decided it. A `hash_equals` line that has been stepped in front of fails this,
 * because inverting a line nothing consults changes nothing.
 *
 * See tests/Support/ConstantTimeProbe.php for how the answer is intercepted
 * without modifying a byte of src/.
 *
 * ALL FIVE PLACES, in descending order of how much it matters:
 *
 *   SubscriptionAuthorizer   The websocket channel-auth check. Reachable by
 *                            ANYONE who can open a socket, which is the whole
 *                            internet, and the thing it guards is subscription
 *                            to private and presence channels. It was not
 *                            covered here at all until this rewrite.
 *   PublishRequestVerifier   The public HTTP surface. Its own docblock calls
 *                            this "the only thing standing between an
 *                            unauthenticated caller and every connected
 *                            client", and an attacker gets to make the requests
 *                            and time the responses themselves, from anywhere.
 *   Owner commands           Two comparisons: the command signature, in
 *                            OwnerCommandSigner (remote execution of the
 *                            application's own write path), and the addressing
 *                            check in OwnerCommandBus that says the command was
 *                            written for THIS worker. Reachable only by
 *                            something that can already write to Redis, so the
 *                            leak is Redis-local, but what it buys is the worst
 *                            thing in the package.
 *   RedisRelay               Control entries, which can only ever deny.
 */

/**
 * The exact comparison each file must still be making.
 *
 * Written out verbatim rather than matched loosely, because "the file mentions
 * hash_equals somewhere" is satisfied by a file that mentions it in a comment
 * while comparing with `==` on the line that decides. The line NUMBER is not
 * written down here, it is found by locating this expression, so that editing
 * the file above a comparison does not fail a security test for no reason,
 * while removing or replacing the comparison fails it loudly.
 *
 * @var array<string, list<string>>
 */
$constantTimeComparisons = [
    'Protocol/SubscriptionAuthorizer.php' => [
        'if (!hash_equals($expected, $auth)) {',
    ],
    'Protocol/PublishRequestVerifier.php' => [
        'return hash_equals($expected, $signature);',
    ],
    'Relay/RedisRelay.php' => [
        'hash_equals($this->controlSignature($type, $encodedPayload), $signature)',
    ],
    'Owner/OwnerCommandSigner.php' => [
        'hash_equals($this->commandSignature($prefix, $fields), $signature)',
    ],
    'Owner/OwnerCommandBus.php' => [
        'hash_equals($this->workerContext->currentProcessKey(), $targetProcessKey)',
    ],
];

/**
 * Check that every named file still contains every expression it must, and say
 * which line each one is on.
 *
 * @param  array<string, list<string>>  $requirements
 * @return array{checked: int, offences: list<string>, lines: array<string, int>}
 */
function scanForConstantTimeComparisons(string $root, array $requirements): array
{
    $checked = 0;
    $offences = [];
    $lines = [];

    foreach ($requirements as $relative => $expressions) {
        $path = $root.'/'.$relative;

        if (!is_file($path)) {
            $offences[] = "{$relative} is not there to check";

            continue;
        }

        $contents = (string) file_get_contents($path);
        $sourceLines = explode("\n", $contents);

        foreach ($expressions as $expression) {
            $checked++;

            $found = null;

            foreach ($sourceLines as $index => $line) {
                if (str_contains($line, $expression)) {
                    $found = $index + 1;
                    break;
                }
            }

            if ($found === null) {
                $offences[] = "{$relative} no longer compares in constant time: expected [{$expression}]";

                continue;
            }

            $lines[$relative.'#'.$expression] = $found;
        }
    }

    return ['checked' => $checked, 'offences' => $offences, 'lines' => $lines];
}

/** Where, in src/, the given comparison lives right now. */
function constantTimeComparisonLine(string $relative, string $expression): int
{
    $result = scanForConstantTimeComparisons(dirname(__DIR__, 2).'/src', [$relative => [$expression]]);

    expect($result['offences'])->toBe([], "{$relative} no longer contains [{$expression}]");

    return $result['lines'][$relative.'#'.$expression];
}

/**
 * Assert that one comparison is REACHED by a real verification and DECIDES it.
 *
 * $verify runs a genuine, correctly signed verification and returns whether it
 * was accepted. Three things then have to hold, and each rules out a different
 * way the guard could be decorative:
 *
 *   the honest run is accepted   the scenario is a real success, so the test is
 *                                not satisfied by code that refuses everything
 *   the line was reached         the comparison ran at all
 *   inverting it refuses         its answer, and not something above it, is
 *                                what the outcome was built from
 */
function expectComparisonDecides(string $relative, string $expression, callable $verify): void
{
    $line = constantTimeComparisonLine($relative, $expression);

    ConstantTimeProbe::forget();

    expect($verify())->toBeTrue("{$relative}:{$line}. The honest scenario was refused, so this proves nothing");
    expect(ConstantTimeProbe::reached('src/'.$relative, $line))
        ->toBeTrue("{$relative}:{$line} was never reached by a verification that should have consulted it");

    $invertedOutcome = ConstantTimeProbe::invertedAt('src/'.$relative, $line, $verify);

    expect($invertedOutcome)
        ->toBeFalse("{$relative}:{$line} does not decide the outcome: inverting its answer changed nothing, so something above it is deciding");
}

// ---------------------------------------------------------------------------
// Reachability, one verification path at a time
// ---------------------------------------------------------------------------

/**
 * The one an anonymous socket reaches. Every `Broadcast::channel()` rule an
 * application writes is enforced by this comparison and nothing else.
 */
test('the websocket channel-auth signature is compared in constant time, and decides', function () {
    $authorizer = new SubscriptionAuthorizer('test-key', 'test-secret');
    $socketId = '1234.5678';
    $channel = 'private-orders';

    $auth = 'test-key:'.hash_hmac(
        'sha256',
        SubscriptionAuthorizer::signingString($socketId, $channel, null, null),
        'test-secret',
    );

    expectComparisonDecides(
        'Protocol/SubscriptionAuthorizer.php',
        'if (!hash_equals($expected, $auth)) {',
        static fn (): bool => !$authorizer->authorize($socketId, $channel, $auth, null)->denied(),
    );
});

test('the publish request signature is compared in constant time, and decides', function () {
    $verifier = new PublishRequestVerifier('test-app', 'test-key', 'test-secret');

    $body = '{"name":"OrderShipped"}';
    $path = '/apps/test-app/events';
    $query = [
        'auth_key' => 'test-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_md5' => md5($body),
    ];

    ksort($query);
    $stringToSign = "POST\n{$path}\n".http_build_query($query);
    $query['auth_signature'] = hash_hmac('sha256', $stringToSign, 'test-secret');

    expectComparisonDecides(
        'Protocol/PublishRequestVerifier.php',
        'return hash_equals($expected, $signature);',
        static fn (): bool => $verifier->verify('POST', $path, $query, $body),
    );
});

test('the relay control signature is compared in constant time, and decides', function () {
    config()->set('lightspeed.relay.enabled', true);

    $relay = app(RedisRelay::class);

    $heard = 0;
    $relay->onControl('constant-time-probe', function () use (&$heard) {
        $heard++;
    });

    $payload = json_encode(['n' => 1], JSON_UNESCAPED_SLASHES);

    $signatureOf = new ReflectionMethod(RedisRelay::class, 'controlSignature');
    $signatureOf->setAccessible(true);
    $signature = $signatureOf->invoke($relay, 'constant-time-probe', $payload);

    $deliver = new ReflectionMethod(RedisRelay::class, 'deliverControl');
    $deliver->setAccessible(true);

    expectComparisonDecides(
        'Relay/RedisRelay.php',
        'hash_equals($this->controlSignature($type, $encodedPayload), $signature)',
        static function () use ($deliver, $relay, $payload, $signature, &$heard): bool {
            $before = $heard;
            $deliver->invoke($relay, 'constant-time-probe', $payload, $signature);

            return $heard > $before;
        },
    );
});

/**
 * A bus attached to one process key, with an executor that records what it ran.
 *
 * Mirrors OwnerCommandSigningTest's harness rather than reaching into it,
 * because a global function declared in another test file is only there if that
 * file happened to be loaded first.
 *
 * @return array{0: OwnerCommandBus, 1: string, 2: object}
 */
function constantTimeOwnerBus(string $instanceId): array
{
    $config = app('config');
    $config->set('lightspeed.server.instance_id', $instanceId);
    $config->set('lightspeed.resources.write_lease_retries', 1);
    $config->set('lightspeed.resources.write_lease_wait_us', 0);
    $config->set('lightspeed.owner_commands.blocking_wait_timeout_ms', 0);

    $workerContext = new WorkerContext($config);
    $workerContext->boot((new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor(), 0);

    $bus = new OwnerCommandBus(
        $config,
        new ResourceRouter($config, $workerContext),
        new OwnerCommandDispatcher($config, app()),
        $workerContext,
    );

    $ran = new class
    {
        public int $count = 0;
    };

    $bus->useExecutor(function (OwnerCommand $command) use ($ran) {
        $ran->count++;

        return ['ok' => true];
    });

    $attached = new ReflectionProperty($bus, 'server');
    $attached->setAccessible(true);
    $attached->setValue($bus, (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor());

    return [$bus, $workerContext->currentProcessKey(), $ran];
}

/**
 * Both comparisons in the owner-command drain, proven separately.
 *
 * Separately is the point: the drain makes two constant-time comparisons in the
 * same namespace, one for the signature and one for the addressing, and a probe
 * that inverted the whole file could not tell which of them was carrying the
 * refusal. Inverting is scoped to one file and line, so each is proven on its
 * own.
 */
test('both owner-command comparisons are constant time, and each decides on its own', function () {
    $resourceId = 'lightspeed-constant-time-'.bin2hex(random_bytes(8));

    [$owner, $ownerProcessKey, $ran] = constantTimeOwnerBus('constant-time-owner');

    $ownerRouter = new ReflectionProperty($owner, 'resourceRouter');
    $ownerRouter->setAccessible(true);
    $ownerRouter->getValue($owner)->claimOwner($resourceId, 'test');

    [$caller] = constantTimeOwnerBus('constant-time-caller');

    $drain = new ReflectionMethod(OwnerCommandBus::class, 'drainCommands');
    $drain->setAccessible(true);

    // One genuinely signed command, drained. The package signs it, the package
    // verifies it, and the executor running is the proof it was accepted.
    $executeOne = static function () use ($caller, $owner, $resourceId, $drain, $ran): bool {
        $caller->forwardIfOwnedByAnotherProcess($resourceId, 'apply-edit', ['nodes' => 3]);

        $before = $ran->count;
        $drain->invoke($owner);

        return $ran->count > $before;
    };

    try {
        expectComparisonDecides(
            'Owner/OwnerCommandSigner.php',
            'hash_equals($this->commandSignature($prefix, $fields), $signature)',
            $executeOne,
        );

        expectComparisonDecides(
            'Owner/OwnerCommandBus.php',
            'hash_equals($this->workerContext->currentProcessKey(), $targetProcessKey)',
            $executeOne,
        );
    } finally {
        $redis = \Illuminate\Support\Facades\Redis::connection();
        $redis->del("lightspeed:resource-owner:{$resourceId}");
        $redis->del("lightspeed:resource-write:{$resourceId}:lease");
        $redis->del("lightspeed:owner-commands:{$ownerProcessKey}");
    }
});

// ---------------------------------------------------------------------------
// The presence scan, which is what says "put it back" when one is removed
// ---------------------------------------------------------------------------

test('every signature in the package is still compared with hash_equals', function () use ($constantTimeComparisons) {
    $result = scanForConstantTimeComparisons(dirname(__DIR__, 2).'/src', $constantTimeComparisons);

    // The scan is only evidence if it actually looked at something. A
    // requirements list that had been emptied would otherwise pass in silence.
    expect($result['checked'])->toBe(5)
        ->and($result['offences'])->toBe([]);
});

/**
 * The guard is only worth having if it would catch the substitution, so this
 * runs THE SAME scanner over a tree that contains it.
 */
test('the scanner catches a signature comparison that stopped being constant time', function () use ($constantTimeComparisons) {
    $root = sys_get_temp_dir().'/lightspeed-constant-time-'.bin2hex(random_bytes(6));
    mkdir($root.'/Protocol', 0777, true);
    mkdir($root.'/Relay', 0777, true);
    mkdir($root.'/Owner', 0777, true);

    try {
        // Three comparisons still constant-time, and one mutated exactly as the
        // mutation testing run mutated it: hash_equals swapped for a loose
        // comparison, with the mention of hash_equals left behind in a comment
        // so that a laxer scanner would be fooled.
        file_put_contents($root.'/Protocol/SubscriptionAuthorizer.php', '<?php'."\n".'if (!hash_equals($expected, $auth)) {');
        file_put_contents($root.'/Protocol/PublishRequestVerifier.php', '<?php // was hash_equals'."\n".'return $expected == $signature;');
        file_put_contents($root.'/Relay/RedisRelay.php', '<?php hash_equals($this->controlSignature($type, $encodedPayload), $signature)');
        file_put_contents(
            $root.'/Owner/OwnerCommandSigner.php',
            '<?php hash_equals($this->commandSignature($prefix, $fields), $signature)',
        );
        file_put_contents(
            $root.'/Owner/OwnerCommandBus.php',
            '<?php hash_equals($this->workerContext->currentProcessKey(), $targetProcessKey)',
        );

        $result = scanForConstantTimeComparisons($root, $constantTimeComparisons);

        expect($result['checked'])->toBe(5)
            ->and($result['offences'])->toHaveCount(1)
            ->and($result['offences'][0])->toContain('PublishRequestVerifier.php');
    } finally {
        @unlink($root.'/Protocol/SubscriptionAuthorizer.php');
        @unlink($root.'/Protocol/PublishRequestVerifier.php');
        @unlink($root.'/Relay/RedisRelay.php');
        @unlink($root.'/Owner/OwnerCommandSigner.php');
        @unlink($root.'/Owner/OwnerCommandBus.php');
        @rmdir($root.'/Protocol');
        @rmdir($root.'/Relay');
        @rmdir($root.'/Owner');
        @rmdir($root);
    }
});

/**
 * A file that has been deleted or moved must fail LOUDLY rather than quietly
 * checking nothing, which is the failure mode the no-database scanner was
 * rewritten to remove.
 */
test('the scanner reports a file it cannot find rather than passing', function () {
    $result = scanForConstantTimeComparisons(sys_get_temp_dir(), [
        'Nowhere/Missing.php' => ['hash_equals($a, $b)'],
    ]);

    expect($result['offences'])->toHaveCount(1)
        ->and($result['offences'][0])->toContain('is not there to check');
});

/**
 * The probe sits in front of every one of these comparisons for the whole
 * suite, so what it does when nobody has asked it to invert anything is a
 * property the other ~330 tests rest on. It delegates, exactly.
 */
test('the probe is a faithful delegate when it is not inverting anything', function () {
    $namespaced = 'Lightspeed\Protocol\hash_equals';

    expect($namespaced('abc', 'abc'))->toBe(hash_equals('abc', 'abc'))
        ->and($namespaced('abc', 'abd'))->toBe(hash_equals('abc', 'abd'))
        ->and($namespaced('abc', ''))->toBe(hash_equals('abc', ''))
        ->and($namespaced('', ''))->toBe(hash_equals('', ''));

    // And it stops inverting when the closure ends, including on the throwing
    // path, otherwise one failing assertion would quietly turn every signature
    // check in the suite upside down.
    $line = constantTimeComparisonLine('Protocol/SubscriptionAuthorizer.php', 'if (!hash_equals($expected, $auth)) {');

    try {
        ConstantTimeProbe::invertedAt('src/Protocol/SubscriptionAuthorizer.php', $line, function () {
            throw new RuntimeException('an assertion blew up mid-inversion');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($namespaced('abc', 'abc'))->toBeTrue();
});
