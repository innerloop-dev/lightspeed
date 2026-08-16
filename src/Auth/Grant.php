<?php

namespace Lightspeed\Auth;

/**
 * One connection's authorization pass for one channel.
 *
 * Four parts:
 *
 *   tags       strings the application chose, e.g. ['user:42', 'project:7'].
 *   payload    whatever the application wants the message handler to see.
 *   issuedAt   when the grant was minted, in microseconds on REDIS's clock.
 *   expiresAt  issuedAt plus the configured grant lifetime.
 *
 * The package never looks inside a tag and never reads the payload. A tag is
 * only ever compared for equality against a revocation key, and the payload is
 * carried to the handler untouched. That is what keeps this generic: the
 * package cannot know what a permission IS, only whether the one it was handed
 * is still current.
 *
 * WHERE THIS LIVES IS THE WHOLE DESIGN. A grant is not stored anywhere. It is
 * encoded here, folded into the `auth` string the client must already present
 * to join a private or presence channel, and signed with the app secret along
 * with the socket id and channel name. The client echoes the string back
 * untouched (pusher-js treats `auth` as opaque), the server re-derives the
 * signature, and a grant that was stripped, swapped or edited simply fails to
 * verify: the subscription is refused, exactly as a forged signature is.
 *
 * The predecessor of this class kept the grant in Redis under a short TTL and
 * read it at subscribe. That created a state the package could not name: a
 * client presenting a valid, non-expiring auth string for which no grant could
 * be found. "The application never tagged this channel" and "the grant is gone"
 * were the same observation, and the package guessed. Every fail-open path in
 * the reverted build came from that guess. There is no absence case here: the
 * grant travels inside the credential, so it is present exactly when the
 * signature verifies.
 *
 * `issuedAt` is on Redis's clock, not on any application server's, because it
 * is compared against revocation timestamps that are also written from Redis's
 * clock. Two servers with skewed clocks would otherwise disagree about whether
 * a revoke came before or after a grant, which is the one comparison that has
 * to be right. See RevocationLog.
 *
 * TAGS ARE A JSON LIST, never a map keyed by tag. json_decode turns the object
 * key "7" into the integer 7, and the reverted build's `is_string()` guard then
 * dropped it silently, which made `revoke('7')` a permanent no-op for every
 * numeric tag. That was found on a live server. A list has no keys to coerce,
 * and decode() casts each element back to string anyway.
 */
final class Grant
{
    /**
     * @param  list<string>  $tags
     * @param  array<array-key, mixed>  $payload  opaque; never read here
     * @param  int  $issuedAt  microseconds, Redis clock
     * @param  int  $expiresAt  microseconds, Redis clock
     */
    public function __construct(
        public readonly array $tags,
        public readonly array $payload,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {
    }

    /**
     * Has this grant passed its lifetime, judged against the given instant?
     *
     * The instant is in microseconds, but unlike issuedAt it may come from the
     * local clock rather than from Redis. Expiry is a backstop and a coarse
     * one: a server whose clock is minutes out of step with the one that minted
     * the grant will run it minutes short or minutes long. The comparison that
     * has to be exact is the revocation one, and that is on Redis's clock at
     * both ends. See RevocationLog.
     *
     * THREE CALLERS, and the third is the one that makes expiry mean anything.
     * Two are client-initiated (the subscribe gate and the per-message check),
     * and for a while they were the only ones, so a connection that only ever
     * LISTENS was never judged at all: it went on receiving broadcasts
     * indefinitely past its grant's expiry, proven twice on live servers. The
     * third is the per-worker sweeper (GrantSweeper::sweepExpiredGrants), which asks
     * this question about the connections nobody else asks about.
     */
    public function hasExpired(int $nowMicros): bool
    {
        return $nowMicros >= $this->expiresAt;
    }

    /**
     * The grant as it rides inside the auth string.
     *
     * base64url, so the encoded form contains no `:` and cannot disturb the
     * `key:signature:grant` split, and no `+` or `/` that a URL or a header
     * would re-encode on the way through a client.
     *
     * The member names are one letter because this string is sent to every
     * client on every channel authorization, and the payload is the
     * application's, of unknown size.
     */
    public function encode(): string
    {
        $json = json_encode([
            't' => array_values($this->tags),
            'p' => (object) $this->payload,
            'i' => $this->issuedAt,
            'e' => $this->expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Rebuild a grant from its encoded form, or null if it is unusable.
     *
     * Null rather than an exception because the caller is the subscribe path
     * and its answer to an unusable grant is the same as its answer to a bad
     * signature: refuse. Note that reaching this method at all means the
     * signature has ALREADY verified, so a malformed grant here is a bug in the
     * application's own auth response rather than an attack, but it still
     * refuses, because a grant that cannot be read cannot be re-checked.
     */
    public static function decode(string $encoded): ?self
    {
        if ($encoded === '') {
            return null;
        }

        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($binary === false) {
            return null;
        }

        try {
            $decoded = json_decode($binary, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !is_array($decoded['t'] ?? null)) {
            return null;
        }

        if (!is_numeric($decoded['i'] ?? null) || !is_numeric($decoded['e'] ?? null)) {
            return null;
        }

        $tags = [];
        foreach ($decoded['t'] as $tag) {
            if (!is_string($tag) && !is_int($tag)) {
                return null;
            }

            // Cast back to string. A JSON list preserves "7" as a string, but
            // the cast costs nothing and makes the type here unconditional
            // rather than a property of how the encoder happened to write it.
            $tag = (string) $tag;

            if ($tag === '') {
                return null;
            }

            $tags[] = $tag;
        }

        // A grant with no tags cannot be revoked by anything, so it would be a
        // connection that claims to be re-checked and never is. tag() refuses
        // to mint one; refuse to accept one too.
        if ($tags === []) {
            return null;
        }

        return new self(
            $tags,
            is_array($decoded['p'] ?? null) ? $decoded['p'] : [],
            (int) $decoded['i'],
            (int) $decoded['e'],
        );
    }
}
