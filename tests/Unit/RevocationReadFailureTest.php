<?php

/**
 * A revocation read that Redis did not answer must refuse, whatever the
 * grant's tag count.
 *
 * Found by mutation testing (RemoveStringCast on the mget line was SAFER
 * than the code): phpredis answers a failed MGET with `false`, and
 * `(array) false` is `[false]`. For a one-tag grant that array is the right
 * LENGTH, so the short-reply guard passed, `is_numeric(false)` skipped the
 * value, and isRevoked() answered "not revoked". One connection, one tag, a
 * Redis hiccup: a revoked grant's frames kept flowing. The docblock's own
 * contract ("@throws when Redis cannot answer; the caller MUST refuse") was
 * broken in exactly the commonest case.
 */

use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\RevocationLog;

test('a failed MGET refuses a one-tag grant instead of allowing it', function () {
    // A connection stub whose mget "fails" the way phpredis fails: false.
    $connection = new class
    {
        public function mget(array $keys): bool
        {
            return false;
        }
    };

    Redis::shouldReceive('connection')->andReturn($connection);

    $grant = new Grant(
        tags: ['project:demo'],
        payload: [],
        issuedAt: 1_700_000_000_000_000,
        expiresAt: PHP_INT_MAX,
    );

    // Refusal here means THROWING: GrantCheck turns the throw into a refusal
    // and a logged error. Returning false is the silent allow.
    expect(fn () => app(RevocationLog::class)->isRevoked($grant))
        ->toThrow(\RuntimeException::class);
});
