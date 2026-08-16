<?php

namespace Lightspeed\Channels;

use Lightspeed\Presence\PresenceStore;
use Swoole\WebSocket\Server;

/**
 * In-memory subscription state for one Lightspeed worker process.
 *
 * Cross-worker presence lives in Redis via PresenceStore. This class
 * only tracks the local fd/socket/channel state needed for direct delivery.
 */
class ChannelManager
{
    private array $channelSubscribers = [];

    private array $connectionChannels = [];

    private array $socketIds = [];

    private array $socketToFd = [];

    private array $connectionContext = [];

    private array $presenceMembers = [];

    /** fds whose push failed and that still have to be closed, as a set. */
    private array $pendingCloseFds = [];

    /** fds already closed by the current drain, so each is closed exactly once. */
    private array $closedFds = [];

    /** True while the outermost broadcast is draining pendingCloseFds. */
    private bool $draining = false;

    public function connect(int $fd, string $socketId, array $context = []): void
    {
        $this->socketIds[$fd] = $socketId;
        $this->socketToFd[$socketId] = $fd;
        $this->connectionContext[$fd] = $context;
    }

    public function socketIdFor(int $fd): ?string
    {
        return $this->socketIds[$fd] ?? null;
    }

    public function fdForSocketId(string $socketId): ?int
    {
        return $this->socketToFd[$socketId] ?? null;
    }

    public function subscribe(int $fd, string $channel, ?array $presenceMember = null): array
    {
        $wasSubscribed = isset($this->connectionChannels[$fd][$channel]);
        $this->connectionChannels[$fd][$channel] = true;
        $this->channelSubscribers[$channel][$fd] = true;

        if ($presenceMember !== null && !$wasSubscribed) {
            $this->presenceMembers[$channel][$fd] = $presenceMember;
        }

        return [
            'new_subscription' => !$wasSubscribed,
        ];
    }

    /**
     * Remove one local subscription and return any presence member payload that
     * should also be removed from the shared presence store.
     */
    public function unsubscribe(int $fd, string $channel): array
    {
        $wasSubscribed = isset($this->connectionChannels[$fd][$channel]);
        $presenceMember = $this->presenceMembers[$channel][$fd] ?? null;

        if ($presenceMember !== null) {
            unset($this->presenceMembers[$channel][$fd]);

            if (empty($this->presenceMembers[$channel])) {
                unset($this->presenceMembers[$channel]);
            }
        }

        unset($this->connectionChannels[$fd][$channel]);
        if (empty($this->connectionChannels[$fd])) {
            unset($this->connectionChannels[$fd]);
        }

        unset($this->channelSubscribers[$channel][$fd]);
        if (empty($this->channelSubscribers[$channel])) {
            unset($this->channelSubscribers[$channel]);
        }

        return [
            'removed' => $wasSubscribed,
            'presence_member' => $presenceMember,
        ];
    }

    /** Remove every local subscription for a closing fd. */
    public function disconnect(int $fd): array
    {
        $channels = $this->channelsFor($fd);
        $presenceLeaves = [];

        foreach ($channels as $channel) {
            $result = $this->unsubscribe($fd, $channel);

            if ($result['presence_member'] !== null) {
                $presenceLeaves[] = [
                    'channel' => $channel,
                    'presence_member' => $result['presence_member'],
                ];
            }
        }

        $socketId = $this->socketIds[$fd] ?? null;
        unset($this->socketIds[$fd]);
        unset($this->connectionContext[$fd]);

        if ($socketId !== null) {
            unset($this->socketToFd[$socketId]);
        }

        return [
            'channels' => $channels,
            'presence_leaves' => $presenceLeaves,
            'socket_id' => $socketId,
        ];
    }

    public function isSubscribed(int $fd, string $channel): bool
    {
        return isset($this->connectionChannels[$fd][$channel]);
    }

    public function channelsFor(int $fd): array
    {
        return array_keys($this->connectionChannels[$fd] ?? []);
    }

    public function connectionContext(int $fd): array
    {
        return is_array($this->connectionContext[$fd] ?? null)
            ? $this->connectionContext[$fd]
            : [];
    }

    public function members(string $channel): array
    {
        return array_map(
            static fn (int $fd) => ['id' => (string) $fd],
            array_keys($this->channelSubscribers[$channel] ?? [])
        );
    }

    public function presenceMember(int $fd, string $channel): ?array
    {
        return $this->presenceMembers[$channel][$fd] ?? null;
    }

    /**
     * Every socket id this worker is currently holding a connection for.
     *
     * The connection sweeper's input, and per-worker for exactly the reason
     * presenceSubscriptions() is: a registry entry names the process serving a
     * socket, and the only process that can honestly say it is still serving
     * that socket is the one holding the fd. Sourced from this table rather
     * than from Redis so that a socket which has closed cannot be vouched for:
     * disconnect() takes it out of here before anything else runs.
     *
     * @return list<string>
     */
    public function heldSocketIds(): array
    {
        return array_values($this->socketIds);
    }

