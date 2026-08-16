<?php

use Illuminate\Broadcasting\BroadcastException;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Relay\RedisRelay;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Attaching is what makes the bridge able to deliver anything.
 *
 * `worker_num` defaults to 1 and the Redis relay is off by default, so on a
 * default install local delivery is not an optimization in front of the relay:
 * it is the only delivery there is, and the server the worker start hands to
 * the bridge is the only route to a socket the process has.
 *
 * The bridge refuses to publish when it has no route, which is the right
 * refusal and also the thing that hides a missing attach: a broadcast that
 * throws "not attached" looks like a configuration problem rather than a
 * workerStart that stopped wiring itself up.
 */
test('a bridge that was attached can publish, and one that was not refuses to', function () {
    // The relay's Redis half off, so `canPublish()` can only be true because a
    // local server was attached to it, which is exactly the path a default
    // install runs on.
    config()->set('lightspeed.relay.enabled', false);

    $swoole = (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor();
    $bridge = new BroadcastBridge(app(RedisRelay::class));

    expect(static fn () => $bridge->fanOut(['proto-mut-orders'], 'OrderShipped', ['order' => 7]))
        ->toThrow(BroadcastException::class);

    $bridge->attach($swoole, 0);

    // Nobody is subscribed, so nothing is delivered; the number is not the
    // point. Getting one back rather than a refusal is.
    expect($bridge->fanOut(['proto-mut-orders'], 'OrderShipped', ['order' => 7]))->toBe(0);
});
