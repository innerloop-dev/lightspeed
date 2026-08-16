<?php

use Lightspeed\Channels\ChannelManager;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The bookkeeping every other surface in this package trusts.
 *
 * ChannelManager is the only thing that knows who is on a channel, which socket
 * id belongs to which fd, and which presence memberships this worker is still
 * vouching for. Nothing it returns is validated anywhere downstream: the
 * presence sweeper reaps whatever presenceSubscriptions() reports, the close
 * path removes whatever disconnect() names, and a member list goes straight out
 * on the wire. So a wrong answer here is not caught later, it is delivered.
 *
 * These tests are about the shapes and the edges of those answers, and about
 * the fan-out's failure handling, which is the one part of the class with
 * control flow worth breaking.
 */

/** A Swoole server that records pushes and closes instead of performing them. */
final class ChanMutBroadcastServer extends SwooleServer
{
    /** @var list<int> fds whose push should report failure */
    public array $failingFds = [];

    /** @var list<int> fds closed, in the order the drain closed them */
    public array $closed = [];

    /** @var list<int> fds a push actually reached */
    public array $delivered = [];

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
        if (in_array($fd, $this->failingFds, true)) {
            return false;
        }

        $this->delivered[] = $fd;

        return true;
    }

    public function close(int $fd, bool $reset = false): bool
    {
        $this->closed[] = $fd;

        return true;
    }
}

test('a member list is the subscriber fds, as strings, in an id-keyed array', function () {
    // This array is a wire payload: it is what a presence snapshot is built
    // from, and a Pusher client reads member ids as strings. An int id, a bare
    // fd, or an empty list is a protocol change, not an internal detail.
    $channels = new ChannelManager();
    $channels->subscribe(7, 'chat');
    $channels->subscribe(9, 'chat');

    expect($channels->members('chat'))->toBe([['id' => '7'], ['id' => '9']])
        ->and($channels->members('nobody-here'))->toBe([]);
});

test('a subscribe with no presence member vouches for no presence membership', function () {
    // presenceSubscriptions() is the presence sweeper's input, and the sweeper
    // acts on it: a plain subscription listed here as a presence membership is
    // a member the sweeper will later reap on behalf of a channel that never
    // had one.
    $channels = new ChannelManager();
    $channels->subscribe(4, 'public-room');

    expect($channels->presenceSubscriptions())->toBe([])
        ->and($channels->presenceMember(4, 'public-room'))->toBeNull();
});

test('a presence member is held for the connection that joined and handed back whole', function () {
    $channels = new ChannelManager();
    $member = ['user_id' => '42', 'user_info' => ['name' => 'Ada']];

    $channels->subscribe(4, 'presence-room', $member);

    expect($channels->presenceMember(4, 'presence-room'))->toBe($member)
        ->and($channels->presenceSubscriptions())->toBe(['presence-room' => [4]]);
});

test('unsubscribing removes only the leaving member, and only from the leaving connection', function () {
    // Two failures live here. Skipping the removal strands a member in every
    // later snapshot with nobody told it left; removing the whole channel entry
    // when it still has members strands everyone else instead, silently, and
    // the sweeper stops vouching for connections this worker is still serving.
    $channels = new ChannelManager();
    $channels->subscribe(4, 'presence-room', ['user_id' => '4']);
    $channels->subscribe(5, 'presence-room', ['user_id' => '5']);

    $result = $channels->unsubscribe(4, 'presence-room');

    expect($result)->toBe(['removed' => true, 'presence_member' => ['user_id' => '4']])
        ->and($channels->presenceMember(4, 'presence-room'))->toBeNull()
        ->and($channels->presenceMember(5, 'presence-room'))->toBe(['user_id' => '5'])
        ->and($channels->presenceSubscriptions())->toBe(['presence-room' => [5]]);
});

test('unsubscribing one channel leaves the connection on its others', function () {
    // The empty() cleanup is housekeeping, and housekeeping that fires when the
    // container is not empty takes a live connection off every channel it holds
    // while it still believes it is subscribed.
    $channels = new ChannelManager();
    $channels->subscribe(4, 'alpha');
    $channels->subscribe(4, 'beta');

    $channels->unsubscribe(4, 'alpha');

    expect($channels->channelsFor(4))->toBe(['beta'])
        ->and($channels->isSubscribed(4, 'beta'))->toBeTrue()
        ->and($channels->isSubscribed(4, 'alpha'))->toBeFalse();
});

