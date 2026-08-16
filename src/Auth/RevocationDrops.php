<?php

namespace Lightspeed\Auth;

use Lightspeed\Channels\Subscriptions;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Take revoked connections out of the fan-out, on this worker.
 *
 * The RECEIVING half of revocation. Nothing about whether a message is ALLOWED
 * is decided here: that is Auth\GrantCheck, which asks Redis on every frame.
 * This is what stops a revoked connection RECEIVING, because a fan-out never
 * consults a grant, so the only way to stop one is to take it off the channel.
 *
 * Everything here is therefore an optimization over the backstop. Lose every
 * entry and the revoked user still cannot send, and is still dropped when the
 * grant expires, by Auth\GrantSweeper's timer. What is left over by a bounded
 * pass is DEFERRED to that same sweeper rather than dropped.
 */
class RevocationDrops
{
    /**
     * Revocations this worker still has connections to unwind for.
     *
     * tag => the latest instant it was revoked at. Filled when a revoke finds
     * more connections carrying a tag than one pass is allowed to unwind, and
     * drained by the grant sweeper's ticks. See dropConnectionsCarrying().
     *
     * @var array<array-key, int>
     */
    private array $pendingRevocations = [];

    public function __construct(
        private readonly ConnectionGrants $connectionGrants,
        private readonly RevocationLog $revocations,
        private readonly RedisRelay $relay,
        private readonly Subscriptions $subscriptions,
        private readonly AttachedServer $attachedServer,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * Wire this worker up to hear about revocations, from itself and its peers.
     *
     * Two sources, one effect, because the relay deliberately never replays a
     * process's own publishes back to it: a `Lightspeed::revoke()` served by
     * THIS process is applied through the direct listener, and one served by
     * any other process arrives over the relay's control channel.
     *
     * Everything registered here is an optimization. It is what makes
     * revocation immediate in the RECEIVING direction: a fan-out never
     * consults a grant, so the only way to stop a revoked user receiving is to
     * take the connection out of the subscriber list, but nothing about
     * whether a message is ALLOWED is decided by state that arrived this way.
     * Lose every entry and the revoked user still cannot send, because
     * GrantCheck::grantRefusal() asks Redis, and is still dropped when the
     * grant expires, by GrantSweeper's timer, which is what makes that second
     * half a fact rather than a claim. Before it existed nothing evaluated
     * expiry for a connection that never sent anything, so a lost entry was
     * permanent for a listener.
     */
    public function bootRevocationListeners(SwooleServer $server): void
    {
        $this->attachedServer->attach($server);

        $this->revocations->onRevoked('server', function (string $tag, int $revokedAt): void {
            $this->dropConnectionsCarrying($tag, $revokedAt);
        });

        $this->relay->onControl(RevocationLog::CONTROL_TYPE, function (array $payload): void {
            $tag = $payload['tag'] ?? null;
            $revokedAt = $payload['revoked_at'] ?? null;

            // Cast, do not guard. The tag "7" makes the round trip through JSON
            // as a string here because it is a value rather than an object key,
            // but the reverted build's `is_string()` guard on the equivalent
            // path silently dropped every numeric tag and made revoke a
            // permanent no-op for them. Found on a live server.
            if (is_string($tag) || is_int($tag)) {
                $tag = (string) $tag;

                if ($tag !== '' && is_numeric($revokedAt)) {
                    $this->dropConnectionsCarrying($tag, (int) $revokedAt);
                }
            }
        });
    }

    /**
     * Unsubscribe every local connection whose grant carries this tag.
     *
     * Unsubscribing rather than refusing is what makes revocation work in the
     * receiving direction: once a connection is off the channel it is not in
     * the fan-out list, so nothing broadcast there reaches it. The socket
     * itself stays open, because it may hold other channels that have nothing
     * to do with this tag.
     *
     * Only grants issued before the revocation are dropped. A connection that
     * re-subscribed after it holds a grant the application has just approved
     * again, and dropping that would turn one revoke into an endless
     * subscribe-and-drop loop for a user whose access is perfectly fine.
     *
     * Guarded exactly like GrantSweeper::sweepExpiredGrants(), and for the same
     * two reasons. This runs from a relay timer body and from inside
     * `Lightspeed::revoke()`, and dropping a presence subscription is a Redis
     * call that can fail: one connection whose cleanup throws must not cost
     * every connection after it in the list its drop, and the client must be
     * told to re-authorize whether or not the shared state could be tidied up.
     *
     * AND BOUNDED, for the third reason the sweeper is: the unit of work here
     * is the same PresenceStore::leave() eval, on the same event loop, with the
     * same `enable_coroutine` off. Revoking one popular tag was ten thousand
     * serial round trips inside one relay tick (measured at 1.40s of fully
     * blocked loop on loopback, 3-10s over a real network), and `worker_num`
     * defaults to 1, so that is the whole server answering nothing. The budget
     * was added to the expiry sweep and not to this, which has the identical
     * shape and a trigger an application reaches on purpose.
     *
     * What is left over is DEFERRED, not dropped: the remainder is handed to the
     * grant sweeper, which finishes it on its own ticks (runGrantSweep). In the
     * meantime those connections are refused on every message they send by the
     * authoritative per-message read, which is exactly the guarantee a lost
     * relay entry already leans on: a delay bounded by the sweep interval,
     * never a hole.
     */
    public function dropConnectionsCarrying(string $tag, int $revokedAt): void
    {
        $server = $this->attachedServer->get();

        if ($server === null) {
            return;
        }

        $carrying = $this->connectionGrants->connectionsCarrying($tag, $revokedAt);
        $budget = GrantSweeper::sweepBudget();

        if (count($carrying) > $budget) {
            $this->deferRevocation($tag, $revokedAt);

            $this->runtimeLogger->logWebsocket('revocation-drop-deferred', [
                'tag' => $tag,
                'remaining' => count($carrying) - $budget,
            ]);

            $carrying = array_slice($carrying, 0, $budget);
        }

        foreach ($carrying as [$fd, $channel]) {
            $this->dropRevokedSubscription($fd, $channel);
        }
    }

    /**
     * Take one revoked subscription out of the fan-out and tell its client.
     *
     * Shared by the immediate pass and the deferred one so the two cannot drift.
     * A connection dropped a tick later must be dropped in exactly the same
     * way, including being told.
     *
     * The two halves are separate tries on purpose. Dropping a PRESENCE
     * subscription is a Redis call that can fail, and the stale frame is what
     * makes the client re-authorize: a connection removed from this worker's
     * fan-out whose client was never told does not reconnect, does not
     * re-subscribe, and reports nothing at either end.
     */
    public function dropRevokedSubscription(int $fd, string $channel): void
    {
        $server = $this->attachedServer->get();

        if ($server === null) {
            return;
        }

        try {
            $this->subscriptions->dropSubscription($fd, $channel);
        } catch (\Throwable $e) {
            $this->runtimeLogger->logWebsocket('error', [
                'fd' => $fd,
                'channel' => $channel,
                'reason' => 'revocation-drop-failed',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            if ($server->isEstablished($fd)) {
                $this->delivery->pushStale($server, $fd, $channel, 'revoked');
            }
        } catch (\Throwable $e) {
            $this->runtimeLogger->logWebsocket('error', [
                'fd' => $fd,
                'channel' => $channel,
                'reason' => 'revocation-notify-failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remember that a revocation still has connections to unwind on this worker.
     *
     * Keyed by tag, holding the LATEST instant revoked for it: a second revoke
     * of the same tag covers every grant the first one did, so one entry per tag
     * is the whole backlog however many times it is revoked. Nothing here is a
     * queue of connections: connectionsCarrying() is an in-memory index that
     * shrinks as the drops land, so re-asking it is what "the remainder" means
     * and there is no second copy of the truth to keep true.
     */
    public function deferRevocation(string $tag, int $revokedAt): void
    {
        $this->pendingRevocations[$tag] = max($revokedAt, $this->pendingRevocations[$tag] ?? 0);
    }

    /**
     * Finish the revocations a previous pass ran out of budget for.
     *
     * A tag is forgotten only when a pass drops fewer than it was allowed to,
     * which is the one observation that means the index had nothing left rather
     * than the budget running out. Forgetting on "dropped zero" alone would
     * strand a tag whose remainder is an exact multiple of the budget.
     *
     * @return int how many drops this spent, so the caller's tick stays bounded
     */
    public function drainPendingRevocations(int $budget): int
    {
        $spent = 0;

        foreach ($this->pendingRevocations as $tag => $revokedAt) {
            if ($spent >= $budget) {
                break;
            }

            $allowed = $budget - $spent;
            $carrying = array_slice(
                $this->connectionGrants->connectionsCarrying((string) $tag, $revokedAt),
                0,
                $allowed,
            );

            foreach ($carrying as [$fd, $channel]) {
                $spent++;
                $this->dropRevokedSubscription($fd, $channel);
            }

            if (count($carrying) < $allowed) {
                unset($this->pendingRevocations[$tag]);
            }
        }

        return $spent;
    }
}
