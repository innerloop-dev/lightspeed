<?php

namespace Lightspeed\Auth;

/**
 * The grant an application is describing during /broadcasting/auth, before the
 * channel callback has returned and before anything is signed.
 *
 * It exists so `Lightspeed::tag([...])->with([...])` reads as one statement in
 * routes/channels.php while still letting the channel callback go on to return
 * false. Nothing is committed here: the broadcaster folds a pending grant into
 * the auth string only after Laravel's own authorization has passed, and drops
 * it otherwise.
 *
 * `with()` is separate from `tag()` because the two answer different questions
 * (which revocations does this connection ride on, and what may it do), and an
 * application that only needs revocation can skip the payload entirely.
 */
final class PendingGrant
{
    /** @var array<array-key, mixed> */
    private array $payload = [];

    /** @param  list<string>  $tags */
    public function __construct(
        private readonly array $tags,
    ) {
    }

    /**
     * Attach the opaque payload the message handler will see.
     *
     * Its shape is entirely the application's: the package signs it, carries it
     * onto every client event for this connection as `$event->auth`, and never
     * reads a key of it.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function with(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return array<array-key, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
