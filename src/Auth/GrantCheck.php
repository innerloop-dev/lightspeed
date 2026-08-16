<?php

namespace Lightspeed\Auth;

use Lightspeed\Logging\RuntimeLogger;

/**
 * The authoritative read that decides whether a granted connection may proceed.
 *
 * One question, asked by exactly two callers: a subscribe that carries a grant,
 * and every message sent on a channel that holds one. It is its own file
 * because it is the whole of the per-message security claim, and because a
 * shortcut added to it anywhere else would be invisible.
 */
class GrantCheck
{
    public function __construct(
        private readonly RevocationLog $revocations,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * Why this grant must be refused, or null if the message may proceed.
     *
     * This is the per-message authorization check, and its cost is exactly one
     * uncached Redis MGET. It is never memoized, never mirrored into shared
     * memory, and never trusted from a previous frame. Every one of those
     * shortcuts existed in the design this replaces, and every one of them was
     * a way for a revoked connection to keep sending after something unrelated
     * went wrong: a table that would not allocate, a stream entry that was
     * trimmed, a Redis blip the relay never recovered from.
     *
     * A Redis that cannot answer REFUSES. Not "assume fine and log it", not
     * "fall back to the last known answer": the whole point of an authoritative
     * read is that no answer is not an answer. The refusal is a stale frame
     * rather than a dropped socket, so the client backs off and re-subscribes,
     * and re-subscribing fails too until Redis returns.
     *
     * The expiry check runs first because it is free and it is what makes an
     * outage bounded rather than open-ended: even if this method could not
     * reach Redis for an hour, every grant minted before the outage has run out
     * of lifetime and is refused on the local clock alone.
     *
     * @return ?string  'expired', 'revoked', 'unavailable', or null to proceed
     */
    public function grantRefusal(Grant $grant): ?string
    {
        if ($grant->hasExpired((int) (microtime(true) * 1_000_000))) {
            return 'expired';
        }

        try {
            return $this->revocations->isRevoked($grant) ? 'revoked' : null;
        } catch (\Throwable $e) {
            $this->runtimeLogger->logWebsocket('error', [
                'reason' => 'revocation-check-unavailable',
                'message' => $e->getMessage(),
            ]);

            return 'unavailable';
        }
    }
}
