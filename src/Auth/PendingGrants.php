<?php

namespace Lightspeed\Auth;

/**
 * The one pending grant belonging to the channel-auth request currently
 * running, held for the few statements between `Lightspeed::tag()` and the
 * broadcaster signing it.
 *
 * This class exists because of a specific bug in the reverted build. The grant
 * manager was a container singleton and kept `?PendingGrant $pending` on
 * itself. Under Octane that object outlives a request, and under the supported
 * `enable_coroutine=true` two /broadcasting/auth requests overlap inside one
 * worker: request A calls tag(), yields on its database query, request B calls
 * tag() and overwrites the slot, and A then signs B's permissions onto A's
 * socket. Silent, and in the direction that grants access.
 *
 * So the slot is per REQUEST, and a request is identified by where it SAYS it
 * begins rather than by anything inferred about the shape of the coroutine
 * tree. begin() (which the broadcaster calls once, at the top of every channel
 * authorization) marks the coroutine it runs in, and everything the request
 * does afterwards resolves to the nearest marked ancestor.
 *
 * PER REQUEST, NOT PER COROUTINE, and that is a bug fixed rather than a
 * distinction drawn. Keying on Swoole\Coroutine::getCid() meant a channel
 * callback that did its work in a NESTED coroutine (a concurrent permission
 * lookup, a WaitGroup, an HTTP call) wrote the grant into the CHILD's slot.
 * The broadcaster then ran in the parent, found nothing, and signed an
 * UNGRANTED auth string for a channel the application had explicitly asked to
 * protect. An auth string with no grant is the untagged form, so nothing about
 * it is ever revocable. Silent, and in the direction that grants access.
 *
 * WALKING TO THE TOP-LEVEL COROUTINE WAS ALSO WRONG, and this is the second
 * round of the same bug. The fix for the nested case walked getPcid() all the
 * way up and asserted that no other request shares the coroutine it lands on.
 * That is true of THIS server, which starts one top-level coroutine per
 * request, and false of every runtime that owns its own event loop and runs
 * requests as children of a container coroutine: Swoole\Coroutine\Http\Server,
 * Hyperf, anything inside a Co\run() wrapper, including this package's own
 * test for the nested case. There, every concurrent request walks to the SAME
 * top-level coroutine, and two overlapping authorizations share one slot again.
 * Reproduced: request A signed request B's tags.
 *
 * The marker fixes both at once, because it stops guessing. A nested coroutine
 * finds its parent's marker; a sibling request under a shared container finds
 * its own, because it planted one. What the walk is for is only to cross the
 * coroutines a request creates for itself, and it stops at the first marker
 * rather than at the top of the world.
 *
 * Slots are not reference counted or swept, because they do not need to be:
 * begin() clears this request's slot before every auth attempt, and take()
 * removes it. A recycled coroutine id therefore never sees the previous
 * occupant's grant. The map holds at most one entry per in-flight auth request.
 *
 * What this still cannot rescue is a channel callback that starts a coroutine
 * and returns WITHOUT waiting for it: the grant is then described after Laravel
 * has already signed the response, and no keying fixes an ordering the
 * application chose. tag() is a statement about the request that is running, so
 * it has to run before that request answers.
 */
final class PendingGrants
{
    /**
     * The key begin() plants in a coroutine's context to say "a channel-auth
     * request starts here".
     */
    private const CONTEXT_MARKER = 'lightspeed.pending_grant_scope';

    /** @var array<int, PendingGrant> request scope => the grant being described */
    private array $slots = [];

    /**
     * Start one channel-auth attempt, discarding anything left in this slot.
     *
     * The leftover is real: a `Broadcast::channel()` callback that calls tag()
     * and then returns false, or throws, leaves a pending grant behind. Clearing
     * on entry rather than trusting it to have been consumed is what stops the
     * NEXT authorization on this coroutine from inheriting it.
     */
    public function begin(): void
    {
        $this->markCurrentCoroutineAsRequest();

        unset($this->slots[$this->scope()]);
    }

    public function put(PendingGrant $grant): void
    {
        $this->slots[$this->scope()] = $grant;
    }

    /** Take this scope's pending grant, if the application described one. */
    public function take(): ?PendingGrant
    {
        $scope = $this->scope();

        $grant = $this->slots[$scope] ?? null;
        unset($this->slots[$scope]);

        return $grant;
    }

    /**
     * Say that the coroutine running right now IS a channel-auth request.
     *
     * Stored in Swoole's own per-coroutine context, which is destroyed with the
     * coroutine, so nothing here has to be cleaned up and a recycled coroutine
     * id can never carry a stale mark.
     *
     * There is nothing to do when no coroutine is running: the process is
     * sequential, so one request runs to completion before the next starts and
     * the single slot (keyed -1) cannot be crossed.
     */
    private function markCurrentCoroutineAsRequest(): void
    {
        // getContext() arrived in Swoole 4.3. Without it there is no mark to
        // plant, and scope() falls back to the walk this replaces: worse, but
        // no worse than it already was on such a build.
        if (!class_exists(\Swoole\Coroutine::class) || !method_exists(\Swoole\Coroutine::class, 'getContext')) {
            return;
        }

        $cid = \Swoole\Coroutine::getCid();

        if (!is_int($cid) || $cid < 0) {
            return;
        }

        $context = \Swoole\Coroutine::getContext($cid);

        if ($context !== null) {
            $context[self::CONTEXT_MARKER] = true;
        }
    }

    /**
     * The identity of the request currently running.
     *
     * The nearest ancestor coroutine that called begin(), found by walking the
     * parent chain and STOPPING AT THE FIRST MARK. A coroutine the request
     * created for itself answers the same key as the request, because the walk
     * passes through it; a sibling request under a shared container coroutine
     * answers its own, because the walk stops before reaching the container.
     *
     * Walking to the top of the chain instead (which is what this did) is
     * only correct on a runtime that gives every request its own top-level
     * coroutine. Under any container-per-event-loop runtime it collapses every
     * concurrent request onto one key, which is the cross-request leak this
     * class exists to prevent. See the class docblock.
     *
     * Swoole returns -1 (and getPcid() returns false) when no coroutine is
     * active. -1 is a perfectly good key: it means the process is running
     * sequentially, so one slot is one request by construction.
     *
     * NO MARK ANYWHERE UP THE CHAIN means tag() was called outside a channel
     * authorization: nothing the broadcaster will ever collect, since it calls
     * begin() itself before the application's callback runs. The old walk is
     * kept for that case, because it is the behaviour that was there before and
     * because the alternative (this coroutine, unshared) would silently break
     * the nested-coroutine case for anyone driving these classes directly.
     */
    private function scope(): int
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return -1;
        }

        $cid = \Swoole\Coroutine::getCid();

        if (!is_int($cid) || $cid < 0) {
            return -1;
        }

        // The chain is a handful of frames deep at worst, and it is walked
        // twice per channel authorization rather than per message.
        $walked = $cid;
        $marks = method_exists(\Swoole\Coroutine::class, 'getContext');

        while (true) {
            $context = $marks ? \Swoole\Coroutine::getContext($walked) : null;

            if ($context !== null && isset($context[self::CONTEXT_MARKER])) {
                return $walked;
            }

            $parent = \Swoole\Coroutine::getPcid($walked);

            if (!is_int($parent) || $parent < 0) {
                return $walked;
            }

            $walked = $parent;
        }
    }
}
