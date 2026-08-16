<?php

namespace Lightspeed\Logging;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Lightspeed\Workers\WorkerContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lightweight stdout logger for Lightspeed HTTP and realtime runtime events.
 *
 * The logger is intentionally config-gated so probes and production debugging
 * can turn on detailed traces without forcing package consumers to adopt a
 * framework-specific logging channel.
 */
class RuntimeLogger
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly WorkerContext $workerContext,
    ) {
    }

    /** Register request-finished logging against the host application's event bus. */
    public function bootHttpLogging(callable $listen): void
    {
        $listen(RequestHandled::class, function (RequestHandled $event): void {
            if (!$this->httpEnabled()) {
                return;
            }

            $startedAt = $event->request->attributes->get('_lightspeed_started_at');
            $requestId = $event->request->attributes->get('_lightspeed_request_id');

            if (!is_float($startedAt) && !is_int($startedAt)) {
                $startedAt = null;
            }

            $this->logHttpAccess(
                request: $event->request,
                response: $event->response,
                requestId: is_string($requestId) ? $requestId : null,
                startedAt: $startedAt,
            );
        });
    }

    /** Whether handled HTTP requests should be logged. */
    public function httpEnabled(): bool
    {
        return (bool) $this->config->get('lightspeed.logging.http', false);
    }

    /** Whether websocket lifecycle and message events should be logged. */
    public function websocketEnabled(): bool
    {
        return (bool) $this->config->get('lightspeed.logging.websocket', false);
    }

    /** Whether payload snippets should be included with log lines. */
    public function payloadsEnabled(): bool
    {
        return (bool) $this->config->get('lightspeed.logging.payloads', false);
    }

    /** Maximum width for payload snippets that appear in log output. */
    public function payloadLimit(): int
    {
        return max(80, (int) $this->config->get('lightspeed.logging.payload_limit', 600));
    }

    /** Emit one HTTP access log line from a real framework request/response pair. */
    public function logHttpAccess(
        Request $request,
        Response $response,
        ?string $requestId = null,
        float|int|null $startedAt = null,
        array $extra = [],
    ): void {
        if (!$this->httpEnabled()) {
            return;
        }

        $fields = [
            'req' => $requestId,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'dur_ms' => $startedAt !== null ? $this->durationMs($startedAt) : null,
            'host' => $request->getHost(),
            'ip' => $request->ip(),
            'route' => $request->route()?->uri(),
        ];

        $this->emit('http', array_merge($fields, $extra));
    }

    /** Emit an HTTP-style log line for synthetic responses served by the runtime itself. */
    public function logSyntheticHttp(
        string $method,
        string $path,
        int $status,
        ?string $host = null,
        ?string $remoteAddr = null,
        float|int|null $startedAt = null,
        ?string $requestId = null,
        array $extra = [],
    ): void {
        if (!$this->httpEnabled()) {
            return;
        }

        $fields = [
            'req' => $requestId,
            'method' => strtoupper($method),
            'path' => $path,
            'status' => $status,
            'dur_ms' => $startedAt !== null ? $this->durationMs($startedAt) : null,
            'host' => $host,
            'ip' => $remoteAddr,
        ];

        $this->emit('http', array_merge($fields, $extra));
    }

    /** Emit one websocket runtime event line. */
    public function logWebsocket(string $action, array $fields = []): void
    {
        if (!$this->websocketEnabled()) {
            return;
        }

        $this->emit('ws', array_merge(['action' => $action], $fields));
    }

    /** Encode a compact payload snippet when payload logging is enabled. */
    public function maybePayloadSnippet(mixed $payload): ?string
    {
        if (!$this->payloadsEnabled()) {
            return null;
        }

        $encoded = null;

        if (is_string($payload)) {
            $encoded = $payload;
        } elseif ($payload !== null) {
            try {
                $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $encoded = '['.get_debug_type($payload).']';
            }
        }

        if ($encoded === null || $encoded === '') {
            return null;
        }

        $encoded = preg_replace('/\s+/', ' ', $encoded) ?? $encoded;

        return mb_strimwidth($encoded, 0, $this->payloadLimit(), '...');
    }

    private function emit(string $channel, array $fields): void
    {
        $identity = $this->workerContext->currentIdentity($channel);

        $parts = [
            now()->format('Y-m-d H:i:s.v'),
            "[lightspeed][$channel]",
        ];

        if ($identity['worker_id'] !== null) {
            $parts[] = "worker={$identity['worker_id']}";
        }

        $parts[] = "pid={$identity['pid']}";

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = "{$key}=".$this->stringify($value);
        }

        fwrite(STDOUT, implode(' ', $parts).PHP_EOL);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            try {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return '"[array]"';
            }
        }

        if (!is_string($value)) {
            return '"['.get_debug_type($value).']"';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '"[string]"';
    }

    private function durationMs(float|int $startedAt): string
    {
        return number_format((microtime(true) - (float) $startedAt) * 1000, 2, '.', '');
    }
}
