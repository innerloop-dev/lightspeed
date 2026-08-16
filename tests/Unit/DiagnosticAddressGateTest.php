<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;
use Lightspeed\Presence\PresenceStore;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: the diagnostic protocol cannot be
 * reached from off-box.
 *
 * SECURITY.md says so, and the docblock on Diagnostics\DiagnosticSockets says
 * so twice. It is the whole reason that protocol is allowed to exist: it
 * subscribes to any channel without running one `Broadcast::channel` rule,
 * whispers, and injects broadcasts across the cluster, on an unauthenticated
 * socket. The address restriction and the forwarding-header check are the two
 * things standing between that and an anonymous client.
 *
 * BOTH WERE UNTESTED. Replacing
 *
 *     if (!in_array($remoteAddress, $allowed, true)) {
 *
 * with `if (false)` left the entire suite green. So did deleting the
 * forwarding-header loop. The suite tested the DISABLED case, which is the one
 * that is true of a default deployment and therefore the one that proves the
 * least: an operator who enables diagnostics to run the relay probe, exactly as
 * docs/PRODUCTION.md instructs, is relying on precisely the two checks nothing
 * was watching.
 *
 * These tests run with diagnostics ENABLED throughout, because a refusal
 * observed while the feature is off is not evidence about the address gate.
 */
beforeEach(function () {
    config()->set('lightspeed.diagnostics.enabled', true);
    config()->set('lightspeed.diagnostics.allow_from', ['127.0.0.1', '::1']);
});

/** Records pushes and disconnects, in place of a real websocket. */
class AddressGateSwooleServer extends SwooleServer
{
    /** @var list<array> decoded frames, in the order they were pushed */
    public array $pushed = [];

    /** @var list<int> fds this server was asked to disconnect */
    public array $disconnected = [];

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

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        $this->disconnected[] = $fd;

        return true;
    }
}

/** An application worker that never boots one; nothing here reaches it. */
class AddressGateOctaneWorker extends Lightspeed\Http\OctaneWorker
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

/** A Server whose collaborators are the container's, composed as serve() does. */
function addressGateServer(): Server
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
        'httpWorker' => new AddressGateOctaneWorker(),
    ];

    foreach ($collaborators as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($server, $value);
    }

    driveLightspeed($server, 'compose', []);

    // These tests drive the callbacks directly, so nothing has run the
    // workerStart that a real process would. Say so, or every handshake here is
    // refused by the readiness gate rather than judged on its merits.
    markLightspeedWorkerReady($server);

    return $server;
}

/** An upgrade request for the diagnostic path, from a given peer. */
function addressGateRequest(int $fd, string $remoteAddress, array $headers = []): Request
{
    $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $request->fd = $fd;
    $request->header = array_merge(['host' => 'localhost'], $headers);
    $request->server = [
        'request_uri' => '/',
        'remote_addr' => $remoteAddress,
    ];

    return $request;
}

// ---------------------------------------------------------------------------
// The gate itself.
// ---------------------------------------------------------------------------

test('the address gate refuses a peer that is not on the allow list', function () {
    // The mutation this test exists for: `if (false)` in place of the
    // in_array() refusal. A public peer is what that mutation admits.
    expect(app(DiagnosticSockets::class)->refusalReason('203.0.113.7'))->not->toBeNull();
});

test('the address gate refuses every off-box shape, not just a public one', function () {
    $gate = app(DiagnosticSockets::class);

    // A private-range neighbour is the realistic one: same VPC, same subnet,
    // no proxy in the way, and no more entitled to an unauthenticated protocol
    // than the public internet is.
    expect($gate->refusalReason('10.0.0.4'))->not->toBeNull()
        ->and($gate->refusalReason('192.168.1.20'))->not->toBeNull()
        ->and($gate->refusalReason('172.16.0.9'))->not->toBeNull()
        // The host's own routable address is still not loopback. A packet that
        // arrived on it came in over the network.
        ->and($gate->refusalReason('198.51.100.10'))->not->toBeNull()
        // Not a substring match, not a prefix match, not a numeric coercion:
        // 127.0.0.1 is on the list and these are not.
        ->and($gate->refusalReason('127.0.0.10'))->not->toBeNull()
        ->and($gate->refusalReason('1127.0.0.1'))->not->toBeNull()
        ->and($gate->refusalReason(''))->not->toBeNull();
});

test('the address gate admits loopback, so the refusals above are about the address', function () {
    // The positive control. Every assertion above passes on a refusalReason()
    // that returns a reason unconditionally, which would be a diagnostic
    // protocol that no longer exists rather than one that is guarded.
    $gate = app(DiagnosticSockets::class);

    expect($gate->refusalReason('127.0.0.1'))->toBeNull()
        ->and($gate->refusalReason('::1'))->toBeNull();
});

test('the allow list is the configured one, not a hard-coded pair', function () {
    // An operator who moves the probe to a unix-socket sidecar or a second
    // loopback alias configures it here, and a gate that ignored the setting
    // would be refusing exactly the caller it was told to admit.
    config()->set('lightspeed.diagnostics.allow_from', ['127.0.0.2']);

    $gate = app(DiagnosticSockets::class);

    expect($gate->refusalReason('127.0.0.2'))->toBeNull()
        ->and($gate->refusalReason('127.0.0.1'))->not->toBeNull();
});

