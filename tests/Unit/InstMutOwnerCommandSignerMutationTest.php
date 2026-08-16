<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Owner\OwnerCommandSigner;

/**
 * The parts of the owner-command signing scheme that nothing else pins.
 *
 * OwnerCommandSigningTest drives the whole bus and so proves the scheme end to
 * end. What it cannot see is what the signer does when the mechanism it depends
 * on is not there: a Redis it cannot reach, or a connection name the
 * application has misconfigured. Both of those decide whether a signed entry
 * executes, so both are behaviour, not plumbing.
 */
function instMutSigner(): OwnerCommandSigner
{
    return new OwnerCommandSigner(app('config'));
}

function instMutClaimKey(string $requestId): string
{
    return "lightspeed:owner-command-seen:{$requestId}";
}

beforeEach(function () {
    config()->set('lightspeed.reverb_compat.app_secret', 'inst-mut-signer-secret');
    config()->set('lightspeed.owner_commands.redis_connection', 'default');

    $this->instMutRequestId = 'inst-mut-'.bin2hex(random_bytes(8));
});

afterEach(function () {
    Redis::connection()->del(instMutClaimKey($this->instMutRequestId));
});

/**
 * A request id is spendable exactly once.
 *
 * The claim is half of the replay protection: the freshness window bounds how
 * long a captured entry stays deliverable, and this bounds how many times. A
 * claim that reported anything other than a plain true on the first spend, or
 * anything other than false on the second, would either refuse every real
 * command or admit every replayed one.
 */
it('spends a request id once and refuses it after that', function () {
    $signer = instMutSigner();

    expect($signer->claimRequestId($this->instMutRequestId))->toBeTrue();
    expect($signer->claimRequestId($this->instMutRequestId))->toBeFalse();
});

/**
 * A Redis that cannot be reached refuses the command instead of admitting it.
 *
 * The direction is the point. A bus that cannot tell whether an id was already
 * spent does not know whether the mutation it is holding has already run, so
 * the only safe answer is no. A connection name that does not resolve is the
 * cheapest way to reach that path without taking Redis away from the suite.
 */
it('refuses to claim when the configured redis connection does not exist', function () {
    config()->set('lightspeed.owner_commands.redis_connection', 'inst-mut-no-such-connection');

    expect(instMutSigner()->claimRequestId($this->instMutRequestId))->toBeFalse();

    config()->set('lightspeed.owner_commands.redis_connection', 'default');

    expect(Redis::connection()->exists(instMutClaimKey($this->instMutRequestId)))->toBe(0);
});

/**
 * The spent claim has to outlive the last instant its entry is still fresh.
 *
 * A TTL of exactly twice the window expires on the very second the entry is
 * still accepted, which readmits it. The +1 is the whole property, so it is
 * pinned against the window it is derived from rather than against a number.
 */
it('keeps a spent id for longer than the widest window its entry is fresh in', function () {
    config()->set('lightspeed.owner_commands.freshness_seconds', 4);

    $signer = instMutSigner();

    expect($signer->spentIdTtlSeconds())->toBeGreaterThan($signer->freshnessSeconds() * 2);

    $signer->claimRequestId($this->instMutRequestId);

    expect(Redis::connection()->ttl(instMutClaimKey($this->instMutRequestId)))
        ->toBeGreaterThan($signer->freshnessSeconds() * 2);
});