test('unsubscribing a channel the connection never held reports that nothing was removed', function () {
    // The caller decides whether to broadcast a leave on this flag alone.
    $channels = new ChannelManager();

    expect($channels->unsubscribe(4, 'never-joined'))
        ->toBe(['removed' => false, 'presence_member' => null]);
});

test('a disconnect reports every channel it took the connection off, and forgets the socket id', function () {
    // The close path fans out on this list and looks the connection up by
    // socket id afterwards. A missing list leaves subscriptions in the shared
    // stores that no local state remembers; a socket id still resolving to a
    // dead fd sends the next lookup to a closed socket.
    $channels = new ChannelManager();
    $channels->connect(4, 'sock-4');
    $channels->subscribe(4, 'alpha');
    $channels->subscribe(4, 'presence-room', ['user_id' => '4']);

    $result = $channels->disconnect(4);

    expect($result['channels'])->toBe(['alpha', 'presence-room'])
        ->and($result['socket_id'])->toBe('sock-4')
        ->and($result['presence_leaves'])->toBe([
            ['channel' => 'presence-room', 'presence_member' => ['user_id' => '4']],
        ])
        ->and($channels->fdForSocketId('sock-4'))->toBeNull()
        ->and($channels->socketIdFor(4))->toBeNull();
});

test('the held socket ids are a list, not a map keyed by fd', function () {
    // The connection sweeper sends this straight to Redis as a set of socket
    // ids. Keyed by fd it is still every socket id, so nothing throws; it just
    // arrives as an object where a list was expected.
    $channels = new ChannelManager();
    $channels->connect(5, 'sock-5');
    $channels->connect(3, 'sock-3');

    expect($channels->heldSocketIds())->toBe(['sock-5', 'sock-3']);
});

test('a broadcast to a channel nobody is on does no work at all', function () {
    // The early return is not an optimisation: the encode happens after it, so
    // a message that cannot be encoded is never encoded for an audience of
    // nobody. Without the return this raises where it used to return zero.
    $channels = new ChannelManager();
    $server = ChanMutBroadcastServer::make();

    expect($channels->broadcast($server, 'silent', ['data' => NAN]))->toBe(0)
        ->and($server->delivered)->toBe([]);
});

test('one dropped socket does not stop the rest of the fan-out', function () {
    // A push fails when the client dropped between the subscriber snapshot and
    // the write, which says nothing about the sockets after it in the loop.
    // Abandoning the loop there turns one dead client into a channel-wide
    // delivery failure that nothing reports.
    $channels = new ChannelManager();
    $channels->subscribe(11, 'chat');
    $channels->subscribe(12, 'chat');
    $channels->subscribe(13, 'chat');

    $server = ChanMutBroadcastServer::make();
    $server->failingFds = [11];

    expect($channels->broadcast($server, 'chat', ['event' => 'x']))->toBe(2)
        ->and($server->delivered)->toBe([12, 13])
        ->and($server->closed)->toBe([11]);
});

test('dropped sockets are closed oldest first, and each exactly once', function () {
    // The drain is a queue, and the order it comes out in is the order the
    // pushes failed in. Taking the newest first is a stack, which under a mass
    // drop closes the sockets that dropped last while the first ones wait.
    $channels = new ChannelManager();
    $channels->subscribe(21, 'chat');
    $channels->subscribe(22, 'chat');

    $server = ChanMutBroadcastServer::make();
    $server->failingFds = [21, 22];

    expect($channels->broadcast($server, 'chat', ['event' => 'x']))->toBe(0)
        ->and($server->closed)->toBe([21, 22]);
});

test('a later broadcast still drains its own drops', function () {
    // The re-entrancy guard is released in a finally block, and it has to be
    // released for real: a guard left standing after the first drain means no
    // broadcast for the rest of this worker's life ever closes a dropped
    // socket, and the sockets accumulate in the queue instead.
    $channels = new ChannelManager();
    $channels->subscribe(31, 'alpha');
    $channels->subscribe(32, 'beta');

    $server = ChanMutBroadcastServer::make();
    $server->failingFds = [31, 32];

    $channels->broadcast($server, 'alpha', ['event' => 'x']);
    $channels->broadcast($server, 'beta', ['event' => 'y']);

    expect($server->closed)->toBe([31, 32]);
});