test('the address gate refuses a loopback peer that arrived through a proxy', function () {
    // The other half, and the reason a loopback address alone is not evidence
    // of a local caller: a reverse proxy connects from 127.0.0.1 for every
    // request it relays, including the ones from the public internet. A
    // forwarding header is the proxy announcing itself, and a genuinely local
    // probe never sends one.
    $gate = app(DiagnosticSockets::class);

    expect($gate->refusalReason('127.0.0.1', ['x-forwarded-for' => '203.0.113.7']))->not->toBeNull()
        ->and($gate->refusalReason('127.0.0.1', ['x-real-ip' => '203.0.113.7']))->not->toBeNull()
        ->and($gate->refusalReason('127.0.0.1', ['forwarded' => 'for=203.0.113.7']))->not->toBeNull()
        ->and($gate->refusalReason('127.0.0.1', ['x-forwarded-host' => 'realtime.example.com']))->not->toBeNull();
});

test('an ordinary header does not refuse a local probe', function () {
    // The positive control for the header loop. Without it, refusing on the
    // presence of ANY header would satisfy every assertion above and make the
    // diagnostic protocol unreachable from the box it is meant to be run on.
    expect(app(DiagnosticSockets::class)->refusalReason('127.0.0.1', [
        'host' => 'localhost',
        'user-agent' => 'lightspeed-relay-probe',
        'sec-websocket-key' => 'x3JJHMbDL1EzLkh9GBhXDw==',
    ]))->toBeNull();
});

test('an empty forwarding header still counts as a proxy announcing itself', function () {
    // `?? null` and not `?: null`. A proxy that appends to an existing
    // X-Forwarded-For can emit an empty one, and "a proxy is in the path" is
    // the fact being read, not "the proxy named someone".
    expect(app(DiagnosticSockets::class)->refusalReason('127.0.0.1', ['x-forwarded-for' => '']))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// The gate as the handshake actually consults it.
//
// Everything above is a pure function. These are the tests that prove the
// server ASKS: a gate that refuses correctly and is never called refuses
// nothing at all.
// ---------------------------------------------------------------------------

test('a remote peer is disconnected instead of being admitted to the diagnostic protocol', function () {
    $swoole = AddressGateSwooleServer::make();
    $server = addressGateServer();
    $fd = random_int(1000, 999999);

    driveLightspeed($server, 'handleOpen', [$swoole, addressGateRequest($fd, '203.0.113.7')]);

    // Three separate things, because each of them alone can be true of a
    // server that is still holed. The socket must be closed; it must never see
    // the diagnostic greeting, which is the frame that tells a client the
    // protocol is there at all; and it must not be recorded as eligible, which
    // is what every later frame on this connection is judged against.
    expect($swoole->disconnected)->toContain($fd)
        ->and(array_column($swoole->pushed, 'type'))->not->toContain('hello')
        ->and(driveLightspeed($server, 'allows', [$fd]))->not->toBeNull();
});

test('a loopback peer is admitted, so the refusal above is about the address', function () {
    // The positive control for the handshake path. Without it, a handshake
    // that refused every diagnostic upgrade would pass the test above while
    // having removed the feature rather than guarded it.
    $swoole = AddressGateSwooleServer::make();
    $server = addressGateServer();
    $fd = random_int(1000, 999999);

    driveLightspeed($server, 'handleOpen', [$swoole, addressGateRequest($fd, '127.0.0.1')]);

    expect($swoole->disconnected)->not->toContain($fd)
        ->and(array_column($swoole->pushed, 'type'))->toContain('hello')
        ->and(driveLightspeed($server, 'allows', [$fd]))->toBeTrue();
});

test('a proxied loopback peer is disconnected by the handshake', function () {
    $swoole = AddressGateSwooleServer::make();
    $server = addressGateServer();
    $fd = random_int(1000, 999999);

    driveLightspeed($server, 'handleOpen', [
        $swoole,
        addressGateRequest($fd, '127.0.0.1', ['x-forwarded-for' => '203.0.113.7']),
    ]);

    expect($swoole->disconnected)->toContain($fd)
        ->and(array_column($swoole->pushed, 'type'))->not->toContain('hello')
        ->and(driveLightspeed($server, 'allows', [$fd]))->not->toBeNull();
});

test('a refused remote peer cannot speak the diagnostic protocol afterwards', function () {
    // The consequence the whole gate exists to prevent, asserted as a
    // consequence rather than as a flag. `subscribe` on this protocol runs no
    // channel authorization whatsoever, so if a refused connection can still
    // reach it, the address check bought nothing.
    $swoole = AddressGateSwooleServer::make();
    $server = addressGateServer();
    $fd = random_int(1000, 999999);
    $channel = 'private-address-gate-'.bin2hex(random_bytes(6));

    driveLightspeed($server, 'handleOpen', [$swoole, addressGateRequest($fd, '203.0.113.7')]);

    $frame = new Frame();
    $frame->fd = $fd;
    $frame->opcode = WEBSOCKET_OPCODE_TEXT;
    $frame->data = json_encode(['type' => 'subscribe', 'channel' => $channel]);

    driveLightspeed($server, 'handleMessage', [$swoole, $frame]);

    expect(array_column($swoole->pushed, 'type'))->not->toContain('subscribed')
        ->and(app(ChannelManager::class)->isSubscribed($fd, $channel))->not->toBeNull();
});
