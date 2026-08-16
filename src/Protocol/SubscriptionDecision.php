<?php

namespace Lightspeed\Protocol;

use Lightspeed\Auth\Grant;

/**
 * The outcome of running one subscription request past SubscriptionAuthorizer.
 *
 * Why this file exists: three outcomes have to be told apart, and two of them
 * look identical if the answer is a bare value. "This channel is public, there
 * was nothing to authorize" and "this private channel was authorized, and a
 * private channel carries no presence member" both arrive as null; a caller
 * that wants to know whether a signature was actually checked then has to
 * re-derive the answer with its own isProtectedChannel() call, which is the
 * security question restated at the call site, in a place a reader would not
 * think to audit. Naming the outcome makes it one thing the authorizer decided
 * and the caller reads.
 *
 *   public channel   -> openChannel()   granted, authorization not required
 *   private, signed  -> granted(null)   granted, no member to carry
 *   presence, signed -> granted([...])  granted, with the verified member
 *   anything else    -> refused()       denied
 *
 * Owns: the vocabulary for those outcomes and nothing else.
 * Deliberately does not own: the decision itself (SubscriptionAuthorizer) or
 * what a refusal looks like on the wire (Server answers it with a
 * subscription_error frame).
 */
final class SubscriptionDecision
{
    private function __construct(
        /** Was a signature required at all, i.e. is this a protected channel? */
        public readonly bool $authorizationRequired,
        /** May the subscription proceed? */
        public readonly bool $granted,
        /**
         * The member identity verified out of channel_data, presence only.
         *
         * Null on every other outcome, which is why it must never be read as
         * the decision itself.
         *
         * @var ?array
         */
        public readonly ?array $presenceMember,
        /**
         * The per-message grant that was signed into the auth string, verified
         * and unexpired, or null when the application signed none.
         *
         * Null means the application never called `Lightspeed::tag()` for this
         * channel, and it can only mean that, which is the property the
         * reverted design lacked. A grant cannot go missing in transit here: it
         * is inside the signature, so a missing grant is a failed signature and
         * a refusal, never a null.
         */
        public readonly ?Grant $grant = null,
    ) {
    }

    /** A public channel: open by design, so there was nothing to authorize. */
    public static function openChannel(): self
    {
        return new self(authorizationRequired: false, granted: true, presenceMember: null);
    }

    /** A protected channel whose signature verified. */
    public static function granted(?array $presenceMember = null, ?Grant $grant = null): self
    {
        return new self(authorizationRequired: true, granted: true, presenceMember: $presenceMember, grant: $grant);
    }

    /** A protected channel whose request is refused. */
    public static function refused(): self
    {
        return new self(authorizationRequired: true, granted: false, presenceMember: null);
    }

    /** Must this subscription be rejected? */
    public function denied(): bool
    {
        return !$this->granted;
    }
}
