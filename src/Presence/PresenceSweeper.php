<?php

namespace Lightspeed\Presence;

use Lightspeed\Channels\ChannelManager;
use Lightspeed\Connections\ConnectionClosed;
use Lightspeed\Connections\ConnectionClosedDispatcher;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\Timer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Vouch for this worker's presence memberships, and unwind the ones nobody is
 * vouching for any more.
 *
 * A second timer rather than more work on the grant one: they run at very
 * different cadences (a grant expires in seconds, a presence marker in a
 * minute) and reconciling presence at the grant sweep's rate would be Redis
 * load for nothing.
 */
class PresenceSweeper
{
    /**
     * The Swoole timer reconciling presence state in this worker, or null.
     *
     * Cleared at worker stop for the same reason the grant sweeper's is: a
     * timer that outlives the worker it was armed in would run against a server
     * this object no longer holds.
     */
    private ?int $presenceSweepTimerId = null;

    /**
     * The presence channel the next sweep starts from, or null for the first.
     *
     * A bounded sweep that always started at the beginning would visit the same
     * few channels forever and never reach the rest, which is worse than no
     * bound at all: a starved channel's ghost members are permanent. See
     * sweepPresence().
     */
    private ?string $presenceSweepResumeFrom = null;

    /**
     * Reaped members whose notification did not fit in the tick that reaped
     * them, oldest first.
     *
     * The channel budget bounds CHANNELS, and one channel can hold any number
     * of abandoned members: a worker OOM-killed while holding a large presence
     * channel leaves every one of its members for a single reapAbandoned() call
     * to return at once. Notifying all of them would run application code that
     * many times, serially, inside one timer callback on the event loop that
     * serves every connection this worker still has.
     *
     * The reap itself is NOT deferred and must not be: it is one atomic Redis
     * script per channel, and the rows it removes are ghosts either way. What is
     * deferred is only the telling, at sweepNotificationBudget() per tick, and
     * nothing is dropped. The queue drains at that rate and only ever grows from
     * real reaps, so it is bounded by the size of the failure that produced it.
     *
     * @var list<array{0: string, 1: string}> channel, user id
     */
    private array $pendingNotifications = [];

