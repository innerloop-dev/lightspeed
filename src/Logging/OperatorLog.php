<?php

namespace Lightspeed\Logging;

use Illuminate\Support\Facades\Log;

/**
 * The things an operator MUST see on a default configuration, each said once.
 *
 * Separate from Logging\RuntimeLogger, and the difference is the whole reason
 * this exists: that channel is opt-in, and a dependency outage failing every
 * connection, a handshake failing for every client, or a channel that has
 * quietly stopped being re-checked are all invisible unless someone turned it
 * on. These go to stdout (or Log::warning, where a log line is the right
 * shape) so a default install still reports them.
 *
 * Everything here is DEDUPLICATED, because the cause is almost always shared:
 * one Redis outage fails every connection's close at the same line, and one
 * removed `tag()` call covers every channel under a pattern. A line per
 * connection buries the thing it is reporting.
 *
 * NOTHING HERE MAY THROW. Its callers are the last frame before a Swoole
 * callback boundary, and the boundary is what that whole mechanism exists to
 * keep an exception away from: a reporter that threw would kill the worker in
 * the act of reporting that the worker nearly died.
 */
class OperatorLog
{
    /**
     * Signature of the handshake failure already reported.
     *
     * A dependency outage (Redis, typically) fails the handshake for every
     * connection attempt, and a reconnecting client pool retries hard. Logging
     * the cause once per outage keeps the reason visible without burying the
     * log in one identical line per attempt; it is cleared by the next
     * handshake that completes.
     */
    private ?string $handshakeFailure = null;

    /**
     * Signatures of callback failures already reported, as a set.
     *
     * Bounded by the number of distinct throw sites in the package rather than
     * by traffic: the signature is class + file + line, so an outage that fails
     * every connection at the same line reports once. See
     * reportCallbackFailure().
     *
     * @var array<string, true>
     */
    private array $callbackFailures = [];

    /**
     * Channel prefixes already reported as no longer re-checked, as a set.
     *
     * Bounded by the number of distinct channel PATTERNS the application
     * defines rather than by open channels or connections. See
     * reportGrantDropped().
     *
     * @var array<string, true>
     */
    private array $grantDropsLogged = [];

    public function line(string $message): void
    {
        fwrite(STDOUT, $message.PHP_EOL);
    }

    /**
     * Report a failure that escaped one of the Swoole callback bodies.
     *
     * NOTHING HERE MAY THROW. It is the last frame before the callback
     * boundary, and the boundary is what this whole mechanism exists to keep
     * an exception away from: a reporter that threw would kill the worker in
     * the act of reporting that the worker nearly died.
     *
     * Written to stdout rather than through the websocket log channel, for the
     * same reason a failed handshake is: that channel is opt-in, and a
     * dependency outage failing every frame has to be visible on a default
     * configuration. Deduplicated by signature, because the cause is almost
     * always shared: one Redis outage fails every connection's close at the
     * same line, and a line per connection would bury it.
     *
     * @param array<string, mixed> $fields
     */
    public function reportCallbackFailure(string $surface, \Throwable $e, array $fields = []): void
    {
        try {
            // Class, file and line rather than the message: the message often
            // carries a per-connection detail, and deduplicating on it would
            // print one line per connection for a single cause.
            $signature = $surface.':'.$e::class.':'.basename($e->getFile()).':'.$e->getLine();

            if (isset($this->callbackFailures[$signature])) {
                return;
            }

            $this->callbackFailures[$signature] = true;

            $context = [];
            foreach ($fields as $key => $value) {
                $context[] = $key.'='.(is_scalar($value) ? (string) $value : gettype($value));
            }

            $this->line(sprintf(
                'Lightspeed %s callback failed: %s (%s)%s',
                $surface,
                $e->getMessage(),
                $signature,
                $context === [] ? '' : ' ['.implode(' ', $context).']',
            ));
        } catch (\Throwable) {
            // Even stdout can fail (a closed descriptor under a supervisor that
            // rotated the log). Serving connections matters more than saying so.
        }
    }

    /**
     * Record why a Pusher handshake could not complete.
     *
     * Written straight to stdout instead of through the websocket log channel,
     * because that channel is opt-in and a handshake failing for every client
     * has to be visible on a default configuration. Identical failures are
     * reported once; see $handshakeFailure.
     */
    public function reportHandshakeFailure(\Throwable $e, string $path): void
    {
        $signature = $e::class.':'.basename($e->getFile()).':'.$e->getLine();

        if ($this->handshakeFailure === $signature) {
            return;
        }

        $this->handshakeFailure = $signature;

        $this->line(sprintf(
            'Lightspeed handshake failed on %s: %s (%s)',
            $path,
            $e->getMessage(),
            $signature,
        ));
    }

    /** Clear the reported handshake failure, so the next outage is reported again. */
    public function handshakeSucceeded(): void
    {
        $this->handshakeFailure = null;
    }

    /**
     * Say that a channel has stopped being re-checked per message.
     *
     * The transition is invisible from every other angle: the subscribe still
     * succeeds, the client notices nothing, no counter moves, and the only
     * evidence is a `tag()` call that is no longer in the deploy. Meanwhile the
     * headline claim the whole feature exists to make true has quietly stopped
     * applying to that channel.
     *
     * THROTTLED PER CHANNEL PREFIX, not per channel and not per connection. The
     * cause is one edited `Broadcast::channel()` callback, and its blast radius
     * is every connection on every channel matching that pattern: on a busy
     * document server that is one line per open document per worker, which is
     * the volume that buries the thing it is reporting. The prefix is the part
     * before the first dot, which is what a channel pattern like
     * `private-doc.{id}` has in common, so one removed tag() call produces one
     * line however many ids are open behind it.
     *
     * Log::warning and not the websocket log channel, for the same reason
     * OwnerCommandBus::reportRefusal() uses it: that channel is opt-in and this
     * has to be visible on a default configuration.
     */
    public function reportGrantDropped(string $channel): void
    {
        $prefix = strstr($channel, '.', true) ?: $channel;

        if (isset($this->grantDropsLogged[$prefix])) {
            return;
        }

        $this->grantDropsLogged[$prefix] = true;

        Log::warning('Lightspeed: a channel is no longer re-checked per message, because its authorization returned no grant', [
            'channel' => $channel,
            'channel_prefix' => $prefix,
            'note' => 'A connection held a grant for this channel and re-subscribed without one, which happens when a Lightspeed::tag() call is removed from the channel callback. Messages on it are no longer re-checked and revoke() can no longer reach it. Deliberate if you meant to opt out; otherwise a tag() call went missing. Further channels under this prefix are not logged by this worker.',
        ]);
    }
}
