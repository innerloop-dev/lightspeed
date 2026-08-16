<?php

use Lightspeed\Auth\Grant;
use Lightspeed\Auth\GrantCheck;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Logging\RuntimeLogger;

/**
 * The whole of the per-message security claim: one method, three refusals.
 *
 * The one this file is about is `unavailable`. A Redis that cannot answer has
 * to refuse, and it has to SAY so: the refusal costs the client a re-subscribe
 * and tells an operator that the authoritative read is down, and a refusal
 * nobody can see is indistinguishable from a package that is simply broken.
 */

/** A revocation log whose Redis is gone. */
class AuthMutUnreachableRevocationLog extends RevocationLog
{
    public function __construct()
    {
    }

    public function isRevoked(Grant $grant): bool
    {
        throw new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
    }
}

/** A revocation log that answers, so the check can be seen letting a frame past. */
class AuthMutAnsweringRevocationLog extends RevocationLog
{
    public function __construct(private readonly bool $revoked)
    {
    }

    public function isRevoked(Grant $grant): bool
    {
        return $this->revoked;
    }
}

/** Records the runtime log lines instead of writing them to stdout. */
class AuthMutCheckLoggerSpy extends RuntimeLogger
{
    /** @var list<array{0: string, 1: array}> action and fields, in order */
    public array $lines = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->lines[] = [$action, $fields];
    }
}

function authMutLiveGrant(): Grant
{
    $now = (int) (microtime(true) * 1_000_000);

    return new Grant(['project:7'], [], $now - 1_000, $now + 300_000_000);
}

test('a revocation read that cannot reach Redis refuses and reports itself', function () {
    $logger = new AuthMutCheckLoggerSpy();
    $check = new GrantCheck(new AuthMutUnreachableRevocationLog(), $logger);

    expect($check->grantRefusal(authMutLiveGrant()))->toBe('unavailable')
        ->and($logger->lines)->toHaveCount(1);

    [$action, $fields] = $logger->lines[0];

    // The reason names the check, and the message carries the underlying
    // failure: without the second, the line says only that something somewhere
    // refused, which is what an outage looks like from the client anyway.
    expect($action)->toBe('error')
        ->and($fields['reason'] ?? null)->toBe('revocation-check-unavailable')
        ->and($fields['message'] ?? null)->toContain('Connection refused');
});

test('a live grant with no revocation proceeds, and says nothing', function () {
    $logger = new AuthMutCheckLoggerSpy();
    $check = new GrantCheck(new AuthMutAnsweringRevocationLog(false), $logger);

    expect($check->grantRefusal(authMutLiveGrant()))->toBeNull()
        ->and($logger->lines)->toBe([]);
});

test('expiry is judged before Redis is asked at all', function () {
    $logger = new AuthMutCheckLoggerSpy();
    $check = new GrantCheck(new AuthMutUnreachableRevocationLog(), $logger);

    $now = (int) (microtime(true) * 1_000_000);
    $expired = new Grant(['project:7'], [], $now - 2_000, $now - 1_000);

    // The expiry check is what makes an outage bounded rather than open-ended,
    // so it cannot be behind the read that is unavailable during one.
    expect($check->grantRefusal($expired))->toBe('expired')
        ->and($logger->lines)->toBe([]);
});

test('a revoked grant is refused as revoked', function () {
    $check = new GrantCheck(new AuthMutAnsweringRevocationLog(true), new AuthMutCheckLoggerSpy());

    expect($check->grantRefusal(authMutLiveGrant()))->toBe('revoked');
});