    /**
     * Whether an application worker was still there to take this tick's
     * notifications.
     *
     * Reset at the top of every tick, and cleared the first time a dispatch
     * comes back undelivered. One failure answers for every member behind it,
     * because the reason is never about the member: there is no booted
     * application worker to run handlers in. Without it, a tick with a large
     * backlog and no worker walks every entry into the same wall and writes a
     * log line for each.
     */
    private bool $notificationsDeliverable = true;

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly PresenceStore $presenceStore,
        private readonly ConnectionId $connectionId,
        private readonly AttachedServer $attachedServer,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
        private readonly ConnectionClosedDispatcher $connectionClosed,
    ) {
    }

    /**
     * Start this worker's presence sweeper.
     *
     * Follows GrantSweeper::bootGrantSweeper() exactly, including replacing
     * rather than joining an existing timer when a worker restarts in the same
     * process.
     */
    public function bootPresenceSweeper(SwooleServer $server): void
    {
        $this->attachedServer->attach($server);

        $this->shutdownPresenceSweeper();

        $this->presenceSweepTimerId = Timer::tick($this->presenceSweepIntervalMs(), function (): void {
            $this->sweepPresence();
        });
    }

    public function shutdownPresenceSweeper(): void
    {
        if ($this->presenceSweepTimerId !== null) {
            Timer::clear($this->presenceSweepTimerId);
            $this->presenceSweepTimerId = null;
        }

        // The timer that would have delivered these is gone, so they are gone
        // too, and a backlog that vanishes without a line is the failure this
        // whole hook documents itself against being. The members were reaped;
        // what is lost is the telling, which is what an operator has to know to
        // decide whether a TTL covered it.
        $this->discardPendingNotifications('presence-sweeper-shutdown');
    }

    /**
     * One reconciliation pass.
     *
     * The close handler is the ONLY thing that ever called leave(), which made
     * every presence row conditional on a close arriving. SIGKILL, an OOM kill,
     * a segfault and a container stop that reaps the process all skip it. The
     * rows are reference counts, so what survives is not a stale entry that the
     * next join overwrites. It is a permanent phantom member of the channel
     * with no decrement left anywhere that could remove it.
     *
     * This is the half of the answer that only a worker can give. Redis cannot
     * distinguish a row belonging to a live connection from one whose process
     * is gone; the worker still holding the socket can, and says so by
     * refreshing that connection's liveness marker. A worker that stops saying
     * it is precisely the worker that was killed, and its rows are then reaped
     * by whichever worker sweeps the channel next.
     *
     * The order matters and is not interchangeable: the marker pass FIRST, over
     * EVERY channel this worker holds, so every membership it can see is
     * vouched for before anything looks for unvouched ones. Reaping first would
     * race a marker that lapsed a millisecond ago against the refresh that was
     * about to renew it, and reap a live member.
     *
     * AND EACH CHANNEL'S OWN MARKERS AGAIN, IMMEDIATELY BEFORE ITS REAP. The
     * early pass is what covers the channels this tick will not reach, and it
     * is the fix for the bug below; it is not, on its own, a safe vouch for the
     * channels this tick DOES reach. Everything between it and a given reap
     * happens on this thread: up to a full budget of application handlers in
     * the drain, and every earlier channel's reap, each an HKEYS plus an EXISTS
     * per member. On a slow tick that distance is measured in seconds, and a
     * vouch that is seconds old against a TTL of a minute is a vouch racing the
     * same clock the bug was about. So the reap is never more than one round
     * trip from the write that earned it.
     *
     * THE MARKER PASS IS NOT BUDGETED, AND THE REST OF THE TICK IS. That split
     * is the whole shape of this method. A marker is racing a wall-clock TTL:
     * refreshing one only on the tick that sweeps its channel gave a worker
     * holding more channels than one tick's budget a revisit period of
     * ceil(channels / budget) ticks, and past the point where that overtakes
     * the TTL (600 channels at the shipped 10s, 100 a tick and 60s), the markers
     * of CONNECTED members lapsed between visits. Another worker then reaped
     * live members: member_removed to browsers that still have the user on the
     * channel, and a 'swept' ConnectionClosed telling the application to release
     * state a connected user still holds. So the markers of every channel are
     * rewritten every tick, chunked into pipelines, and only the channel-key
     * EXPIREs (an hour, reached only by a channel nobody is on) and the reap
     * itself (the expensive half, and a ghost found one rotation late is still
     * found) stay on the rotation.
     *
     * NOTHING HERE MAY THROW, for the same reason GrantSweeper's sweep may not:
     * this is a Swoole timer callback, an escaping exception is fatal to the
     * worker, and `enable_coroutine` defaults to FALSE so there is no coroutine
     * to contain it. Each marker chunk and each channel is written inside its
     * own try, so one unreachable chunk or channel cannot cost the others
     * their sweep, and the whole body sits inside one more.
     *
     * @return int how many members were reaped
     */
    public function sweepPresence(): int
    {
        $server = $this->attachedServer->get();

        if ($server === null) {
            return 0;
        }

        $reaped = 0;

        // Read once per tick rather than per member: an application that
        // registers no handler queues nothing and pays nothing, and one that
        // does gets a fixed ceiling on how much of this tick belongs to it.
        $notifying = $this->hasClosedHandlers();
        $notifications = $this->sweepNotificationBudget();
        $this->notificationsDeliverable = true;

        try {
            // Read ONCE per tick and handed to both halves. It is built by
            // walking every presence channel this worker holds and copying
            // every fd list, so at ten thousand channels it is milliseconds and
            // megabytes; reading it again for the slice would pay that twice
            // for an answer that cannot have changed in between, there being no
            // coroutine to change it.
            $subscriptions = $this->channels->presenceSubscriptions();

            // Before the drain as well as before the reaps: the drain runs
            // application handlers inline, and a marker written after them has
            // spent that time lapsing.
            $vouched = $this->heartbeatMarkers($subscriptions);

            if ($notifying) {
                // Before this tick's own reaps, because these members left
                // first and their handlers are the ones already running late.
                $notifications = $this->drainPendingNotifications($notifications);
            } else {
                // The list was emptied while a backlog was still queued. Held
                // entries would be delivered by whichever tick came after a
                // handler was registered again, which could be hours later and
                // about connections nothing remembers: an application would act
                // on a departure that has long since been covered by a TTL.
                // Dropped, once, out loud.
                $this->discardPendingNotifications('connection-closed-handlers-removed');
            }

            foreach ($this->presenceSweepSlice($subscriptions) as $channel => $fds) {
                // The marker pass is a PRECONDITION of the reap, not merely the
                // step before it. Reaping asks "which rows is nobody vouching
                // for", and this worker's own members are unvouched-for until
                // their markers are written, so a reap that ran after a failed
                // write would remove the live members of the very worker doing
                // the reaping. Found by the test that asserts a live member
                // survives a sweep, against a heartbeat that was throwing.
                //
                // Hence the skip: a channel this worker could not vouch for is
                // a channel it has not earned the right to reap. The rows are
                // safe either way: a real ghost is still there next tick. The
                // failure was already logged, once for the pass rather than
                // once per channel behind the same unreachable Redis.
                if (($vouched[$channel] ?? false) !== true) {
                    continue;
                }

                try {
                    // The adjacency the reap below is entitled to: this
                    // channel's markers, written now rather than however many
                    // handlers and reaps ago the early pass ran. One pipeline,
                    // and at most one per budgeted channel per tick.
                    $this->presenceStore->heartbeatConnections([
                        $channel => $this->presenceConnectionIds($fds),
                    ]);

                    $this->presenceStore->heartbeatChannel($channel);
                } catch (\Throwable $e) {
                    $this->runtimeLogger->logWebsocket('error', [
                        'channel' => $channel,
                        'reason' => 'presence-heartbeat-failed',
                        'message' => $e->getMessage(),
                    ]);

                    // Skipped rather than reaped anyway. For the marker write
                    // that is the precondition again; for the channel EXPIRE,
                    // which only guards the hour-long TTL, it is that a channel
                    // whose EXPIRE just failed is a channel Redis is
                    // unreachable for, and the reap behind it is several more
                    // blocking calls into the same outage.
                    continue;
                }

                try {
                    foreach ($this->presenceStore->reapAbandoned($channel) as $userId) {
                        $reaped++;

                        // Told to the channel, exactly as an orderly close
                        // would have: a member removed from the snapshot with
                        // no member_removed leaves every client painting a
                        // collaborator who is not there until it reloads.
                        $this->delivery->broadcastPusherEvent($channel, 'pusher_internal:member_removed', [
                            'user_id' => $userId,
                        ]);

                        // And told to the application, exactly as an orderly
                        // close tells it, because for a connection whose worker
                        // was killed this is the only telling there will ever
                        // be. Thin by necessity: see Connections\ConnectionClosed.
                        //
                        // Past the tick's budget the telling waits; the reap
                        // above has already happened and is not undone.
                        //
                        // And an undeliverable one waits too, for the same
                        // reason the drain puts one back: "there is no booted
                        // worker" is true for every member behind it as well.
                        if ($notifying && $this->notificationsDeliverable && $notifications > 0) {
                            if ($this->notifyConnectionClosed($channel, $userId)) {
                                $notifications--;
                            } else {
                                $this->pendingNotifications[] = [$channel, $userId];
                                $this->notificationsDeliverable = false;
                            }
                        } elseif ($notifying) {
                            $this->pendingNotifications[] = [$channel, $userId];
                        }
                    }
                } catch (\Throwable $e) {
                    $this->runtimeLogger->logWebsocket('error', [
                        'channel' => $channel,
                        'reason' => 'presence-sweep-failed',
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->runtimeLogger->logWebsocket('error', [
                'reason' => 'presence-sweep-failed',
                'message' => $e->getMessage(),
            ]);
        }

        return $reaped;
    }

    /**
     * Rewrite the liveness marker of every presence connection this worker
     * holds, on every channel it holds, before anything reaps anything.
     *
     * EVERY channel, every tick, with no rotating budget, which is where this
     * parts company with the reap below it and follows the connection sweep
     * instead: a channel whose reap waits a few ticks keeps a ghost a little
     * longer, while a channel whose markers wait a few ticks too many loses
     * LIVE members. Skipping is not a delay here, it is the bug.
     *
     * What makes that affordable is the unit: a pipelined SETEX rather than a
     * round trip, so the pass costs connections/chunk round trips rather than
     * one each. The chunk size bounds how much of it is in flight at once, so a
     * worker holding a pathological number of channels does not hand Redis a
     * single write it has to buffer whole.
     *
     * A CHUNK THAT COULD NOT BE WRITTEN ENDS THE PASS, and costs every channel
     * it had not reached this tick's reap. The rule is the connection sweep's,
     * for the reason given there: the chunk after a failed one is another
     * blocking round trip into the same outage, taken on the event loop that is
     * serving every connection this worker has, and a marker outlives many
     * ticks so nothing is lost by waiting for the next one. The channels behind
     * the failure are simply never marked vouched-for, which is what stops the
     * reap from touching them, and it is said once with a count rather than
     * once per channel behind the same unreachable Redis.
     *
     * @param array<string, list<int>> $subscriptions
     * @return array<string, bool> channel => whether all of its markers were written
     */
    private function heartbeatMarkers(array $subscriptions): array
    {
        $chunkSize = $this->presenceSweepChunkSize();

        $vouched = [];
        $chunk = [];
        $chunked = 0;

        foreach ($subscriptions as $channel => $fds) {
            // Set before any write, so that a channel split across two chunks
            // is left unvouched-for by whichever of them fails.
            $vouched[$channel] = true;

            foreach ($fds as $fd) {
                $chunk[$channel][] = $this->connectionId->presenceConnectionId($fd);
                $chunked++;

                if ($chunked >= $chunkSize) {
                    if (!$this->heartbeatMarkerChunk($chunk, $vouched, $subscriptions)) {
                        return $vouched;
                    }

                    $chunk = [];
                    $chunked = 0;
                }
            }
        }

        if ($chunk !== []) {
            $this->heartbeatMarkerChunk($chunk, $vouched, $subscriptions);
        }

        return $vouched;
    }

    /**
     * Write one chunk of markers, and say whether the pass may go on.
     *
     * On failure the chunk's own channels are marked unvouched-for explicitly,
     * and every channel this pass had not reached yet is left out of the map
     * altogether, which the reap gate reads as the same "no".
     *
     * @param array<string, list<string>> $chunk
     * @param array<string, bool> $vouched
     * @param array<string, list<int>> $subscriptions every channel the pass set out to write
     */
    private function heartbeatMarkerChunk(array $chunk, array &$vouched, array $subscriptions): bool
    {
        try {
            $this->presenceStore->heartbeatConnections($chunk);

            return true;
        } catch (\Throwable $e) {
            foreach (array_keys($chunk) as $channel) {
                $vouched[$channel] = false;
            }

            $abandoned = 0;

            foreach (array_keys($subscriptions) as $channel) {
                if (($vouched[$channel] ?? false) !== true) {
                    $abandoned++;
                }
            }

            $this->runtimeLogger->logWebsocket('error', [
                'channels' => $abandoned,
                'reason' => 'presence-heartbeat-failed',
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tell the application that a reaped member is gone.
     *
     * ITS OWN try, on top of the dispatcher's own guarantee never to throw,
     * because this is the one place in the package where application code runs
     * inside a Swoole timer callback: nothing here may escape, and a second
     * guard costs a stack frame that only a dying worker would have saved.
     */
    private function notifyConnectionClosed(string $channel, string $userId): bool
    {
        try {
            if (!$this->connectionClosed->hasHandlers()) {
                return true;
            }

            return $this->connectionClosed->dispatch(ConnectionClosed::swept($channel, $userId));
        } catch (\Throwable $e) {
            $this->runtimeLogger->logWebsocket('error', [
                'channel' => $channel,
                'reason' => 'connection-closed-notify-failed',
                'message' => $e->getMessage(),
            ]);

            // A notification that got as far as the handlers and blew up there
            // is a delivered notification: retrying it would run whatever DID
            // succeed a second time.
            return true;
        }
    }

    /**
     * Drop a backlog nobody will ever be told about, and say how big it was.
     *
     * Guarded, and it clears the queue whether or not the line gets written: a
     * logger that cannot write is not a reason to keep entries that are already
     * undeliverable.
     */
    private function discardPendingNotifications(string $reason): void
    {
        $discarded = count($this->pendingNotifications);

        $this->pendingNotifications = [];

        if ($discarded === 0) {
            return;
        }

        try {
            $this->runtimeLogger->logWebsocket('error', [
                'reason' => $reason,
                'discarded' => $discarded,
                'message' => 'swept connection-closed notifications were dropped without being delivered',
            ]);
        } catch (\Throwable) {
            // Nothing left to report through, and the members this was about
            // have already been reaped.
        }
    }

    /**
     * Tell the application about the members earlier ticks did not get to.
     *
     * Oldest first, and no more than the budget left, so a backlog drains at a
     * steady rate instead of arriving as the same flood one tick later.
     *
     * AN ENTRY THAT COULD NOT BE DELIVERED GOES BACK, at the front, and ends
     * the drain for this tick. Taking it off the queue anyway would lose it for
     * the one reason guaranteed to be true for every entry behind it as well:
     * there is no booted application worker. Ending the drain there, and
     * clearing notificationsDeliverable, is what keeps that from becoming a
     * line of log per queued member, and sends this tick's own reaps to the
     * back of the queue instead of into the same wall.
     *
     * @return int the budget still unspent
     */
    private function drainPendingNotifications(int $budget): int
    {
        while ($budget > 0 && $this->pendingNotifications !== []) {
            [$channel, $userId] = array_shift($this->pendingNotifications);

            // Spent before the attempt, so that an entry which goes back on the
            // queue cannot be shifted off it again by this same loop.
            $budget--;

            if (!$this->notifyConnectionClosed($channel, $userId)) {
                array_unshift($this->pendingNotifications, [$channel, $userId]);
                $this->notificationsDeliverable = false;

                break;
            }
        }

        return $budget;
    }

    /**
     * Is any application handler listening at all?
     *
     * Its own guard on top of the dispatcher's, because the answer decides
     * whether this tick queues anything, and a throw here would be a throw on
     * the event loop. An unanswerable question is answered "no": the sweep's
     * own work is not conditional on it.
     */
    private function hasClosedHandlers(): bool
    {
        try {
            return $this->connectionClosed->hasHandlers();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * How many reaped members one sweep will tell the application about.
     *
     * The same default as the channel budget, and the same reason: application
     * code runs inline on the event loop here, so what has to be bounded is the
     * number of times one tick can enter it. Members past the ceiling are
     * notified on later ticks, not dropped.
     */
    private function sweepNotificationBudget(): int
    {
        return max(1, (int) config('lightspeed.presence.sweep_max_notifications_per_tick', 100));
    }

    /**
     * The channels this tick is allowed to sweep, resuming where the last stopped.
     *
     * The reap is the expensive half: an HKEYS and an EXISTS per connection
     * before any ghost is found, all of it blocking, on the event loop that
     * serves every connection this worker holds, with `enable_coroutine` off.
     * Unbounded, a worker holding ten thousand presence channels spent tens of
     * thousands of serial round trips inside one tick: same shape, same cost and
     * same single-worker default as the grant sweep, which had a budget while
     * this did not.
     *
     * ONLY THE REAP AND THE CHANNEL TTL ARE BOUNDED HERE. The liveness markers
     * left this budget when it turned out they were racing a wall-clock TTL
     * rather than merely waiting their turn; see heartbeatMarkers().
     *
     * ROTATION IS THE HALF THAT MAKES A BOUND SAFE. Cutting the list at a fixed
     * budget and always starting over would visit the first N channels on every
     * tick and never reach channel N+1, so its abandoned rows would never be
     * reconciled at all: a permanent phantom member, which is precisely the
     * failure this sweep exists to prevent. Resuming from the channel after the
     * last one swept gives every channel its turn; a resume point whose channel
     * has since gone simply starts the next pass at the beginning.
     *
     * Takes the subscription map the tick already read rather than reading its
     * own: the two answers cannot differ (nothing yields in between) and the
     * map is the expensive thing to build.
     *
     * @param array<string, list<int>> $subscriptions
     * @return array<string, list<int>>
     */
    private function presenceSweepSlice(array $subscriptions): array
    {
        $budget = $this->presenceSweepBudget();

        if (count($subscriptions) <= $budget) {
            $this->presenceSweepResumeFrom = null;

            return $subscriptions;
        }

        $channels = array_keys($subscriptions);
        $start = $this->presenceSweepResumeFrom === null
            ? false
            : array_search($this->presenceSweepResumeFrom, $channels, true);
        $offset = $start === false ? 0 : $start;

        // Wraps, so a resume point near the end still gets a full slice rather
        // than a short one, and the rotation stays even.
        $slice = [];
        $count = count($channels);

        for ($i = 0; $i < $budget; $i++) {
            $channel = $channels[($offset + $i) % $count];
            $slice[$channel] = $subscriptions[$channel];
        }

        $this->presenceSweepResumeFrom = $channels[($offset + $budget) % $count];

        return $slice;
    }

    /**
     * How many presence channels one sweep will reconcile before leaving the
     * rest to the next tick.
     *
     * Half the grant sweep's budget by default, because the unit here is a
     * channel-wide reap rather than one Redis round trip, so the two ceilings
     * buy the event loop the same amount of time.
     */
    private function presenceSweepBudget(): int
    {
        return max(1, (int) config('lightspeed.presence.sweep_max_channels_per_tick', 100));
    }

    /**
     * The presence connection ids of a channel's local sockets.
     *
     * The id, not the fd: it is what join() filed the row under and what the
     * marker key is built from, so a marker written under anything else vouches
     * for nothing while looking like it did.
     *
     * @param list<int> $fds
     * @return list<string>
     */
    private function presenceConnectionIds(array $fds): array
    {
        return array_map(fn (int $fd): string => $this->connectionId->presenceConnectionId($fd), $fds);
    }

    /**
     * How many liveness markers go into one pipeline.
     *
     * Not a per-tick budget: every marker this worker holds is written on every
     * tick (see heartbeatMarkers()). This bounds only how much of that pass is
     * in flight at once, which is why it is the connection sweep's dial and
     * default rather than a second reading of the channel budget.
     */
    private function presenceSweepChunkSize(): int
    {
        return max(1, (int) config('lightspeed.presence.sweep_chunk_size', 500));
    }

    /**
     * How often this worker vouches for its presence memberships.
     *
     * The floor is what keeps the relationship with the marker TTL sane: the
     * marker must outlive several ticks or a healthy worker reaps its own
     * members, and PresenceStore holds up its end by refusing a TTL shorter
     * than three of these.
     */
    private function presenceSweepIntervalMs(): int
    {
        return max(100, (int) config('lightspeed.presence.sweep_interval_ms', 10000));
    }
}
