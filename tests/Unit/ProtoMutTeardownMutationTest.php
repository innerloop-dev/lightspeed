<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Presence\ConnectionId;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\Teardown;
use Lightspeed\Connections\ConnectionClosedDispatcher;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * What a closing connection leaves behind, and what it says on the way out.
 *
 * Teardown is best-effort by nature, which makes it the easiest thing in the
 * package to break silently: nothing downstream fails when a per-fd entry is
 * left in place, until Swoole hands the same fd number to somebody else. Two of
 * the lines here exist for exactly that reuse, and one of them decides whether
 * the next holder of the fd may speak the diagnostic protocol.
 *
 * The close log is the other half. It is the operator's record that a socket
 * ended, and it is deliberately not written for an fd that was never really a
 * connection, so that a port scan does not fill the log with closes.
 */
class ProtoMutTeardownLogger extends RuntimeLogger
{
    /** @var list<array{action: string, fields: array}> */
    public array $entries = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->entries[] = ['action' => $action, 'fields' => $fields];
    }
}

/** A channel manager whose close result is written by the test. */
class ProtoMutTeardownChannels extends ChannelManager
{
    public function __construct(private readonly array $result)
    {
    }

    public function disconnect(int $fd): array
    {
        return $this->result;
    }
}

/** A presence store that always says the departure is worth announcing. */
class ProtoMutTeardownPresence extends PresenceStore
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function leave(string $channel, string $connectionId): array
    {
        return ['broadcast_member_removed' => true, 'user_id' => null];
    }
}

/** Records the member_removed frames instead of putting them on a wire. */
class ProtoMutTeardownDelivery extends Delivery
{
    /** @var list<array{channel: string, event: string, data: mixed}> */
    public array $broadcasts = [];

    public function __construct()
    {
    }

    public function broadcastPusherEvent(string $channel, string $event, mixed $data, ?int $exceptFd = null): int
    {
        $this->broadcasts[] = ['channel' => $channel, 'event' => $event, 'data' => $data];

        return 1;
    }
}

/** A Teardown built from the real collaborators, with the log recorded. */
function proto_mut_teardown(ProtoMutTeardownLogger $logger, array $overrides = []): Teardown
{
    return new Teardown(
        $overrides['channels'] ?? app(ChannelManager::class),
        $overrides['grants'] ?? app(ConnectionGrants::class),
        app(ConnectionRegistry::class),
        $overrides['presence'] ?? app(PresenceStore::class),
        $overrides['diagnostics'] ?? app(DiagnosticSockets::class),
        app(ConnectionId::class),
        $overrides['delivery'] ?? app(Delivery::class),
        $logger,
        $overrides['connectionClosed'] ?? app(ConnectionClosedDispatcher::class),
    );
}

function proto_mut_teardown_swoole(): SwooleServer
{
    return (new ReflectionClass(SwooleServer::class))->newInstanceWithoutConstructor();
}

/** The fields of the single `close` entry, or null if none was written. */
function proto_mut_close_entry(ProtoMutTeardownLogger $logger): ?array
{
    foreach ($logger->entries as $entry) {
        if ($entry['action'] === 'close') {
            return $entry['fields'];
        }
    }

    return null;
}

test('a closing connection is logged with its fd, its socket id and the channels it held', function () {
    $logger = ProtoMutTeardownLogger::make();
    $channels = app(ChannelManager::class);
    $fd = random_int(1000, 999999);
    $channel = 'proto-mut-teardown.'.bin2hex(random_bytes(4));

    $channels->connect($fd, '123.456', []);
    $channels->subscribe($fd, $channel);

    proto_mut_teardown($logger)->closeConnection(proto_mut_teardown_swoole(), $fd);

    // Whole-entry, because each member answers a different operator question:
    // which connection ended, which socket id the rest of the fleet knew it by,
    // and what it was subscribed to when it went.
    expect(proto_mut_close_entry($logger))->toBe([
        'fd' => $fd,
        'socket' => '123.456',
        'channels' => [$channel],
    ]);
});

