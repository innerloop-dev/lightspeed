<?php

namespace Lightspeed\Auth;

/**
 * The grants held by the connections attached to one worker, in memory.
 *
 * A grant arrives inside the auth string, is verified once at subscribe, and
 * lives here for the rest of the subscription so the message path does not have
 * to re-verify a signature it has already checked. What it does NOT save is the
 * per-message Redis read: that still happens on every frame, against the tags
 * kept here. This is a record of what was proven, not a cache of whether it is
 * still true.
 *
 * Keyed by fd for the same reason ChannelManager is: Swoole delivers every
 * frame for a connection to the worker that owns it, so an fd is a complete key
 * for the connection's whole life inside this process, and the close handler is
 * what removes it.
 *
 * The second index, tag to connections, is what `revoke()` walks to drop the
 * matching subscriptions immediately rather than waiting for their next
 * message. It is derived state and is rebuilt by nothing: every write goes
 * through mint()/forget() so the two views cannot drift.
 *
 * A grant that has already been refused is deliberately KEPT rather than
 * dropped. Dropping it would leave the connection with no grant at all, and no
 * grant is how an untagged application is recognised, so one refused frame
 * would silently unlock every frame after it. It stays until the connection
 * unsubscribes, is dropped by a revocation, or closes.
 */
class ConnectionGrants
{
    /** @var array<int, array<string, Grant>> fd => channel => grant */
    private array $grants = [];

    /**
     * @var array<array-key, array<int, array<string, true>>> tag => fd => channel
     *
     * PHP coerces a numeric string array key to an int, so a tag of "7" is
     * stored under the key 7. That is harmless HERE because every read is a
     * lookup by the same tag value, which coerces identically, but it is why
     * nothing in this class iterates these keys and treats them as tags. The
     * tag strings themselves live in the Grant, where JSON cannot mangle them.
     */
    private array $byTag = [];

    public function mint(int $fd, string $channel, Grant $grant): void
    {
        // A re-subscribe replaces the grant, so the old tags must stop pointing
        // here or a revoke of a tag the connection no longer carries would drop
        // a subscription it is entitled to.
        $this->forget($fd, $channel);

        $this->grants[$fd][$channel] = $grant;

        foreach ($grant->tags as $tag) {
            $this->byTag[$tag][$fd][$channel] = true;
        }
    }

    public function grantFor(int $fd, string $channel): ?Grant
    {
        return $this->grants[$fd][$channel] ?? null;
    }

    /**
     * Every grant one connection is carrying, keyed by channel.
     *
     * A pure read, and the close path's only chance to see any of this: the
     * teardown forgets the connection before it works out what it was, so
     * Connections\ConnectionClosed is built from a copy taken here first.
     *
     * The key type is `array-key` rather than `string` because it cannot be
     * anything else: PHP stores the array key "7" as the integer 7 and a cast
     * on the way out would be undone by the assignment that applied it. Callers
     * that care about a numeric channel name have to cast at the point they
     * read the key, which is why nothing here promises they will not have to.
     *
     * @return array<array-key, Grant>
     */
    public function grantsFor(int $fd): array
    {
        return $this->grants[$fd] ?? [];
    }

    /**
     * Every (fd, channel) carrying this tag with a grant issued before the
     * given instant.
     *
     * The instant matters: a connection that re-subscribed AFTER the revocation
     * holds a grant the application has just re-approved, and dropping it would
     * turn one revoke into an endless subscribe/drop loop for a user whose
     * access is fine.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function connectionsCarrying(string $tag, int $issuedBefore): array
    {
        $matches = [];

        foreach ($this->byTag[$tag] ?? [] as $fd => $channels) {
            foreach ($channels as $channel => $_) {
                $channel = (string) $channel;

                $grant = $this->grants[$fd][$channel] ?? null;

                if ($grant !== null && $grant->issuedAt <= $issuedBefore) {
                    $matches[] = [(int) $fd, $channel];
                }
            }
        }

        return $matches;
    }

    /**
     * Every (fd, channel) whose grant has run out of lifetime by this instant.
     *
     * This is what the per-worker sweeper walks, and it has to exist because
     * expiry is the one refusal nobody asks for. A connection that only ever
     * LISTENS never sends a frame, so no client-initiated path ever judges its
     * grant: before the sweeper, a lurker with a 3-second grant was proven
     * still receiving broadcasts at t=8s, and on a two-worker server 22 seconds
     * past expiry and 3.75 grant lifetimes past a revoke.
     *
     * A full scan, deliberately. The alternative is an expiry-ordered index,
     * which is more derived state to keep true through mint/forget/re-subscribe
     * in exchange for a walk that compares one integer per subscription, over
     * one worker's own connections, once a sweep interval.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function expiredBefore(int $nowMicros): array
    {
        $expired = [];

        foreach ($this->grants as $fd => $channels) {
            foreach ($channels as $channel => $grant) {
                if ($grant->hasExpired($nowMicros)) {
                    $expired[] = [(int) $fd, (string) $channel];
                }
            }
        }

        return $expired;
    }

    public function forget(int $fd, string $channel): void
    {
        $grant = $this->grants[$fd][$channel] ?? null;

        if ($grant === null) {
            return;
        }

        foreach ($grant->tags as $tag) {
            unset($this->byTag[$tag][$fd][$channel]);

            if (($this->byTag[$tag][$fd] ?? null) === []) {
                unset($this->byTag[$tag][$fd]);
            }

            if (($this->byTag[$tag] ?? null) === []) {
                unset($this->byTag[$tag]);
            }
        }

        unset($this->grants[$fd][$channel]);

        if (($this->grants[$fd] ?? null) === []) {
            unset($this->grants[$fd]);
        }
    }

    /** Drop everything held for a closing connection. */
    public function forgetConnection(int $fd): void
    {
        foreach (array_keys($this->grants[$fd] ?? []) as $channel) {
            $this->forget($fd, (string) $channel);
        }

        unset($this->grants[$fd]);
    }
}
