<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Channels\ChannelName;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: an anonymous client cannot make this
 * server allocate without limit.
 *
 * Public channels need no authorization by design and the app key is public by
 * design, so `pusher:subscribe` is the one path here that an unauthenticated
 * client drives into an allocation: the channel name becomes an array key in
 * ChannelManager, twice, for the life of the connection. Nothing bounded the
 * name's length, its character set, or how many distinct names one connection
 * could hold, so
 *
 *   {"event":"pusher:subscribe","data":{"channel":"<64KB of junk>"}}
 *
 * in a loop grew the worker until it was OOM-killed. And `worker_num` defaults
 * to 1, so that is the whole server.
 *
 * The limits are Pusher's and Reverb's, which is what makes adding them safe:
 * an application whose channel names work against either is unaffected.
 */

class ChannelLimitsSwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push(int $fd, \Swoole\WebSocket\Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }
}

function channelLimitsServer(): Server
{
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $collaborators = [
        'channels' => app(ChannelManager::class),
        'broadcastBridge' => app(BroadcastBridge::class),
        'connectionRegistry' => app(ConnectionRegistry::class),
        'resourceRouter' => app(ResourceRouter::class),
        'ownerCommandBus' => app(OwnerCommandBus::class),
        'presenceStore' => app(PresenceStore::class),
        'workerContext' => app(WorkerContext::class),
        'relay' => app(RedisRelay::class),
        'runtimeLogger' => app(RuntimeLogger::class),
        'connectionGrants' => app(ConnectionGrants::class),
        'revocations' => app(RevocationLog::class),
        'httpWorker' => app(Lightspeed\Http\OctaneWorker::class),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    // Builds the websocket surfaces out of the collaborators just injected,
    // exactly as the constructor does after building the Octane worker.
    driveLightspeed($server, 'compose', []);

    return $server;
}

function driveSubscribe(Server $server, ChannelLimitsSwooleServer $swoole, int $fd, string $channel): void
{
    driveLightspeed($server, 'handlePusherSubscribe', [$swoole, $fd, ['channel' => $channel]]);
}

/** The last frame's decoded `data` member. */
function channelLimitsLastData(ChannelLimitsSwooleServer $swoole): array
{
    $frame = end($swoole->pushed);

    return json_decode($frame['data'] ?? '{}', true);
}

function channelLimitsConnection(): array
{
    $fd = random_int(1000, 999999);
    app(ChannelManager::class)->connect($fd, "{$fd}.".random_int(1000, 999999), []);

    return [channelLimitsServer(), ChannelLimitsSwooleServer::make(), $fd];
}

test('a channel name past the length limit is refused and allocates nothing', function () {
    // The attack verbatim. A public channel, so nothing authenticates and
    // nothing authorizes: this refusal is the only thing in the way.
    [$server, $swoole, $fd] = channelLimitsConnection();

    $junk = str_repeat('a', 64 * 1024);
    driveSubscribe($server, $swoole, $fd, $junk);

    expect(app(ChannelManager::class)->channelsFor($fd))->toBe([])
        ->and($swoole->pushed[0]['event'])->toBe('pusher:error')
        ->and(channelLimitsLastData($swoole)['code'])->toBe('channel-name-too-long');
});

test('the refusal does not echo the name it refused', function () {
    // A refusal that repeats the input turns one rejected allocation into two
    // accepted ones: a socket write and a line on the server's disk.
    [$server, $swoole, $fd] = channelLimitsConnection();

    driveSubscribe($server, $swoole, $fd, str_repeat('a', 64 * 1024));

    expect(strlen(json_encode($swoole->pushed)))->toBeLessThan(500);
});

test('a name exactly at the limit is accepted and one character over is not', function () {
    // 164 is Pusher's own limit, and matching it exactly is what makes this
    // safe to add to a package that already has users.
    [$server, $swoole, $fd] = channelLimitsConnection();

    $atLimit = str_repeat('a', ChannelName::MAX_LENGTH);
    driveSubscribe($server, $swoole, $fd, $atLimit);

    expect(app(ChannelManager::class)->channelsFor($fd))->toBe([$atLimit]);

    driveSubscribe($server, $swoole, $fd, str_repeat('b', ChannelName::MAX_LENGTH + 1));

    expect(app(ChannelManager::class)->channelsFor($fd))->toBe([$atLimit]);
});

test('a channel name outside the Pusher charset is refused', function () {
    foreach (['bad channel', "new\nline", 'quote"mark', 'café', "nul\0byte", '<script>'] as $channel) {
        [$server, $swoole, $fd] = channelLimitsConnection();

        driveSubscribe($server, $swoole, $fd, $channel);

        expect(app(ChannelManager::class)->channelsFor($fd))->toBe([], "[{$channel}] was allowed through")
            ->and(channelLimitsLastData($swoole)['code'])->toBe('channel-name-invalid');
    }
});

test('every character Pusher allows is still allowed', function () {
    // The other half of the charset check, and the one that would break real
    // applications if it were wrong.
    [$server, $swoole, $fd] = channelLimitsConnection();

    // Public, so the subscription authorizer waves it through and what is
    // being observed is this gate alone.
    $channel = 'Order.42_a-b=c@d,e;f';
    driveSubscribe($server, $swoole, $fd, $channel);

    expect(app(ChannelManager::class)->channelsFor($fd))->toContain($channel)
        ->and($swoole->pushed[0]['event'])->toBe('pusher_internal:subscription_succeeded');
});

test('a connection cannot hold more channels than the cap', function () {
    config()->set('lightspeed.channels.max_per_connection', 5);

    [$server, $swoole, $fd] = channelLimitsConnection();

    for ($i = 0; $i < 20; $i++) {
        driveSubscribe($server, $swoole, $fd, "public-channel-{$i}");
    }

    expect(app(ChannelManager::class)->channelsFor($fd))->toHaveCount(5)
        ->and(channelLimitsLastData($swoole)['code'])->toBe('channel-limit-reached');
});

test('re-subscribing to a channel already held is not refused at the cap', function () {
    // A `lightspeed:stale` frame asks a client to subscribe again, and it does
    // so at exactly the moment the connection is at its cap. Counting a
    // re-subscribe would make that instruction impossible to follow.
    config()->set('lightspeed.channels.max_per_connection', 2);

    [$server, $swoole, $fd] = channelLimitsConnection();

    driveSubscribe($server, $swoole, $fd, 'public-one');
    driveSubscribe($server, $swoole, $fd, 'public-two');
    driveSubscribe($server, $swoole, $fd, 'public-one');

    expect(array_column($swoole->pushed, 'event'))->not->toContain('pusher:error')
        ->and(app(ChannelManager::class)->channelsFor($fd))->toHaveCount(2);
});

test('the channel limits ship at the Pusher and Reverb values', function () {
    expect(config('lightspeed.channels.max_name_length'))->toBe(164)
        ->and(config('lightspeed.channels.max_per_connection'))->toBe(100);
});
