<?php

namespace Lightspeed\Auth;

use Lightspeed\Channels\Subscriptions;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\Timer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The one thing that ever judges a grant nobody is using.
 *
 * A timer rather than a hook on anything, because the connections this is for
 * are the ones that do nothing. Every other path that judges a grant is
 * client-initiated (a subscribe, or a message), so a connection that only
 * LISTENS was never judged at all: `Grant::hasExpired()` had two callers and
 * both of them were on paths a silent client never takes. A lurker with a
 * 3-second grant was proven still receiving broadcasts at t=8s on one server,
 * and 22 seconds past expiry on a two-worker one.
 *
 * That absence was worse than itself. Both the docs and this package describe
 * expiry as the BACKSTOP that makes a lost relay control entry a delay rather
 * than a hole, and with nothing evaluating expiry, the relay was the only
 * receive-side enforcement there was, so losing one entry was permanent.
 *
 * The same tick finishes the revocation backlog (Auth\RevocationDrops), under
 * ONE shared budget. See runGrantSweep().
 */
class GrantSweeper
{
    /**
     * The Swoole timer sweeping expired grants in this worker, or null.
     *
     * Held so worker stop can clear it. A timer that outlives the worker it was
     * armed in would run against a server this object no longer holds.
     */
    private ?int $grantSweepTimerId = null;

