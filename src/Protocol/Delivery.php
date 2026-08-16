<?php

namespace Lightspeed\Protocol;

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Write a frame to one socket, or to everyone on a channel.
 *
 * Where the websocket surface turns a decision into bytes for ONE socket. Every
 * handler, both sweepers and the revocation drain reach the wire through here,
 * so the envelope shapes (Protocol\Frames) and the "is this socket still
 * there?" check are stated once rather than in each of them.
 *
 * It holds no state and makes no decision: what to say is the caller's job,
 * saying it is this.
 *
 * IT IS NOT THE ONLY WRITER OF BYTES, and an earlier version of this docblock
 * claimed it was. Channel fan-out (ChannelManager::broadcast) encodes once and
 * pushes to every local subscriber itself, and closes the sockets whose push
 * failed. That is not an oversight to tidy up on the next pass, it is the
 * dependency direction: this class is constructed with a ChannelManager and a
 * BroadcastBridge, and the bridge reaches ChannelManager again through
 * Relay\RedisRelay, so a ChannelManager that delivered through here would close
 * a construction cycle. Fan-out also writes with the subscriber map, the stale
 * subscriber cleanup and the dropped-fd queue all in hand mid-loop, and those
 * are ChannelManager's own state.
 *
 * So: one socket, from a decision the caller already made, is here. A whole
 * channel at once is ChannelManager's, and its docblock says why the closing
 * is part of the same loop.
 */
class Delivery
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly BroadcastBridge $broadcastBridge,
    ) {
    }

    public function push(SwooleServer $server, int $fd, array $payload): void
    {
        if (!$server->isEstablished($fd)) {
            return;
        }

        $server->push($fd, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function pushPusherEvent(SwooleServer $server, int $fd, string $event, ?string $channel, mixed $data): void
    {
        $this->push($server, $fd, Frames::pusherEvent($event, $channel, $data));
    }

    public function pushPusherError(SwooleServer $server, int $fd, string $code, string $message): void
    {
        $this->push($server, $fd, Frames::pusherError($code, $message));
    }

    /**
     * Tell one connection its subscription is no longer good.
     *
     * The frame that makes a refusal recoverable: the client backs off and
     * authorizes again, which mints a fresh grant if the application still says
     * yes. Pushed by the per-message check, the expiry sweeper and the
     * revocation drain, which is why the retry advice is computed here rather
     * than in each of them.
     */
    public function pushStale(SwooleServer $server, int $fd, string $channel, string $reason): void
    {
        $this->push($server, $fd, Frames::stale($channel, $this->staleRetryMs(), $reason));
    }

    public function broadcastPusherEvent(string $channel, string $event, mixed $data, ?int $exceptFd = null): int
    {
        return $this->broadcastBridge->fanOut(
            [$channel],
            $event,
            $data,
            $exceptFd !== null ? $this->channels->socketIdFor($exceptFd) : null,
        );
    }

    /**
     * How long a refused client is asked to wait before subscribing again.
     *
     * Jittered per frame, because revoking a busy tag refuses every connection
     * carrying it at once and a client library that reacted immediately would
     * put that whole population through /broadcasting/auth, and therefore the
     * application's database, in the same instant.
     */
    private function staleRetryMs(): int
    {
        $max = max(1, (int) config('lightspeed.auth.stale_retry_max_ms', 2000));

        return random_int(0, $max);
    }
}
