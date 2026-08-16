<?php

namespace Lightspeed\Http;

use Lightspeed\Protocol\PusherPaths;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerHealth;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * The HTTP surface of a Lightspeed process: which of the four destinations a
 * plain HTTP request belongs to, and what it is answered with.
 *
 * One process serves the host application and the realtime surface side by
 * side, which means every request that is not a websocket upgrade arrives
 * here. This class owns that decision and every response Lightspeed itself
 * writes (the health JSON, the 404 on the realtime port, the 503 while the
 * application worker is still booting) plus the synthetic access log line for
 * each of them.
 *
 * It deliberately does NOT own the websocket surface (Server keeps the frame
 * protocols and the diagnostic gate), the application (Http\OctaneWorker boots
 * and runs it), or what a publish request means (Http\PublishEndpoint parses,
 * verifies and fans it out, and returns a status and body for this class to
 * write).
 *
 *   /healthz                 -> synthetic health JSON (realtime probe target)
 *   /apps/{id}/events        -> signed Pusher publish endpoint
 *   :http_port/*             -> Octane, i.e. the host application's routes
 *   :realtime_port/* (other) -> 404, so a broken deploy is visible to monitors
 */
class RequestRouter
{
    public function __construct(
        private readonly OctaneWorker $httpWorker,
        private readonly PublishEndpoint $publishEndpoint,
        private readonly RuntimeLogger $runtimeLogger,
        private readonly WorkerHealth $workerHealth,
    ) {
    }

    /**
     * Route one request.
     *
     * @param  int   $httpPort  The port the host application is served on.
     * @param  bool  $samePort  True when application and realtime share a port,
     *                          in which case every unclaimed path is the
     *                          application's and nothing can 404 here.
     */
    public function handle(Request $request, Response $response, int $httpPort, bool $samePort): void
    {
        $startedAt = microtime(true);
        $requestId = $this->makeRequestId();
        $path = $request->server['request_uri'] ?? '/';

        if ($path === '/healthz') {
            $this->health($request, $response, $path, $startedAt, $requestId);
            return;
        }

        if (PusherPaths::isEventsPath($path)) {
            $this->handlePublishRequest($request, $response, $path, $startedAt, $requestId);
            return;
        }

        $serverPort = (int) ($request->server['server_port'] ?? 0);
        if ($samePort || $serverPort === $httpPort) {
            $this->handleApplicationRequest($request, $response, $requestId, $startedAt);
            return;
        }

        // Reached only on the realtime port, and only for paths that are
        // neither the health probe nor the publish endpoint: application
        // routes are served above, either because both surfaces share a
        // port or because the request arrived on the app HTTP port. The
        // realtime surface has no other public paths, so answering 404
        // here is what lets an HTTP monitor tell a healthy deploy from a
        // broken one.
        $this->json($response, 404, [
            'ok' => false,
            'error' => 'Not found.',
        ]);
        $this->logSynthetic($request, $path, 404, $startedAt, $requestId);
    }

    /**
     * Answer the realtime health probe, honestly.
     *
     * THIS USED TO BE AN UNCONDITIONAL 200, and that is the failure the whole
     * of Workers\WorkerHealth exists for. docs/PRODUCTION.md tells operators to
     * point a load balancer at this path, so a worker whose start-up threw
     * halfway through, and which therefore accepted every websocket connection
     * and delivered no broadcast at all, was answering the one question that
     * could have taken it out of rotation with "yes, send me traffic".
     *
     * A health endpoint is a claim about whether this process can do its job.
     * The only 200 it may return is one it has earned; everything else is 503,
     * which is what a load balancer reads as "drain me" and a human reads as
     * "look here".
     *
     * Still ahead of every other decision, and still on either port: a probe
     * that cannot reach the endpoint learns nothing, least of all on a node
     * that is broken.
     */
    private function health(
        Request $request,
        Response $response,
        string $path,
        float $startedAt,
        string $requestId,
    ): void {
        $ready = $this->workerHealth->isReady();
        $status = $ready ? 200 : 503;

        $body = [
            'ok' => $ready,
            'server' => 'lightspeed',
            'time' => now()->toIso8601String(),
        ];

        if (!$ready) {
            // The cause, when there is one more specific than "not yet". A red
            // check with no reason costs an operator the first ten minutes of
            // every incident.
            $body['error'] = $this->workerHealth->failure()
                ?? 'Lightspeed worker has not finished starting.';
        }

        $this->json($response, $status, $body);
        $this->logSynthetic($request, $path, $status, $startedAt, $requestId);
    }

    private function handleApplicationRequest(Request $request, Response $response, string $requestId, float $startedAt): void
    {
        if (!$this->httpWorker->isBooted()) {
            $this->json($response, 503, [
                'ok' => false,
                'error' => 'Lightspeed HTTP worker is not ready.',
            ]);
            $this->logSynthetic(
                $request,
                (string) ($request->server['request_uri'] ?? '/'),
                503,
                $startedAt,
                $requestId,
            );
            return;
        }

        $this->httpWorker->handle($request, $response, $requestId, $startedAt);
    }

    private function handlePublishRequest(
        Request $request,
        Response $response,
        string $path,
        float $startedAt,
        string $requestId,
    ): void {
        $result = $this->publishEndpoint->handle(
            method: (string) ($request->server['request_method'] ?? 'GET'),
            path: $path,
            rawBody: (string) $request->rawContent(),
            post: is_array($request->post ?? null) ? $request->post : [],
            query: is_array($request->get ?? null) ? $request->get : [],
        );

        $this->json($response, $result['status'], $result['body']);
        $this->logSynthetic(
            $request,
            $path,
            $result['status'],
            $startedAt,
            $requestId,
            $result['log'],
            defaultMethod: 'POST',
        );
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, int $status, array $payload): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Log a response Lightspeed wrote itself.
     *
     * Requests handed to the application are logged by the application's own
     * request-handled listener instead, so nothing here double-counts them.
     *
     * @param array<string, mixed> $extra
     */
    private function logSynthetic(
        Request $request,
        string $path,
        int $status,
        float $startedAt,
        string $requestId,
        array $extra = [],
        string $defaultMethod = 'GET',
    ): void {
        $this->runtimeLogger->logSyntheticHttp(
            method: (string) ($request->server['request_method'] ?? $defaultMethod),
            path: $path,
            status: $status,
            host: $this->requestHost($request),
            remoteAddr: (string) ($request->server['remote_addr'] ?? '127.0.0.1'),
            startedAt: $startedAt,
            requestId: $requestId,
            extra: $extra,
        );
    }

    private function requestHost(Request $request): string
    {
        return (string) ($request->header['host'] ?? $request->server['server_name'] ?? 'localhost');
    }

    private function makeRequestId(): string
    {
        return bin2hex(random_bytes(4));
    }
}
