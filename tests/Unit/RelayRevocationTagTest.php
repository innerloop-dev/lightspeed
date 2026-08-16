<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * What this worker will and will not accept as a revocation from a peer.
 *
 * `Lightspeed::revoke()` served by one process reaches every other process as a
 * SIGNED control entry on the relay stream, and the listener registered by
 * Auth\RevocationDrops is what turns that entry into dropped subscriptions on
 * this worker. The signature proves the entry came from something holding the
 * app secret; it says nothing about the entry's SHAPE, because the shape is
 * whatever survived a JSON round trip.
 *
 * TWO WAYS TO GET THAT READ WRONG, AND THE PACKAGE HAS SHIPPED ONE OF THEM.
 * The reverted build guarded the tag with `is_string()` alone. JSON preserves
 * the tag "7" as a string when it is a VALUE, so that guard looked correct and
 * passed review; it silently dropped every numeric tag on the path where the
 * tag had been a map KEY, and `revoke('7')` became a permanent no-op. Found on
 * a live server, which is the expensive way to find it.
 *
 * The other way is the mutation this file exists for. Replacing the whole
 * check with
 *
 *     $this->dropConnectionsCarrying((string) $tag, (int) $revokedAt);
 *
 * left the entire suite green. That mutant casts whatever arrived, so a control
 * entry whose tag is `true` revokes the tag `"1"`, one whose tag is a float
 * revokes its decimal spelling, and one whose tag is an array reaches a string
 * cast on an array. A revocation is a command that force-unsubscribes every
 * connection carrying a tag, so a cast that invents a tag out of a value that
 * was never one is a denial of service aimed at whichever of the
 * application's users happens to be tagged with the spelling.
 *
 * Both halves are pinned here, because a guard tested only from the refusing
 * side is a guard that can be replaced with `return` and a guard tested only
 * from the accepting side is the reverted build.
 */

/** Records pushes, in place of a real websocket. */
class RelayRevocationSwooleServer extends SwooleServer
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

    public function push(int $fd, Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }
}

/** An application worker that never boots one; nothing here reaches it. */
class RelayRevocationOctaneWorker extends Lightspeed\Http\OctaneWorker
{
    public function __construct()
    {
    }

    public function isBooted(): bool
    {
        return true;
    }

    public function runTask(callable $task): mixed
    {
        return $task();
    }
}

/** A composed Server, with the revocation listeners armed on a fake socket server. */
function relayRevocationServer(RelayRevocationSwooleServer $swoole): Server
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
        'httpWorker' => new RelayRevocationOctaneWorker(),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);
    driveLightspeed($server, 'bootRevocationListeners', [$swoole]);

    return $server;
}

/** The listener the relay would call when a peer's revocation entry arrives. */
function relayRevocationListener(): callable
{
    $listeners = new ReflectionProperty(RedisRelay::class, 'controlListeners');
    $listeners->setAccessible(true);

    return $listeners->getValue(app(RedisRelay::class))[RevocationLog::CONTROL_TYPE];
}

/**
 * A connection subscribed to a private channel, holding a grant with one tag.
 *
 * @return array{0: int, 1: string}
 */
function connectionCarryingTag(string $tag): array
{
    $fd = random_int(1000, 999999);
    $channel = 'private-relay-revocation-'.bin2hex(random_bytes(6));
    $issuedAt = (int) (microtime(true) * 1_000_000);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel);
    app(ConnectionGrants::class)->mint($fd, $channel, new Grant([$tag], [], $issuedAt, $issuedAt + 300_000_000));

    return [$fd, $channel];
}

/** The instant a peer would stamp on a revocation issued now. */
function revokedNow(): int
{
    return (int) (microtime(true) * 1_000_000) + 1_000_000;
}

// ---------------------------------------------------------------------------
// The accepting half: the tags a revocation MUST reach.
// ---------------------------------------------------------------------------

