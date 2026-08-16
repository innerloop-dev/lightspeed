<?php

namespace Lightspeed\Connections;

use Lightspeed\Channels\ChannelManager;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\AttachedServer;
use Swoole\Timer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Keep saying, for as long as it is true, that this worker is holding these
 * sockets.
 *
 * Connections\ConnectionRegistry publishes the answer to "which process is serving
 * socket X right now", and that answer is the only one there is: nothing inside
 * the worker needs it (it has the fd), and everything outside the process does.
 * The entry was written once, `SETEX 3600`, on the handshake, and never touched
 * again, so after an hour a connection that was still open, still subscribed and
 * still receiving broadcasts had no entry at all. A lapsed entry is byte for
 * byte the same evidence as the one Protocol\Teardown deletes on close, so the
 * consumer cannot tell "still connected, over there" from "gone": both probes
 * report a socket that never arrived, and anything routing to a socket it does
 * not hold routes it nowhere. An hour is nothing to a package whose entire
 * point is long-lived connections.
 *
 * A third timer rather than more work on the presence one, following the reason
 * PresenceSweeper is separate from GrantSweeper: the cadences are an order of
 * magnitude apart (a grant expires in seconds, a presence marker in a minute,
 * a connection entry in an hour) and renewing at the faster rate would be Redis
 * load for nothing.
 *
 * The shape is the presence sweep's, and so is the reasoning behind it: shared
 * state in Redis cannot distinguish an entry belonging to a live connection
 * from one whose process is gone, and the worker still holding the socket can.
 * A worker that stops saying it is precisely the worker that was SIGKILLed, and
 * its entries lapse on their own, which is the behaviour the TTL was there for
 * in the first place and the reason the fix is a renewal rather than a longer
 * TTL or no TTL at all.
 */
class ConnectionSweeper
{
    /**
     * The Swoole timer renewing this worker's registry entries, or null.
     *
     * Cleared at worker stop for the reason the other two sweepers' are: a
     * timer that outlived the worker it was armed in would go on vouching for
     * connections against a server this object no longer holds.
     */
    private ?int $connectionSweepTimerId = null;

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ConnectionRegistry $connectionRegistry,
        private readonly AttachedServer $attachedServer,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * Start this worker's connection sweeper.
     *
     * Follows GrantSweeper::bootGrantSweeper() exactly, including replacing
     * rather than joining an existing timer when a worker restarts in the same
     * process.
     */
    public function bootConnectionSweeper(SwooleServer $server): void
    {
        $this->attachedServer->attach($server);

        $this->shutdownConnectionSweeper();

        $this->connectionSweepTimerId = Timer::tick($this->connectionSweepIntervalMs(), function (): void {
            $this->sweepConnections();
        });
    }

    public function shutdownConnectionSweeper(): void
    {
        if ($this->connectionSweepTimerId !== null) {
            Timer::clear($this->connectionSweepTimerId);
            $this->connectionSweepTimerId = null;
        }
    }

    /**
     * One renewal pass over the connections this worker is holding.
     *
     * EVERY held connection, every tick, with no rotating budget, which is the
     * shape the presence sweep's marker pass follows too and for the same
     * reason. What rotates there is the reap, where a bound is safe because a
     * channel that waits a few ticks for it only keeps a ghost member a little
     * longer. Here a connection that is skipped for long enough
     * loses its entry outright and becomes invisible to the whole cluster,
     * which is the failure being fixed, so skipping is not a delay, it is the
     * bug. What makes that affordable is that the unit is a pipelined SETEX
     * rather than a round trip: the chunk size bounds how much goes into any one
     * pipeline, and the pass costs held/chunk round trips rather than one per
     * connection. At the shipped 500 and 5 minutes, a worker holding ten
     * thousand sockets spends twenty round trips every five minutes.
     *
     * NOTHING HERE MAY THROW, for the reason the other two sweeps may not: this
     * is a Swoole timer callback, an escaping exception is fatal to the worker,
     * and `enable_coroutine` defaults to FALSE so there is no coroutine to
     * contain it. Redis being down is the expected failure rather than an exotic
     * one, and a worker whose registry is unreachable must go on serving every
     * connection it holds; the entries lapse and the sweep that follows the
     * outage rewrites them, which is exactly why refresh() writes rather than
     * extends.
     *
     * @return int how many entries were renewed
     */
    public function sweepConnections(): int
    {
        $server = $this->attachedServer->get();

        if ($server === null) {
            return 0;
        }

        $renewed = 0;

        try {
            foreach (array_chunk($this->channels->heldSocketIds(), $this->connectionSweepChunkSize()) as $chunk) {
                $renewed += $this->connectionRegistry->refresh($chunk);
            }
        } catch (\Throwable $e) {
            // The whole pass is abandoned rather than the failing chunk alone.
            // ConnectionRegistry already retries a dropped link once and hides
            // it, so anything arriving here is a Redis that is genuinely
            // unreachable, and the remaining chunks would each be another
            // blocking round trip into the same outage, taken on the event loop
            // that is serving every connection this worker has. Nothing is lost
            // by waiting for the next tick: the entries outlive many of them.
            $this->runtimeLogger->logWebsocket('error', [
                'reason' => 'connection-sweep-failed',
                'renewed' => $renewed,
                'message' => $e->getMessage(),
            ]);
        }

        return $renewed;
    }

    /**
     * How often this worker vouches for the connections it is holding.
     *
     * The floor is what keeps the relationship with the entry TTL sane: the
     * entry must outlive several ticks or a healthy worker's own live
     * connections stop being findable, and ConnectionRegistry holds up its end
     * by refusing a TTL shorter than three of these.
     */
    private function connectionSweepIntervalMs(): int
    {
        return max(100, (int) config('lightspeed.connections.sweep_interval_ms', 300000));
    }

    /**
     * How many entries go into one pipeline.
     *
     * Not a per-tick budget: every held connection is renewed on every tick (see
     * sweepConnections()). This bounds only how much of the pass is in flight at
     * once, so a worker holding a very large number of sockets does not build
     * one enormous pipeline and hand Redis a single write it has to buffer whole.
     */
    private function connectionSweepChunkSize(): int
    {
        return max(1, (int) config('lightspeed.connections.sweep_chunk_size', 500));
    }
}