    public function __construct(
        private readonly ConnectionGrants $connectionGrants,
        private readonly RevocationDrops $revocationDrops,
        private readonly Subscriptions $subscriptions,
        private readonly AttachedServer $attachedServer,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    public function bootGrantSweeper(SwooleServer $server): void
    {
        $this->attachedServer->attach($server);

        // A worker restart runs this boot path again in the same process, so an
        // existing timer is replaced rather than joined by a second one.
        $this->shutdownGrantSweeper();

        $this->grantSweepTimerId = Timer::tick($this->grantSweepIntervalMs(), function (): void {
            $this->runGrantSweep();
        });
    }

    /**
     * One tick of the grant sweeper: finish the revocations, then the expiries.
     *
     * ONE budget between the two, not one each. The budget exists because the
     * unit of work is a blocking Redis round trip on an event loop with no
     * coroutine to yield to, and a tick that could spend `sweep_max_per_tick`
     * twice would be a ceiling that does not bound the tick, which is the whole
     * thing the ceiling is for.
     *
     * Revocations go first because they are the more urgent refusal: an expired
     * grant stopped being usable on its own schedule, while a revoked one is
     * still inside its lifetime and only this pass takes it out of the fan-out.
     *
     * BUT EXPIRY GETS A GUARANTEED SHARE, NOT THE LEFTOVERS. This used to hand
     * the whole budget to the revocation drain and run expiry only `if
     * ($budget > 0)`. A backlog at or above `sweep_max_per_tick` therefore
     * consumed every tick whole and expiry was skipped outright, for as long as
     * the backlog lasted: revoking one tag with 10k connections is about 50
     * seconds of that at the defaults, and sustained revocation traffic never
     * stops. Expiry is the BACKSTOP that makes a lost relay control entry a
     * delay rather than a hole, because it is the one refusal that needs
     * neither the relay nor a frame from the client. Letting the relay being
     * busy switch it off is exactly backwards.
     *
     * Half each, and each inherits what the other did not spend. So the common
     * case (nothing to revoke) still gives expiry the entire budget, a
     * revocation storm still gets half a tick's progress, and the total is
     * still bounded by `sweep_max_per_tick`.
     *
     * The `max(1, ...)` floors matter only at a budget of 1, where no split can
     * give both jobs a share; there the tick spends 2 rather than 1, which is
     * one extra round trip at the degenerate minimum and is preferred to
     * starving one job completely.
     */
    public function runGrantSweep(): void
    {
        $budget = self::sweepBudget();

        $reservedForExpiry = max(1, intdiv($budget, 2));

        $spent = $this->revocationDrops->drainPendingRevocations(max(1, $budget - $reservedForExpiry));

        $this->sweepExpiredGrants(max(1, $budget - $spent));
    }

    public function shutdownGrantSweeper(): void
    {
        if ($this->grantSweepTimerId !== null) {
            Timer::clear($this->grantSweepTimerId);
            $this->grantSweepTimerId = null;
        }
    }

    /**
     * Drop every subscription in this worker whose grant has run out.
     *
     * Unsubscribing rather than refusing, for the same reason revocation
     * unsubscribes: a fan-out never consults a grant, so the only way to stop a
     * connection RECEIVING is to take it out of the subscriber list. The socket
     * stays open (it may hold other channels whose grants are fine), and the
     * stale frame tells the client to authorize again, which mints a fresh
     * grant if the application still says yes.
     *
     * NOTHING HERE MAY THROW. This runs inside a Swoole timer callback, where
     * an escaping exception takes the worker's tick with it, and it runs with
     * `enable_coroutine` defaulting to FALSE, so there is no coroutine to
     * contain a failure either. Each connection is therefore swept inside its
     * own try, so one dead socket cannot cost the others their sweep, and the
     * whole pass is wrapped again in case the scan itself fails.
     *
     * THE DECISION MAKES NO REDIS CALL; THE CLEANUP CAN. Expiry is a comparison
     * against the local clock, which is why the sweep still runs during the
     * outage that makes revocation unreadable. But dropping a PRESENCE
     * subscription goes through Presence\PresenceStore::leave(), which is a
     * Redis eval, because leaving a member in the room is not an acceptable
     * alternative. This docblock used to claim the whole sweep made no Redis
     * call, and the claim being false was load-bearing in two ways:
     *
     *   the stale frame is now pushed even when the cleanup FAILED. It used to
     *   sit after the drop inside one try, so a presence store that could not
     *   reach Redis threw, the throw was swallowed, and the connection left
     *   this worker's fan-out without the client ever being told to
     *   re-authorize. It did not reconnect, it did not re-subscribe, it just
     *   went quiet: the worst possible shape for a failure, because nothing
     *   at either end reports it.
     *
     *   the pass is BOUNDED (sweepBudget). A mass expiry would otherwise make
     *   one blocking round trip per presence subscription inside a single tick,
     *   with coroutines off, which is the event loop stopped for as long as
     *   that takes. Same reasoning as the bounded waits in 6df6b9c: what is
     *   left over is swept on the next tick, and a grant that is still expired
     *   still refuses every message it sends in the meantime.
     *
     * @return int how many subscriptions were dropped
     */
    public function sweepExpiredGrants(?int $budget = null): int
    {
        $server = $this->attachedServer->get();

        if ($server === null) {
            return 0;
        }

        $swept = 0;

        try {
            $expired = $this->connectionGrants->expiredBefore((int) (microtime(true) * 1_000_000));
            $budget = $budget ?? self::sweepBudget();
            $seen = 0;

            foreach ($expired as [$fd, $channel]) {
                if ($seen++ >= $budget) {
                    $this->runtimeLogger->logWebsocket('grant-sweep-deferred', [
                        'remaining' => count($expired) - $seen + 1,
                    ]);
                    break;
                }

                try {
                    $this->subscriptions->dropSubscription($fd, $channel);
                    $swept++;
                } catch (\Throwable $e) {
                    $this->runtimeLogger->logWebsocket('error', [
                        'fd' => $fd,
                        'channel' => $channel,
                        'reason' => 'grant-sweep-failed',
                        'message' => $e->getMessage(),
                    ]);
                }

                // Separately, and unconditionally. Whether or not the shared
                // state could be cleaned up, this worker is done fanning out to
                // that subscription, and a client that is not told to
                // re-authorize has no way to discover that.
                try {
                    if ($server->isEstablished($fd)) {
                        $this->delivery->pushStale($server, $fd, $channel, 'expired');
                    }
                } catch (\Throwable $e) {
                    $this->runtimeLogger->logWebsocket('error', [
                        'fd' => $fd,
                        'channel' => $channel,
                        'reason' => 'grant-sweep-notify-failed',
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->runtimeLogger->logWebsocket('error', [
                'reason' => 'grant-sweep-failed',
                'message' => $e->getMessage(),
            ]);
        }

        return $swept;
    }

    /**
     * How many subscriptions one sweep will unwind before leaving the rest to
     * the next tick.
     *
     * The unit of cost is a PRESENCE subscription, whose cleanup is a blocking
     * Redis round trip on an event loop that has no coroutine to yield to. The
     * default is a compromise between clearing a normal backlog in one pass and
     * never handing the loop a bill it did not agree to.
     *
     * Static, and read from here by Auth\RevocationDrops too: an immediate
     * revocation pass and the deferred one that finishes it are bounded by the
     * same number, and a second copy of it is how the two would drift.
     */
    public static function sweepBudget(): int
    {
        return max(1, (int) config('lightspeed.auth.sweep_max_per_tick', 200));
    }

    /**
     * How often this worker looks for grants that have run out.
     *
     * The floor is not politeness: a sweep walks every subscription this worker
     * holds, and a misconfigured interval of a millisecond would spend the
     * worker's tick on a scan that can only find something once per grant
     * lifetime.
     */
    private function grantSweepIntervalMs(): int
    {
        return max(100, (int) config('lightspeed.auth.sweep_interval_ms', 1000));
    }
}
