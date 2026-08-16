<?php

use Lightspeed\Channels\ChannelManager;

/**
 * A failed push is treated as a disconnect and routed through the server's own
 * close path, and that close handler broadcasts `pusher_internal:member_removed`
 * on the same channel, synchronously, back into broadcast(). So a mass drop (a
 * load balancer reaping idle sockets, a large payload to many slow consumers)
 * used to recurse once per dropped socket, each frame holding a blocking Redis
 * XADD, and closed most sockets several times on the way back out. It died with
 * an exhausted stack long before the sockets ran out.
 *
 * These tests pin the non-re-entrant cleanup that replaced it: nested
 * broadcasts queue their drops for the outermost call to drain, so a mass drop
 * stays flat, closes each fd exactly once, and still strands nobody in the
 * subscriber set.
 *
 * The fake server stands in for Swoole: every push fails, and close() does what
 * Server.php's 'close' handler does, full local teardown, then member_removed
 * on the channel that is dropping. It is built without a constructor because
 * nothing here needs a listening socket, only the three methods below.
 */
final class MassDropProbeServer extends Swoole\WebSocket\Server
{
    public ChannelManager $channels;

    public string $channel = 'presence-room';

    public int $depth = 0;

    public int $maxDepth = 0;

    /** @var array<int, int> fd => times closed */
    public array $closeCounts = [];

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push($fd, $data, $opcode = 1, $flags = null): bool
    {
        return false;
    }

    public function close($fd, $reset = false): bool
    {
        $fd = (int) $fd;
        $this->closeCounts[$fd] = ($this->closeCounts[$fd] ?? 0) + 1;

        $this->channels->disconnect($fd);

        $this->depth++;
        $this->maxDepth = max($this->maxDepth, $this->depth);

        $this->channels->broadcast($this, $this->channel, [
            'event' => 'pusher_internal:member_removed',
            'data' => ['user_id' => (string) $fd],
        ]);

        $this->depth--;

        return true;
    }
}

/**
 * Drop every subscriber of one presence channel at once.
 *
 * 200 sockets, not the 500 a reviewer used by hand: the member_removed frames
 * are quadratic in the number of dropped members whatever the cleanup does, and
 * 200 already blows the stack on the recursive shape while staying fast enough
 * for CI.
 */
function massDrop(int $sockets = 200): MassDropProbeServer
{
    $channels = new ChannelManager();

    /** @var MassDropProbeServer $server */
    $server = (new ReflectionClass(MassDropProbeServer::class))->newInstanceWithoutConstructor();
    $server->channels = $channels;

    for ($fd = 1; $fd <= $sockets; $fd++) {
        $channels->connect($fd, 'socket-'.$fd);
        $channels->subscribe($fd, $server->channel, ['user_id' => (string) $fd]);
    }

    $channels->broadcast($server, $server->channel, ['event' => 'mass-drop', 'data' => []]);

    return $server;
}

test('it closes every socket dropped by a mass fan-out failure exactly once', function () {
    $server = massDrop();

    expect(count($server->closeCounts))->toBe(200)
        ->and(array_keys($server->closeCounts, 1, true))->toHaveCount(200);
});

test('it does not recurse when a close handler broadcasts back into the channel', function () {
    expect(massDrop()->maxDepth)->toBe(1);
});

test('it leaves no subscriber behind when the whole channel drops', function () {
    $server = massDrop();

    expect($server->channels->members($server->channel))->toBe([])
        ->and($server->channels->channelsFor(1))->toBe([])
        ->and($server->channels->socketIdFor(1))->toBeNull();
});
