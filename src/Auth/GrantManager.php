<?php

namespace Lightspeed\Auth;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The application-facing half of per-message authorization: what `Lightspeed::`
 * resolves to.
 *
 * Two verbs, and there are only two on purpose.
 *
 *   Lightspeed::tag([...])->with([...])   in routes/channels.php, at auth time
 *   Lightspeed::revoke('project:7')       anywhere, when access changes
 *
 * There is no separate per-user and per-group operation. `revoke('user:42')`
 * and `revoke('project:7')` are the identical call; the only thing that decides
 * which connections they reach is which tags the application attached when the
 * grant was minted. The consequence is the application's to accept: a tag that
 * was never attached cannot be revoked, because the package has no way to guess
 * what a connection should have been tagged with.
 *
 * The whole feature is opt-in through tag(). An application that never calls it
 * signs the same two-part auth strings it always did, subscribes with no Redis
 * call at all, and its message path is byte for byte what it was before this
 * existed.
 */
class GrantManager
{
    public function __construct(
        private readonly PendingGrants $pending,
        private readonly RevocationLog $revocations,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Declare the tags this connection's grant rides on.
     *
     * Called from a `Broadcast::channel()` callback, where the application has
     * just decided the same question against its database. Nothing is signed
     * until that callback returns successfully; see LightspeedBroadcaster.
     *
     * Both limits below reject rather than truncate. The reverted build
     * truncated an over-long tag to fit a fixed-width mirror row, which minted a
     * grant carrying `project:12` while `revoke('project:1234...')` wrote a
     * different key: a connection that could never be revoked, and a
     * revocation that could never land, neither of which said anything. A tag
     * that will not work has to fail where it was written.
     *
     * @param  list<string>|string  $tags
     *
     * @throws \InvalidArgumentException on an empty, non-string, over-long, or
     *                                   over-numerous tag
     */
    public function tag(array|string $tags): PendingGrant
    {
        $maxLength = $this->maxTagLength();
        $normalized = [];

        foreach ((array) $tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new \InvalidArgumentException('Lightspeed tags must be non-empty strings.');
            }

            if (strlen($tag) > $maxLength) {
                throw new \InvalidArgumentException(sprintf(
                    'Lightspeed tag [%s...] is %d bytes, over the %d-byte limit in lightspeed.auth.max_tag_length.',
                    substr($tag, 0, 32),
                    strlen($tag),
                    $maxLength,
                ));
            }

            $normalized[$tag] = true;
        }

        $normalized = array_map('strval', array_keys($normalized));

        if ($normalized === []) {
            throw new \InvalidArgumentException(
                'Lightspeed::tag() needs at least one tag; a grant with no tags could never be revoked.'
            );
        }

        // Every tag is one key in the per-message MGET and one entry in the
        // signed string that every client carries, so the count is bounded
        // here rather than discovered as a slow message path in production.
        $maxTags = $this->maxTags();
        if (count($normalized) > $maxTags) {
            throw new \InvalidArgumentException(sprintf(
                'Lightspeed::tag() was given %d tags, over the limit of %d in lightspeed.auth.max_tags.',
                count($normalized),
                $maxTags,
            ));
        }

        $grant = new PendingGrant($normalized);

        $this->pending->put($grant);

        return $grant;
    }

    /**
     * Refuse every message from every connection whose grant carries this tag,
     * and take those connections out of the fan-out on the spot.
     *
     * Immediate in both directions. The sending direction is the authoritative
     * per-message read, which every worker performs against Redis and which is
     * therefore correct the instant this call returns. The receiving direction
     * is the relay notification that unsubscribes the connections; that one is
     * fast rather than guaranteed, and its worst case is that a connection keeps
     * receiving until its grant expires.
     *
     * A version bump, not a blacklist: the tag records WHEN it was revoked, and
     * only grants issued before that are refused. A connection that
     * re-subscribes is allowed straight back if the application's own
     * authorization still says yes, and nothing accumulates in Redis because the
     * record expires once no grant can predate it.
     *
     * @param  list<string>|string  $tags
     */
    public function revoke(array|string $tags): void
    {
        foreach ((array) $tags as $tag) {
            if (!is_string($tag) && !is_int($tag)) {
                throw new \InvalidArgumentException('Lightspeed can only revoke non-empty string tags.');
            }

            // Cast, because `revoke(['7'])` arrives here with the tag as the
            // string it was written as, but `revoke($map)` and array keys in
            // general do not survive as strings. The reverted build guarded
            // with is_string() and silently dropped exactly this case.
            $tag = (string) $tag;

            if ($tag === '') {
                throw new \InvalidArgumentException('Lightspeed can only revoke non-empty string tags.');
            }

            $this->revocations->revoke($tag);
        }
    }

    private function maxTagLength(): int
    {
        return max(1, (int) $this->config->get('lightspeed.auth.max_tag_length', 191));
    }

    private function maxTags(): int
    {
        return max(1, (int) $this->config->get('lightspeed.auth.max_tags', 16));
    }
}
