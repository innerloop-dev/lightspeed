<?php

/**
 * The operator log distinguishes WHY a diagnostic handshake was refused.
 *
 * Three different situations end in the same refusal: the operator never
 * opted in, the peer is not on the allow list, and a proxy is in the path.
 * The first is routine, the second may be a misconfigured probe, and the
 * third can be a routing mistake exposing the diagnostic port. An operator
 * reading `reason => diagnostics-disabled` for all three cannot tell a
 * quiet default from a misrouted proxy, so each cause carries its own label.
 */

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Protocol\Handshake;
use Lightspeed\Protocol\PusherApp;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\OperatorLog;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\Http\Request;
use Swoole\WebSocket\Server as SwooleServer;

class RefusalReasonSwooleServer extends SwooleServer
{
    /** @var list<int> */
    public array $disconnected = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return false;
    }

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        $this->disconnected[] = $code;

        return true;
    }
}

class HandshakeRefusalRuntimeLogger extends RuntimeLogger
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

function refusalReasonFor(string $remoteAddress, array $headers = []): ?string
{
    $logger = HandshakeRefusalRuntimeLogger::make();

    $handshake = new Handshake(
        app(ChannelManager::class),
        app(ConnectionRegistry::class),
        app(ConnectionGrants::class),
        app(DiagnosticSockets::class),
        app(PusherApp::class),
        app(Delivery::class),
        $logger,
        app(OperatorLog::class),
    );

    $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $request->fd = 7;
    $request->header = array_merge(['host' => 'localhost'], $headers);
    $request->server = [
        'request_uri' => '/diagnostics',
        'remote_addr' => $remoteAddress,
    ];

    $handshake->openConnection(RefusalReasonSwooleServer::make(), $request);

    foreach ($logger->entries as $entry) {
        if ($entry['action'] === 'refused') {
            return $entry['fields']['reason'] ?? null;
        }
    }

    return null;
}

test('each of the three refusal causes logs its own reason', function () {
    // Cause 1: the operator never opted in.
    config()->set('lightspeed.diagnostics.enabled', false);
    $disabled = refusalReasonFor('127.0.0.1');

    // Cause 2: opted in, but the peer is not on the allow list.
    config()->set('lightspeed.diagnostics.enabled', true);
    $offBox = refusalReasonFor('203.0.113.7');

    // Cause 3: opted in, loopback peer, but a proxy announced itself.
    $proxied = refusalReasonFor('127.0.0.1', ['x-forwarded-for' => '203.0.113.7']);

    expect($disabled)->not->toBeNull()
        ->and($offBox)->not->toBeNull()
        ->and($proxied)->not->toBeNull();

    // The point of the test: three causes, three distinct labels. Against the
    // pre-fix code every branch logs 'diagnostics-disabled' and this fails.
    expect(count(array_unique([$disabled, $offBox, $proxied])))->toBe(3);
});

test('an admitted diagnostic peer still logs an open, not a refusal', function () {
    // The positive control: a gate that refused everyone would pass the
    // distinctness test trivially if the labels were derived per call site.
    config()->set('lightspeed.diagnostics.enabled', true);

    expect(refusalReasonFor('127.0.0.1'))->toBeNull();
});