test('a connection that never subscribed is still logged, with no channels', function () {
    $logger = ProtoMutTeardownLogger::make();
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, '124.456', []);

    proto_mut_teardown($logger)->closeConnection(proto_mut_teardown_swoole(), $fd);

    // A socket that opened and closed without subscribing is a real connection
    // ending and belongs in the log; `channels` is null rather than an empty
    // list so the entry does not claim a subscription that was never made.
    expect(proto_mut_close_entry($logger))->toBe([
        'fd' => $fd,
        'socket' => '124.456',
        'channels' => null,
    ]);
});

test('an fd that was never a connection is torn down in silence', function () {
    $logger = ProtoMutTeardownLogger::make();

    proto_mut_teardown($logger)->closeConnection(proto_mut_teardown_swoole(), random_int(1000, 999999));

    // Swoole runs the close handler for anything that reached the port,
    // including a scan that opened a TCP connection and hung up. Logging those
    // buries the closes an operator is actually looking for.
    expect(proto_mut_close_entry($logger))->toBeNull();
});

test('a closed fd stops being eligible for the diagnostic protocol', function () {
    $logger = ProtoMutTeardownLogger::make();
    // Not resolved from the container: eligibility is per worker process, and
    // this map is the process's, so the test holds the very instance the
    // teardown is given.
    $diagnostics = new DiagnosticSockets();
    $fd = random_int(1000, 999999);

    $diagnostics->admit($fd);

    proto_mut_teardown($logger, ['diagnostics' => $diagnostics])
        ->closeConnection(proto_mut_teardown_swoole(), $fd);

    // Swoole reuses fd numbers. Eligibility left behind here is inherited by
    // the next connection to be handed this number, and it is eligibility for
    // the protocol that carries no channel authorization at all.
    expect($diagnostics->allows($fd))->toBeFalse();
});

test('a closed fd stops carrying the grants it was minted', function () {
    $logger = ProtoMutTeardownLogger::make();
    $grants = app(ConnectionGrants::class);
    $fd = random_int(1000, 999999);
    $channel = 'private-proto-mut-teardown.'.bin2hex(random_bytes(4));
    $now = (int) (microtime(true) * 1_000_000);

    $grants->mint($fd, $channel, new Grant(['proto-mut:'.bin2hex(random_bytes(4))], [], $now, $now + 60_000_000));

    proto_mut_teardown($logger)->closeConnection(proto_mut_teardown_swoole(), $fd);

    // The same fd reuse, on the piece of per-fd state whose survival GRANTS
    // access rather than merely confusing a log line: the next connection on
    // this number would be found already permitted on a private channel.
    expect($grants->grantFor($fd, $channel))->toBeNull();
});

test('a departing presence member is always named as a string', function () {
    $logger = ProtoMutTeardownLogger::make();
    $delivery = new ProtoMutTeardownDelivery();

    $teardown = proto_mut_teardown($logger, [
        // A member whose id is a number, and one that has lost its id
        // altogether. Neither should be reachable through a subscribe, which is
        // the point: this is the frame every other client uses to remove
        // somebody from a roster it is painting, and pusher-js matches roster
        // entries by string id. A number there removes nobody, and a missing
        // key drops the member from the frame entirely.
        'channels' => new ProtoMutTeardownChannels([
            'channels' => ['presence-a', 'presence-b'],
            'socket_id' => '125.456',
            'presence_leaves' => [
                ['channel' => 'presence-a', 'presence_member' => ['user_id' => 12345]],
                ['channel' => 'presence-b', 'presence_member' => ['name' => 'no id here']],
            ],
        ]),
        'presence' => ProtoMutTeardownPresence::make(),
        'delivery' => $delivery,
    ]);

    $teardown->closeConnection(proto_mut_teardown_swoole(), random_int(1000, 999999));

    expect($delivery->broadcasts)->toHaveCount(2)
        ->and($delivery->broadcasts[0]['event'])->toBe('pusher_internal:member_removed')
        ->and($delivery->broadcasts[0]['data'])->toBe(['user_id' => '12345'])
        ->and($delivery->broadcasts[1]['data'])->toBe(['user_id' => '']);
});
