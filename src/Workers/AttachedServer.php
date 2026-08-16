<?php

namespace Lightspeed\Workers;

use Swoole\WebSocket\Server as SwooleServer;

/**
 * The Swoole server this worker is attached to, or nothing before workerStart.
 *
 * One fact, held once and shared by the parts that need it. Every ordinary
 * path is handed the server by the Swoole callback that woke it; the ones held
 * here are the ones nobody is currently talking to, a revocation arriving over
 * the relay, and the two sweepers' timers, all of which have to push frames and
 * close subscriptions on their own initiative.
 *
 * It is deliberately a typed holder rather than a field copied into each of
 * them: worker stop detaches ONCE, and a timer that outlived its worker would
 * otherwise run against a server one of those copies still held.
 */
class AttachedServer
{
    private ?SwooleServer $swoole = null;

    public function attach(SwooleServer $server): void
    {
        $this->swoole = $server;
    }

    public function detach(): void
    {
        $this->swoole = null;
    }

    public function get(): ?SwooleServer
    {
        return $this->swoole;
    }
}
