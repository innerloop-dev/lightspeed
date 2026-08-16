<?php

use Lightspeed\Http\OctaneWorker;
use Lightspeed\Http\PublishEndpoint;
use Lightspeed\Http\RequestRouter;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerHealth;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * The four-way HTTP decision, and the responses Lightspeed writes itself.
 *
 * RequestRouter was extracted from Server so this decision could be exercised
 * without a listening socket, and it is the only thing standing between a
 * public port and the host application: a wrong branch here either hides the
 * application behind a 404 or exposes the realtime port as if it served one.
 * The port check is the subtle half, an unclaimed path is the application's
 * when both surfaces share a port, and a 404 when they do not.
 *
 * Swoole's request and response objects are subclassed rather than mocked.
 * They are ordinary PHP objects here: the router only ever reads the public
 * `server`, `header`, `get` and `post` arrays plus rawContent(), and only ever
 * writes through status()/header()/end(), so overriding those is enough to see
 * exactly what would go on the wire. Nothing in this file opens a port.
 */

/** A Swoole request with the fields the router reads, and nothing else. */
final class FakeSwooleRequest extends Request
{
    public string $body = '';

    public function rawContent(): string|false
    {
        return $this->body;
    }

    public static function make(string $path, int $serverPort = 8000, string $method = 'GET'): self
    {
        $request = new self();
        $request->server = [
            'request_uri' => $path,
            'request_method' => $method,
            'server_port' => $serverPort,
            'remote_addr' => '10.0.0.9',
        ];
        $request->header = ['host' => 'example.test'];
        $request->get = [];
        $request->post = [];

        return $request;
    }
}

/** A Swoole response that records what was written instead of sending it. */
final class FakeSwooleResponse extends Response
{
    public ?int $sentStatus = null;

    /** @var array<string, mixed> */
    public array $sentHeaders = [];

    public ?string $sentBody = null;

    public function status(int|string $statusCode, string $reason = ''): bool
    {
        $this->sentStatus = (int) $statusCode;

        return true;
    }

    public function header(string $key, mixed $value, bool $format = true): bool
    {
        $this->sentHeaders[$key] = $value;

        return true;
    }

    public function end(mixed $content = null): bool
    {
        $this->sentBody = (string) $content;

        return true;
    }

    /** @return array<string, mixed> */
    public function decodedBody(): array
    {
        return json_decode((string) $this->sentBody, true, 512, JSON_THROW_ON_ERROR);
    }
}

/** Stands in for the application worker: records what was handed to it. */
final class FakeOctaneWorker extends OctaneWorker
{
    /** @var array<int, array{requestId: string, path: string}> */
    public array $handled = [];

    public function __construct(private bool $booted = true)
    {
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function handle(Request $request, Response $response, string $requestId, float $startedAt): void
    {
        $this->handled[] = [
            'requestId' => $requestId,
            'path' => (string) ($request->server['request_uri'] ?? ''),
        ];
    }
}

/** Returns a canned publish result and records the request it was given. */
final class FakePublishEndpoint extends PublishEndpoint
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function __construct(private array $result = [
        'status' => 200,
        'body' => ['ok' => true],
        'log' => ['event' => 'demo'],
    ]) {
    }

    public function handle(string $method, string $path, string $rawBody, array $post, array $query): array
    {
        $this->calls[] = compact('method', 'path', 'rawBody', 'post', 'query');

        return $this->result;
    }
}

/** Records synthetic log lines so "who logs this response" can be asserted. */
final class RecordingRuntimeLogger extends RuntimeLogger
{
    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public function __construct()
    {
    }

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
        $this->lines[] = compact('method', 'path', 'status', 'host', 'remoteAddr', 'requestId', 'extra');
    }
}

/**
 * A router for a worker that finished starting.
 *
 * The health flag is passed READY here because every test in this file is about
 * the four-way routing decision, and a worker that never started routes nothing.
 * The unready case is tests/Unit/WorkerStartHealthTest.php's subject.
 */
function requestRouterFor(
    ?FakeOctaneWorker $worker = null,
    ?FakePublishEndpoint $publishEndpoint = null,
    ?RecordingRuntimeLogger $logger = null,
    ?WorkerHealth $health = null,
): RequestRouter {
    if ($health === null) {
        $health = new WorkerHealth();
        $health->markReady();
    }

    return new RequestRouter(
        $worker ?? new FakeOctaneWorker(),
        $publishEndpoint ?? new FakePublishEndpoint(),
        $logger ?? new RecordingRuntimeLogger(),
        $health,
    );
}

test('the health endpoint answers on either port, ahead of every other decision', function () {
    foreach ([8000, 9000] as $port) {
        $response = new FakeSwooleResponse();
        $logger = new RecordingRuntimeLogger();
        $worker = new FakeOctaneWorker();

        requestRouterFor($worker, null, $logger)
            ->handle(FakeSwooleRequest::make('/healthz', $port), $response, httpPort: 8000, samePort: false);

        expect($response->sentStatus)->toBe(200)
            ->and($response->sentHeaders['Content-Type'])->toBe('application/json')
            ->and($response->decodedBody()['ok'])->toBeTrue()
            ->and($response->decodedBody()['server'])->toBe('lightspeed')
            ->and($worker->handled)->toBe([]);

        // A probe target Lightspeed answered itself is logged here, because the
        // application's own request-handled listener never sees it.
        expect($logger->lines)->toHaveCount(1)
            ->and($logger->lines[0]['status'])->toBe(200)
            ->and($logger->lines[0]['path'])->toBe('/healthz')
            ->and($logger->lines[0]['host'])->toBe('example.test');
    }
});

