<?php

namespace Lightspeed\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Lightspeed\Relay\RedisRelay;
use Swoole\WebSocket\Server;

/**
 * Minimal bridge between Laravel broadcasting and the Lightspeed relay runtime.
 */
class BroadcastBridge
{
    public function __construct(
        private readonly RedisRelay $relay,
    ) {
    }

    public function attach(Server $server, int $workerId): void
    {
        $this->relay->bootWorker($server, $workerId);
    }

    public function detach(): void
    {
        $this->relay->shutdownWorker();
    }

    public function broadcast(array $channels, string $event, array $payload, ?string $exceptSocketId = null): void
    {
        $this->fanOut($channels, $event, $payload, $exceptSocketId);
    }

    /** Normalize payload encoding before handing off to the relay. */
    public function fanOut(array $channels, string $event, mixed $payload, ?string $exceptSocketId = null): int
    {
        $wirePayload = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->fanOutMessage(
            $channels,
            [
                'event' => $event,
                'data' => $wirePayload,
            ],
            $exceptSocketId,
        );
    }

    /** Publish a fully formed pusher-style message through the relay. */
    public function fanOutMessage(array $channels, array $baseMessage, ?string $exceptSocketId = null): int
    {
        if (!$this->relay->canPublish()) {
            throw new BroadcastException('Lightspeed broadcast bridge is not attached.');
        }

        return $this->relay->publishMessage($channels, $baseMessage, $exceptSocketId);
    }
}
