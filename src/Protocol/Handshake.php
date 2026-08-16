<?php

namespace Lightspeed\Protocol;

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Diagnostics\DiagnosticSockets;
use Lightspeed\Connections\ConnectionRegistry;
use Lightspeed\Logging\OperatorLog;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\Http\Request;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * Decide which protocol a newly opened socket belongs to, and answer it.
 *
 * Two protocols share the websocket port, and they are never mixed:
 *
 *   open /app/{key}  -> Pusher connection  -> only pusher:* / pusher_internal:* frames
 *   open (any other) -> diagnostic (loopback + opt-in only)
 *                                          -> only {"type": ...} frames
 *
 * The decision is made HERE and recorded, once, for the connection's whole
 * life: see Diagnostics\DiagnosticSockets. Nothing downstream re-derives it
 * from the shape of a frame.
 *
 * Every exit from this class is explicit. A client that receives no frame sits
 * in "connecting" forever, so a connection that cannot be served is always
 * told why and closed with a Pusher close code the client library understands.
 */
class Handshake
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ConnectionRegistry $connectionRegistry,
        private readonly ConnectionGrants $connectionGrants,
        private readonly DiagnosticSockets $diagnosticSockets,
        private readonly PusherApp $pusherApp,
        private readonly Delivery $delivery,
        private readonly RuntimeLogger $runtimeLogger,
        private readonly OperatorLog $operatorLog,
    ) {
    }

    public function openConnection(SwooleServer $server, Request $request): void
    {
        $fd = $request->fd;
        $path = $request->server['request_uri'] ?? '/';

        if (PusherPaths::isWebsocketPath($path, $this->pusherApp->pathPrefix())) {
            $appKey = PusherPaths::appKeyFromWebsocketPath($path, $this->pusherApp->pathPrefix());

            if ($appKey !== $this->pusherApp->key()) {
                $this->delivery->pushPusherError($server, $fd, 'invalid-app-key', 'Invalid realtime app key.');
                $server->disconnect($fd, 4001, 'Invalid app key');
                return;
            }

            $socketId = SocketId::generate($fd);

            // The handshake touches shared state (the connection registry
            // is Redis-backed), so it can fail for reasons that have
            // nothing to do with this client. A client that never receives
            // a frame here sits in "connecting" forever, so an unusable
            // connection is always closed explicitly instead.
            try {
                // Swoole reuses fd numbers, and a grant is the one piece of
                // per-fd state whose leaking across a reuse would GRANT
                // access rather than merely confuse. The close handler
                // clears it; this is the belt to that pair of braces.
                $this->connectionGrants->forgetConnection($fd);

                $this->channels->connect($fd, $socketId, $this->buildConnectionContext($request));
                $this->connectionRegistry->remember($socketId);
            } catch (\Throwable $e) {
                $this->operatorLog->reportHandshakeFailure($e, $path);
                $this->delivery->pushPusherError($server, $fd, 'handshake-failed', 'Realtime handshake failed.');
                // 4100 tells a Pusher client to reconnect after a backoff,
                // which is the correct advice for a transient dependency
                // outage: the next attempt may well succeed.
                $server->disconnect($fd, 4100, 'Handshake failed');
                return;
            }

            $this->operatorLog->handshakeSucceeded();

            $this->runtimeLogger->logWebsocket('open', [
                'fd' => $fd,
                'path' => $path,
                'socket' => $socketId,
                'host' => $this->requestHost($request),
                'ip' => (string) ($request->server['remote_addr'] ?? '127.0.0.1'),
            ]);

            $this->delivery->push($server, $fd, Frames::connectionEstablished(
                $socketId,
                $this->pusherApp->activityTimeout(),
            ));
            return;
        }

        // Anything that is not the Pusher path can only be the diagnostic
        // protocol, which carries no channel authorization. It is refused
        // unless explicitly enabled AND the peer is on the loopback
        // interface, so it can never be reached from off-box.
        $remoteAddress = (string) ($request->server['remote_addr'] ?? '');

        $refusalReason = $this->diagnosticSockets->refusalReason($remoteAddress, is_array($request->header ?? null) ? $request->header : []);

        if ($refusalReason !== null) {
            $this->runtimeLogger->logWebsocket('refused', [
                'fd' => $fd,
                'path' => $path,
                'ip' => $remoteAddress,
                'reason' => $refusalReason,
            ]);

            $this->delivery->pushPusherError($server, $fd, 'invalid-path', 'Unknown websocket endpoint.');
            $server->disconnect($fd, 4004, 'Unknown websocket endpoint');
            return;
        }

        $this->diagnosticSockets->admit($fd);

        $this->runtimeLogger->logWebsocket('open', [
            'fd' => $fd,
            'path' => $path,
            'host' => $this->requestHost($request),
            'ip' => $remoteAddress,
            'protocol' => 'diagnostic',
        ]);

        $this->delivery->push($server, $fd, Frames::diagnosticHello($fd, $path));
    }

    private function buildConnectionContext(Request $request): array
    {
        return [
            'headers' => is_array($request->header ?? null) ? $request->header : [],
            'cookies' => is_array($request->cookie ?? null) ? $request->cookie : [],
            'remote_addr' => (string) ($request->server['remote_addr'] ?? '127.0.0.1'),
        ];
    }

    private function requestHost(Request $request): string
    {
        return (string) ($request->header['host'] ?? $request->server['server_name'] ?? 'localhost');
    }
}
