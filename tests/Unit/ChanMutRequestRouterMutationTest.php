<?php

use Lightspeed\Http\OctaneWorker;
use Lightspeed\Http\PublishEndpoint;
use Lightspeed\Http\RequestRouter;
use Lightspeed\Logging\RuntimeLogger;
use Lightspeed\Workers\WorkerHealth;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * The parts of the HTTP surface that are read by something other than a human.
 *
 * The port comparison decides whether a request reaches the host application or
 * a 404, and it compares a value Swoole hands over as text against one this
 * package holds as a number. The synthetic log line is the only record of every
 * response Lightspeed writes itself, and each of its fields is the field an
 * operator greps by: the method, the client address, the virtual host. And the
 * publish endpoint is handed a form body that only this class can give it.
 *
 * None of that is asserted by the responses these tests already check, which is
 * why it is asserted here: a router that answers correctly while logging the
 * wrong client, or that quietly stops forwarding the parsed form body, looks
 * exactly like a working one.
 */

/** A Swoole request whose `server` array the test writes field by field. */
final class ChanMutRouterRequest extends Request
{
    public string $body = '';

    public function rawContent(): string|false
    {
        return $this->body;
    }

    /** @param array<string, mixed> $server */
    public static function make(array $server, array $header = ['host' => 'example.test']): self
    {
        $request = new self();
        $request->server = $server;
        $request->header = $header;
        $request->get = [];
        $request->post = [];

        return $request;
    }
}

/** A Swoole response that keeps what was written instead of sending it. */
final class ChanMutRouterResponse extends Response
{
    public ?int $sentStatus = null;

    public ?string $sentBody = null;

    public function status(int|string $statusCode, string $reason = ''): bool
    {
        $this->sentStatus = (int) $statusCode;

        return true;
    }