test('a peer revocation of a plain string tag drops the connections carrying it', function () {
    // The positive control for everything below. Every refusal in this file
    // passes against a listener that does nothing at all.
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('project:7');

    (relayRevocationListener())(['tag' => 'project:7', 'revoked_at' => revokedNow()]);

    expect(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeFalse()
        ->and(array_column($swoole->pushed, 'event'))->toContain('lightspeed:stale');
});

test('a numeric tag revokes whether JSON handed it back as a string or an int', function () {
    // The live bug, both spellings. A tag that is a VALUE survives as "7"; the
    // same tag reached this listener as an int 7 on the path where it had been
    // a map key, and an `is_string()` guard made revoke('7') a permanent no-op
    // for it. Neither spelling may be dropped on the floor.
    foreach (['7', 7] as $wireTag) {
        $swoole = RelayRevocationSwooleServer::make();
        relayRevocationServer($swoole);
        [$fd, $channel] = connectionCarryingTag('7');

        (relayRevocationListener())(['tag' => $wireTag, 'revoked_at' => revokedNow()]);

        expect(app(ChannelManager::class)->isSubscribed($fd, $channel))
            ->toBeFalse('a tag arriving as '.gettype($wireTag).' must still revoke');
    }
});

test('the revocation instant may arrive as a numeric string', function () {
    // Same round trip, same reasoning: the instant is a large integer and JSON
    // encoders are entitled to hand it back as a string.
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('project:7');

    (relayRevocationListener())(['tag' => 'project:7', 'revoked_at' => (string) revokedNow()]);

    expect(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The refusing half: the values that are not tags.
// ---------------------------------------------------------------------------

test('a boolean tag does not revoke the tag it would have been cast to', function () {
    // The mutation, exactly. `(string) true` is "1", so under the mutant a
    // control entry carrying `tag: true` force-unsubscribes every connection
    // tagged "1", which on an application that tags by user id is one specific
    // user, chosen by a value that was never a tag.
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('1');

    (relayRevocationListener())(['tag' => true, 'revoked_at' => revokedNow()]);

    expect(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue()
        ->and($swoole->pushed)->toBe([]);
});

test('a float tag does not revoke its decimal spelling', function () {
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('7.5');

    (relayRevocationListener())(['tag' => 7.5, 'revoked_at' => revokedNow()]);

    expect(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
});

test('a tag that is not a scalar at all is ignored rather than cast', function () {
    // `(string)` on an array is a warning and the word "Array", so the mutant
    // does not merely mis-revoke here: it reaches a cast that PHP will not do
    // cleanly, inside a listener called from the relay poll.
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('project:7');

    $thrown = null;

    try {
        (relayRevocationListener())(['tag' => ['project:7'], 'revoked_at' => revokedNow()]);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull()
        ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
});

test('an entry with no tag, or an empty one, revokes nothing', function () {
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('project:7');

    $listener = relayRevocationListener();

    foreach ([[], ['revoked_at' => revokedNow()], ['tag' => '', 'revoked_at' => revokedNow()], ['tag' => null, 'revoked_at' => revokedNow()]] as $payload) {
        $thrown = null;

        try {
            $listener($payload);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeNull()
            ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
    }
});

test('an entry whose instant is not a number revokes nothing', function () {
    // `(int) "whenever"` is 0, which happens to match no grant, so the visible
    // outcome is the same either way today. It is pinned because "the cast
    // produces a harmless number" is a property of the current grant clock
    // rather than of the check, and the check is what is supposed to be true.
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('project:7');

    $listener = relayRevocationListener();

    foreach (['whenever', null, ['now'], true] as $revokedAt) {
        $thrown = null;

        try {
            $listener(['tag' => 'project:7', 'revoked_at' => $revokedAt]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeNull()
            ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
    }
});

test('a revocation older than the grant it would drop leaves it alone', function () {
    // Not a shape check but the same listener's other judgement, and the one
    // that stops a revoke turning into an endless subscribe-and-drop loop: a
    // connection that re-subscribed AFTER the revocation holds a grant the
    // application has just approved again.
    $swoole = RelayRevocationSwooleServer::make();
    relayRevocationServer($swoole);
    [$fd, $channel] = connectionCarryingTag('project:7');

    (relayRevocationListener())([
        'tag' => 'project:7',
        'revoked_at' => (int) (microtime(true) * 1_000_000) - 600_000_000,
    ]);

    expect(app(ChannelManager::class)->isSubscribed($fd, $channel))->toBeTrue();
});