test('a publish request is answered with the endpoint status and body it returned', function () {
    $publishEndpoint = new FakePublishEndpoint([
        'status' => 401,
        'body' => ['error' => 'Invalid publish signature.'],
        'log' => ['app' => 'demo'],
    ]);
    $logger = new RecordingRuntimeLogger();
    $worker = new FakeOctaneWorker();
    $response = new FakeSwooleResponse();

    $request = FakeSwooleRequest::make('/apps/demo/events', 9000, 'POST');
    $request->body = '{"name":"e"}';
    $request->get = ['auth_signature' => 'nope'];

    requestRouterFor($worker, $publishEndpoint, $logger)
        ->handle($request, $response, httpPort: 8000, samePort: false);

    expect($response->sentStatus)->toBe(401)
        ->and($response->decodedBody())->toBe(['error' => 'Invalid publish signature.'])
        ->and($worker->handled)->toBe([]);

    // The endpoint sees the raw request, not a Swoole object: that separation
    // is what makes both classes testable on their own.
    expect($publishEndpoint->calls)->toHaveCount(1)
        ->and($publishEndpoint->calls[0]['method'])->toBe('POST')
        ->and($publishEndpoint->calls[0]['path'])->toBe('/apps/demo/events')
        ->and($publishEndpoint->calls[0]['rawBody'])->toBe('{"name":"e"}')
        ->and($publishEndpoint->calls[0]['query'])->toBe(['auth_signature' => 'nope']);

    // The endpoint's own log fields are merged into the router's line.
    expect($logger->lines[0]['status'])->toBe(401)
        ->and($logger->lines[0]['extra'])->toBe(['app' => 'demo']);
});

test('an application path on the application port reaches the worker and is not logged twice', function () {
    $worker = new FakeOctaneWorker();
    $logger = new RecordingRuntimeLogger();
    $response = new FakeSwooleResponse();

    requestRouterFor($worker, null, $logger)
        ->handle(FakeSwooleRequest::make('/dashboard', 8000), $response, httpPort: 8000, samePort: false);

    expect($worker->handled)->toHaveCount(1)
        ->and($worker->handled[0]['path'])->toBe('/dashboard')
        ->and($worker->handled[0]['requestId'])->toMatch('/^[0-9a-f]{8}$/');

    // The router wrote no response of its own, and logged nothing: the
    // application answers and the request-handled listener logs it.
    expect($response->sentStatus)->toBeNull()
        ->and($response->sentBody)->toBeNull()
        ->and($logger->lines)->toBe([]);
});

test('an unclaimed path on the realtime port is a 404, so a broken deploy is visible', function () {
    $worker = new FakeOctaneWorker();
    $logger = new RecordingRuntimeLogger();
    $response = new FakeSwooleResponse();

    requestRouterFor($worker, null, $logger)
        ->handle(FakeSwooleRequest::make('/dashboard', 9000), $response, httpPort: 8000, samePort: false);

    expect($response->sentStatus)->toBe(404)
        ->and($response->sentHeaders['Content-Type'])->toBe('application/json')
        ->and($response->decodedBody())->toBe(['ok' => false, 'error' => 'Not found.'])
        ->and($worker->handled)->toBe([])
        ->and($logger->lines[0]['status'])->toBe(404);
});

test('sharing one port turns the same unclaimed path into an application request', function () {
    $worker = new FakeOctaneWorker();
    $response = new FakeSwooleResponse();

    // Same path, same non-application port as the 404 above, only samePort
    // differs, and nothing can 404 here because every path is the app's.
    requestRouterFor($worker)
        ->handle(FakeSwooleRequest::make('/dashboard', 9000), $response, httpPort: 8000, samePort: true);

    expect($worker->handled)->toHaveCount(1)
        ->and($response->sentStatus)->toBeNull();
});

test('an application request that arrives before the worker is booted is a 503', function () {
    $worker = new FakeOctaneWorker(booted: false);
    $logger = new RecordingRuntimeLogger();
    $response = new FakeSwooleResponse();

    requestRouterFor($worker, null, $logger)
        ->handle(FakeSwooleRequest::make('/dashboard', 8000), $response, httpPort: 8000, samePort: false);

    expect($response->sentStatus)->toBe(503)
        ->and($response->decodedBody())->toBe([
            'ok' => false,
            'error' => 'Lightspeed HTTP worker is not ready.',
        ])
        ->and($worker->handled)->toBe([])
        ->and($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0]['status'])->toBe(503)
        ->and($logger->lines[0]['path'])->toBe('/dashboard');
});

test('every request gets its own request id', function () {
    $worker = new FakeOctaneWorker();
    $router = requestRouterFor($worker);

    $router->handle(FakeSwooleRequest::make('/a', 8000), new FakeSwooleResponse(), 8000, false);
    $router->handle(FakeSwooleRequest::make('/b', 8000), new FakeSwooleResponse(), 8000, false);

    expect($worker->handled[0]['requestId'])->not->toBe($worker->handled[1]['requestId']);
});