    /**
     * Every presence membership this worker is currently holding.
     *
     * The presence sweeper's input, and the reason it can exist: shared
     * presence state in Redis cannot tell a live connection from one whose
     * process was killed, but the worker still serving the socket can. This is
     * that knowledge, and it is per-worker because a connection belongs to
     * exactly one.
     *
     * @return array<string, list<int>> channel => fds
     */
    public function presenceSubscriptions(): array
    {
        $subscriptions = [];

        foreach ($this->presenceMembers as $channel => $members) {
            $fds = array_keys($members);

            if ($fds !== []) {
                $subscriptions[(string) $channel] = $fds;
            }
        }

        return $subscriptions;
    }

    /**
     * Deliver one normalized websocket message to local subscribers only.
     *
     * This encodes and pushes here rather than through Protocol\Delivery, which
     * is where a single-socket write belongs. Fan-out is the exception, for two
     * reasons that are both structural: Delivery is constructed with a
     * ChannelManager (and with a BroadcastBridge that reaches one again through
     * the relay), so delivering through it from here would close a construction
     * cycle; and the loop below writes with the subscriber snapshot, the stale
     * subscriber cleanup and the dropped-fd queue all in hand, which is this
     * class's own state and does not travel.
     *
     * A push can fail mid-fan-out, almost always because the client dropped
     * between the subscriber snapshot and the write. Dropping only this one
     * channel subscription would leave the connection half-alive: it stays in
     * the presence set, no `pusher_internal:member_removed` goes out, and every
     * later presence snapshot reports a member that is gone. So a failed push
     * is treated as the disconnect it really is, and is routed through the
     * server's own close path rather than reimplemented here: this class owns
     * local subscription state only, and the close handler is the single owner
     * of the full teardown (local state, shared presence store, member_removed,
     * connection registry).
     *
     *   push() === false
     *     -> remember the fd
     *     -> after the loop: $server->close($fd)
     *     -> Swoole 'close' -> Server close handler -> full disconnect
     *
     * The close is deferred until the loop ends on purpose: the close handler
     * broadcasts member_removed, which re-enters broadcast() for this very
     * channel. Deferring keeps that re-entry outside the iteration, so nested
     * cleanup never mutates state the loop is still walking.
     *
     * Deferring alone is not enough, because $server->close() runs the 'close'
     * handler SYNCHRONOUSLY: the nested broadcast can itself find failed pushes
     * and close them, one stack frame deeper, with a blocking Redis XADD at
     * every level. A mass drop (a load balancer reaping idle sockets, a large
     * payload to many slow consumers) would recurse once per dropped socket:
     * an unbounded stack, one blocking XADD per frame, and the same fd closed
     * again on the way out. So the cleanup is made non-re-entrant (a queue
     * plus a guard flag):
     *
     *   outermost broadcast: queue drops -> drain: close, close, close ...
     *     nested broadcast (from a close handler): queue drops -> return
     *                                              ^ the outer drain picks
     *                                                them up on its next turn
     *
     * The drain is a flat loop, so depth stays at one close handler however many
     * sockets drop, and the number of closes is linear in the number of dropped
     * fds. $closedFds is what makes it exactly one close per fd: a socket
     * re-queued by a nested broadcast (its fd can still be a subscriber of
     * another channel mid-teardown) is not closed a second time. The
     * member_removed frames those closes emit are still O(N^2) for N dropped
     * members, but that is the presence protocol's own cost, not the recursion.
     */
    public function broadcast(Server $server, string $channel, array $message, ?int $exceptFd = null): int
    {
        $subscribers = array_keys($this->channelSubscribers[$channel] ?? []);
        if (empty($subscribers)) {
            return 0;
        }

        $encoded = json_encode($message, JSON_THROW_ON_ERROR);
        $delivered = 0;

        foreach ($subscribers as $fd) {
            if ($exceptFd !== null && $fd === $exceptFd) {
                continue;
            }

            // Not established means the handshake never finished or the close
            // handler has already run, so there is no live presence membership
            // to strand; clearing the stale subscriber entry is enough.
            if (!$server->isEstablished($fd)) {
                $this->unsubscribe($fd, $channel);
                continue;
            }

            if ($server->push($fd, $encoded) === false) {
                if (!isset($this->closedFds[$fd])) {
                    $this->pendingCloseFds[$fd] = true;
                }

                continue;
            }

            $delivered++;
        }

        $this->closeDroppedFds($server);

        return $delivered;
    }

    /**
     * Close every fd queued by a failed push, without re-entering.
     *
     * Only the outermost broadcast drains. A broadcast that runs inside a close
     * handler returns immediately after queueing, and its fds are picked up by
     * the drain already in progress. The guard is released in a finally block so
     * a throwing close handler cannot wedge the queue shut for the process.
     */
    private function closeDroppedFds(Server $server): void
    {
        if ($this->draining || $this->pendingCloseFds === []) {
            return;
        }

        $this->draining = true;

        try {
            while (($fd = array_key_first($this->pendingCloseFds)) !== null) {
                unset($this->pendingCloseFds[$fd]);
                $this->closedFds[$fd] = true;

                $server->close($fd);
            }
        } finally {
            $this->draining = false;
            $this->closedFds = [];
        }
    }
}