    public function header(string $key, mixed $value, bool $format = true): bool
    {
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

/** Stands in for the application worker and records what reached it. */
final class ChanMutRouterWorker extends OctaneWorker
{
    /** @var list<string> paths handed to the application */
    public array $handled = [];

    public function __construct()
    {
    }

    public function isBooted(): bool
    {
        return true;
    }

    public function handle(Request $request, Response $response, string $requestId, float $startedAt): void
    {
        $this->handled[] = (string) ($request->server['request_uri'] ?? '');
    }
}

/** Records the five arguments the router builds a publish call out of. */
final class ChanMutRouterPublishEndpoint extends PublishEndpoint
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function handle(string $method, string $path, string $rawBody, array $post, array $query): array
    {
        $this->calls[] = compact('method', 'path', 'rawBody', 'post', 'query');

        return ['status' => 200, 'body' => ['ok' => true], 'log' => []];
    }
}

/** Records the synthetic log lines the router writes for its own responses. */
final class ChanMutRouterLogger extends RuntimeLogger
{
    /** @var list<array<string, mixed>> */
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

function chanMutRouter(
    ChanMutRouterWorker $worker,
    ChanMutRouterLogger $logger,
    ?ChanMutRouterPublishEndpoint $publish = null,
): RequestRouter {
    $health = new WorkerHealth();
    $health->markReady();

    return new RequestRouter($worker, $publish ?? new ChanMutRouterPublishEndpoint(), $logger, $health);
}

test('a server port that arrived as text still matches the port the application is served on', function () {
    // Swoole fills `server` from the request, and everything in a request is
    // text. Compared without the cast, an identical port fails to match its own
    // number and every application route on that port answers 404 instead: the
    // whole host application hidden behind the realtime surface's fallback.
    $worker = new ChanMutRouterWorker();
    $response = new ChanMutRouterResponse();

    chanMutRouter($worker, new ChanMutRouterLogger())->handle(
        ChanMutRouterRequest::make([
            'request_uri' => '/dashboard',
            'request_method' => 'GET',
            'server_port' => '8000',
            'remote_addr' => '10.0.0.9',
        ]),
        $response,
        httpPort: 8000,
        samePort: false,
    );

    expect($worker->handled)->toBe(['/dashboard'])
        ->and($response->sentStatus)->toBeNull();
});

test('a request that names no port at all is compared as port zero', function () {
    // The fallback is a number, and which number it is decides what a portless
    // request is mistaken for. Pinning it is the only way a change to it is
    // visible, because every other route through this method agrees.
    $worker = new ChanMutRouterWorker();

    chanMutRouter($worker, new ChanMutRouterLogger())->handle(
        ChanMutRouterRequest::make(['request_uri' => '/dashboard', 'request_method' => 'GET']),
        new ChanMutRouterResponse(),
        httpPort: 0,
        samePort: false,
    );

    expect($worker->handled)->toBe(['/dashboard']);

    // And with a real port configured, the same portless request is not the
    // application's.
    $other = new ChanMutRouterWorker();
    $response = new ChanMutRouterResponse();

    chanMutRouter($other, new ChanMutRouterLogger())->handle(
        ChanMutRouterRequest::make(['request_uri' => '/dashboard', 'request_method' => 'GET']),
        $response,
        httpPort: 8000,
        samePort: false,
    );

    expect($other->handled)->toBe([])
        ->and($response->sentStatus)->toBe(404);
});

test('the health answer carries the instant it was given', function () {
    // A probe result with no time on it cannot be told from a cached one, which
    // is the failure mode this endpoint exists to make visible.
    $response = new ChanMutRouterResponse();

    chanMutRouter(new ChanMutRouterWorker(), new ChanMutRouterLogger())->handle(
        ChanMutRouterRequest::make([
            'request_uri' => '/healthz',
            'request_method' => 'GET',
            'server_port' => 9000,
            'remote_addr' => '10.0.0.9',
        ]),
        $response,
        httpPort: 8000,
        samePort: false,
    );

    $body = $response->decodedBody();

    expect(array_keys($body))->toBe(['ok', 'server', 'time'])
        ->and(strtotime((string) $body['time']))->not->toBeFalse();
});

test('the parsed form body reaches the publish endpoint', function () {
    // A Pusher SDK may post the event as a form rather than as a JSON body, and
    // Swoole has already parsed it by the time the request arrives. Dropping it
    // here leaves the endpoint verifying a signature over an empty body, so a
    // correctly signed publish is answered 401.
    $publish = new ChanMutRouterPublishEndpoint();
    $request = ChanMutRouterRequest::make([
        'request_uri' => '/apps/test-app/events',
        'request_method' => 'POST',
        'server_port' => 9000,
        'remote_addr' => '10.0.0.9',
    ]);
    $request->post = ['name' => 'resource.updated', 'channels' => ['private-resource.1']];
    $request->get = ['auth_signature' => 'abc'];
    $request->body = 'name=resource.updated';

    chanMutRouter(new ChanMutRouterWorker(), new ChanMutRouterLogger(), $publish)
        ->handle($request, new ChanMutRouterResponse(), httpPort: 8000, samePort: false);

    expect($publish->calls)->toHaveCount(1)
        ->and($publish->calls[0]['post'])->toBe(['name' => 'resource.updated', 'channels' => ['private-resource.1']])
        ->and($publish->calls[0]['query'])->toBe(['auth_signature' => 'abc'])
        ->and($publish->calls[0]['rawBody'])->toBe('name=resource.updated')
        ->and($publish->calls[0]['method'])->toBe('POST');
});

test('a response Lightspeed writes itself is pretty printed', function () {
    // These bodies are read by whoever is holding the incident: a curl against
    // /healthz on a node that is refusing traffic, or the 404 that says the
    // realtime port is answering but the deploy is wrong. One long line is the
    // format that makes both of those harder to read at the moment they matter.
    $response = new ChanMutRouterResponse();

    chanMutRouter(new ChanMutRouterWorker(), new ChanMutRouterLogger())->handle(
        ChanMutRouterRequest::make([
            'request_uri' => '/nothing-here',
            'request_method' => 'GET',
            'server_port' => 9000,
            'remote_addr' => '10.0.0.9',
        ]),
        $response,
        httpPort: 8000,
        samePort: false,
    );

    expect($response->sentBody)->toBe(json_encode(
        ['ok' => false, 'error' => 'Not found.'],
        JSON_PRETTY_PRINT,
    ));
});

test('the synthetic log line names the request that caused it, not the defaults', function () {
    // Every field here is the field an operator filters on. The method
    // separates a probe from a publish, the address says which client, and the
    // host says which of the sites behind this port. Each has a fallback for
    // the request that carries nothing, and a fallback that answers when the
    // request DID carry something logs a plausible line about the wrong
    // request.
    $logger = new ChanMutRouterLogger();

    chanMutRouter(new ChanMutRouterWorker(), $logger)->handle(
        ChanMutRouterRequest::make([
            'request_uri' => '/healthz',
            'request_method' => 'HEAD',
            'server_port' => 9000,
            'remote_addr' => '203.0.113.7',
            'server_name' => 'internal.invalid',
        ]),
        new ChanMutRouterResponse(),
        httpPort: 8000,
        samePort: false,
    );

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0]['method'])->toBe('HEAD')
        ->and($logger->lines[0]['remoteAddr'])->toBe('203.0.113.7')
        ->and($logger->lines[0]['host'])->toBe('example.test')
        ->and($logger->lines[0]['path'])->toBe('/healthz');

    // A probe from a load balancer often sends no Host header at all, and the
    // node's own name is the next best answer. Skipping straight to
    // "localhost" logs every one of those lines against a host that does not
    // exist, on exactly the requests an operator is reading during a drain.
    $nameless = new ChanMutRouterLogger();

    chanMutRouter(new ChanMutRouterWorker(), $nameless)->handle(
        ChanMutRouterRequest::make([
            'request_uri' => '/healthz',
            'request_method' => 'GET',
            'server_port' => 9000,
            'remote_addr' => '203.0.113.7',
            'server_name' => 'node-7.internal',
        ], header: []),
        new ChanMutRouterResponse(),
        httpPort: 8000,
        samePort: false,
    );

    expect($nameless->lines[0]['host'])->toBe('node-7.internal');
});

test('terminating a worker that never booted is a no-op, and repeatable', function () {
    // workerStop runs on every process, including one whose boot threw before
    // an Octane worker existed. Terminating that one has to be silent: an error
    // raised inside a Swoole stop handler is reported against the shutdown, not
    // against the boot that actually failed.
    $worker = app(OctaneWorker::class);

    $worker->terminate();
    $worker->terminate();

    expect($worker->isBooted())->toBeFalse();
});
