<?php

namespace Lightspeed\Presence;

use Lightspeed\Channels\ChannelManager;
use Lightspeed\Workers\WorkerContext;

/**
 * The identity a presence row is counted under.
 *
 * Worker process key plus socket id, so a member is attributable to the exact
 * connection on the exact worker that holds it. That is what lets the presence
 * sweeper tell a row belonging to a live connection from one left behind by a
 * worker that was killed: only the worker in the key can vouch for it.
 *
 * The socket id may be passed in by a caller that has already read it (the
 * close path has it from the disconnect result, and by then the channel
 * manager no longer does), and falls back to the fd when a connection never
 * completed its handshake.
 */
class ConnectionId
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly WorkerContext $workerContext,
    ) {
    }

    public function presenceConnectionId(int $fd, ?string $socketId = null): string
    {
        $resolvedSocketId = $socketId ?: $this->channels->socketIdFor($fd) ?: (string) $fd;

        return sprintf('%s:socket:%s', $this->workerContext->currentProcessKey(), $resolvedSocketId);
    }
}
