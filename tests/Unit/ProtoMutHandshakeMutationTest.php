<?php

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

/**
 * What the open handler records, and what it hands to the rest of the worker.
 *
 * Three things leave this class besides the frame the client sees, and none of
 * them is visible to that client.
 *
 * The websocket log is the operator's only account of who connected: which fd,
 * on which path, under which socket id, from which address, with which host
 * header, and, for the other protocol, that it WAS the other protocol. A member
 * that stops being written does not break a connection, it makes the record
 * unable to answer the question it exists for.
 *
 * The connection context is the request as the application will later see it
 * inside a channel-auth callback. Headers and cookies are how a connection is
 * attributed to a user at all, so a context that arrives empty is an
 * authorization decision made on missing evidence.
 *
 * And the operator log's handshake dedupe: identical failures are reported
 * once, so something has to say when the outage ended, or the next one is
 * silent.
 */
class ProtoMutHandshakeLogger extends RuntimeLogger
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

class ProtoMutHandshakeOperatorLog extends OperatorLog
{
    /** @var list<string> */
    public array $lines = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }
}

class ProtoMutHandshakeDelivery extends Delivery
{
    /** @var list<array> */
    public array $pushed = [];

    /** @var list<string> */
    public array $errors = [];

    public function __construct()
    {
    }

    public function push(SwooleServer $server, int $fd, array $payload): void
    {
        $this->pushed[] = $payload;
    }

    public function pushPusherError(SwooleServer $server, int $fd, string $code, string $message): void
    {
        $this->errors[] = $code;
    }
}

/** A channel manager whose connect() fails the way a Redis outage would. */
class ProtoMutHandshakeBrokenChannels extends ChannelManager
{
    public function connect(int $fd, string $socketId, array $context = []): void
    {
        throw new \RuntimeException('the registry is unreachable');
    }
}

class ProtoMutHandshakeServer extends SwooleServer
{
    /** @var list<int> */
    public array $disconnected = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        $this->disconnected[] = $code;

        return true;
    }
}

/** The handshake under test, with the log and the operator log recorded. */
function proto_mut_handshake(array $overrides = []): array
{
    $parts = [
        'logger' => $overrides['logger'] ?? ProtoMutHandshakeLogger::make(),
        'operator' => $overrides['operator'] ?? ProtoMutHandshakeOperatorLog::make(),
        'delivery' => new ProtoMutHandshakeDelivery(),
        'channels' => $overrides['channels'] ?? app(ChannelManager::class),
        'diagnostics' => new DiagnosticSockets(),
    ];

    $parts['handshake'] = new Handshake(
        $parts['channels'],
        app(ConnectionRegistry::class),
        app(ConnectionGrants::class),
        $parts['diagnostics'],
        app(PusherApp::class),
        $parts['delivery'],
        $parts['logger'],
        $parts['operator'],
    );

    return $parts;
}

/** A Swoole upgrade request, with only the members a test cares about set. */
function proto_mut_hs_request(int $fd, string $path, array $server = [], ?array $header = ['host' => 'localhost'], ?array $cookie = []): Request
{
    $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $request->fd = $fd;
    $request->header = $header;
    $request->cookie = $cookie;
    $request->server = ['request_uri' => $path] + $server;

    return $request;
}

/** The fields of the first entry with this action, or null if there is none. */
function proto_mut_hs_entry(ProtoMutHandshakeLogger $logger, string $action): ?array
{
    foreach ($logger->entries as $entry) {
        if ($entry['action'] === $action) {
            return $entry['fields'];
        }
    }

    return null;
}

test('a pusher connection that opens is recorded with everything needed to find it again', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $parts = proto_mut_handshake();
    $fd = random_int(1000, 999999);

    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        $fd,
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3'],
        ['host' => 'orders.example'],
    ));

    // The socket id is the name every other node in the fleet knows this
    // connection by, so an entry without it cannot be joined to anything.
    $socketId = $parts['channels']->socketIdFor($fd);

    expect($socketId)->not->toBeNull()
        ->and(proto_mut_hs_entry($parts['logger'], 'open'))->toBe([
            'fd' => $fd,
            'path' => '/app/proto-mut-key',
            'socket' => $socketId,
            'host' => 'orders.example',
            'ip' => '10.1.2.3',
        ]);
});

test('the connection context carries the headers, cookies and address the request arrived with', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $parts = proto_mut_handshake();
    $fd = random_int(1000, 999999);

    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        $fd,
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3'],
        ['host' => 'orders.example', 'authorization' => 'Bearer proto-mut'],
        ['laravel_session' => 'proto-mut-session'],
    ));

    // This is the request as a channel-auth callback will see it. An empty
    // context is not a missing log line: it is the application deciding who
    // this connection belongs to with the session cookie and the authorization
    // header removed.
    expect($parts['channels']->connectionContext($fd))->toBe([
        'headers' => ['host' => 'orders.example', 'authorization' => 'Bearer proto-mut'],
        'cookies' => ['laravel_session' => 'proto-mut-session'],
        'remote_addr' => '10.1.2.3',
    ]);
});

test('a request that carries no headers or cookies still produces a context of the right shape', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $parts = proto_mut_handshake();
    $fd = random_int(1000, 999999);

    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        $fd,
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3'],
        header: null,
        cookie: null,
    ));

    // Both members are read as arrays downstream. Passing a null through would
    // move the failure into whichever callback touched it first, which is a
    // failure inside the application rather than at the door.
    expect($parts['channels']->connectionContext($fd))->toBe([
        'headers' => [],
        'cookies' => [],
        'remote_addr' => '10.1.2.3',
    ]);
});

test('an address and a host that are not strings are still recorded as strings', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $parts = proto_mut_handshake();
    $fd = random_int(1000, 999999);

    // Both of these are strings coming out of Swoole. They are stated as
    // strings anyway because the log line is read by machines: a field whose
    // type depends on what a client sent is a field nothing can index on, and
    // the context member is handed to application code that treats it as one.
    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        $fd,
        '/app/proto-mut-key',
        ['remote_addr' => 3232235777],
        ['host' => 8080],
    ));

    expect(proto_mut_hs_entry($parts['logger'], 'open')['ip'])->toBe('3232235777')
        ->and(proto_mut_hs_entry($parts['logger'], 'open')['host'])->toBe('8080')
        ->and($parts['channels']->connectionContext($fd)['remote_addr'])->toBe('3232235777');
});

test('a request with no host header is recorded under the server name, and only then under localhost', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $named = proto_mut_handshake();
    $named['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        random_int(1000, 999999),
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3', 'server_name' => 'orders.internal'],
        header: [],
    ));

    $bare = proto_mut_handshake();
    $bare['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        random_int(1000, 999999),
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3'],
        header: [],
    ));

    // Three sources in a deliberate order: what the client asked for, what this
    // server calls itself, and a last resort. A vhost that answers several
    // names is told apart by the first alone.
    expect(proto_mut_hs_entry($named['logger'], 'open')['host'])->toBe('orders.internal')
        ->and(proto_mut_hs_entry($bare['logger'], 'open')['host'])->toBe('localhost');
});

test('a refused connection is recorded with its address and the cause of the refusal', function () {
    config()->set('lightspeed.diagnostics.enabled', false);

    $parts = proto_mut_handshake();
    $fd = random_int(1000, 999999);

    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        $fd,
        '/diagnostics',
        ['remote_addr' => '203.0.113.7'],
    ));

    $fields = proto_mut_hs_entry($parts['logger'], 'refused');

    // The address is the only thing in this entry an operator can act on: a
    // refusal from off-box is a probe, and a stream of them from one address is
    // the thing worth blocking.
    expect($fields)->toHaveKeys(['fd', 'path', 'ip', 'reason'])
        ->and($fields['fd'])->toBe($fd)
        ->and($fields['path'])->toBe('/diagnostics')
        ->and($fields['ip'])->toBe('203.0.113.7')
        ->and($fields['reason'])->toBeString()
        ->and($parts['delivery']->errors)->toBe(['invalid-path']);
});

test('a connection with no address at all is refused and recorded with an empty address', function () {
    config()->set('lightspeed.diagnostics.enabled', true);

    $parts = proto_mut_handshake();

    $parts['handshake']->openConnection(
        ProtoMutHandshakeServer::make(),
        proto_mut_hs_request(random_int(1000, 999999), '/diagnostics'),
    );

    // An address Swoole could not give us is not an address that passes the
    // loopback gate, and it must not be written into the log as anything but
    // what it was: nothing.
    expect(proto_mut_hs_entry($parts['logger'], 'refused')['ip'])->toBe('');
});

test('an address that is not a string is judged by the loopback gate rather than crashing the open handler', function () {
    config()->set('lightspeed.diagnostics.enabled', true);

    $parts = proto_mut_handshake();

    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        random_int(1000, 999999),
        '/diagnostics',
        ['remote_addr' => 3232235777],
    ));

    // The gate takes a string. An open handler that dies here does not refuse
    // the connection, it takes down the callback, and Swoole's next frame
    // arrives on a connection nobody finished deciding about.
    expect(proto_mut_hs_entry($parts['logger'], 'refused')['ip'])->toBe('3232235777')
        ->and($parts['delivery']->errors)->toBe(['invalid-path']);
});

test('an admitted diagnostic connection is recorded as the other protocol', function () {
    config()->set('lightspeed.diagnostics.enabled', true);

    $parts = proto_mut_handshake();
    $fd = random_int(1000, 999999);

    $parts['handshake']->openConnection(ProtoMutHandshakeServer::make(), proto_mut_hs_request(
        $fd,
        '/diagnostics',
        ['remote_addr' => '127.0.0.1'],
        ['host' => 'localhost'],
    ));

    // `protocol` is what separates this entry from a Pusher open in the same
    // log. Without it the two are indistinguishable, and the one that carries
    // no channel authorization is the one worth being able to grep for.
    expect(proto_mut_hs_entry($parts['logger'], 'open'))->toBe([
        'fd' => $fd,
        'path' => '/diagnostics',
        'host' => 'localhost',
        'ip' => '127.0.0.1',
        'protocol' => 'diagnostic',
    ]);
});

test('a handshake that could not complete tells the client so, and closes with the retry code', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $parts = proto_mut_handshake(['channels' => new ProtoMutHandshakeBrokenChannels()]);
    $swoole = ProtoMutHandshakeServer::make();

    $parts['handshake']->openConnection($swoole, proto_mut_hs_request(
        random_int(1000, 999999),
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3'],
    ));

    // A client that receives no frame sits in `connecting` forever, so the one
    // thing this path must not do is go quiet. 4100 is the Pusher close code
    // that means "reconnect after a backoff", which is the correct advice for a
    // dependency outage: 4000-4099 tells the client not to come back at all,
    // and this connection failed for a reason that has nothing to do with it.
    expect($parts['delivery']->errors)->toBe(['handshake-failed'])
        ->and($swoole->disconnected)->toBe([4100]);
});

test('a handshake outage that recovers is reported again the next time it happens', function () {
    config()->set('lightspeed.reverb_compat.app_key', 'proto-mut-key');

    $operator = ProtoMutHandshakeOperatorLog::make();
    $broken = proto_mut_handshake([
        'operator' => $operator,
        'channels' => new ProtoMutHandshakeBrokenChannels(),
    ]);
    $working = proto_mut_handshake(['operator' => $operator]);

    $request = static fn () => proto_mut_hs_request(
        random_int(1000, 999999),
        '/app/proto-mut-key',
        ['remote_addr' => '10.1.2.3'],
    );

    $broken['handshake']->openConnection(ProtoMutHandshakeServer::make(), $request());
    $broken['handshake']->openConnection(ProtoMutHandshakeServer::make(), $request());

    // Reported once: an outage that fails every handshake would otherwise write
    // a line per connection attempt.
    expect($operator->lines)->toHaveCount(1);

    $working['handshake']->openConnection(ProtoMutHandshakeServer::make(), $request());

    $broken['handshake']->openConnection(ProtoMutHandshakeServer::make(), $request());

    // And the recovery is what re-arms the report. Without it the dedupe is
    // permanent, so the SECOND outage of a server's life is silent, which is
    // the one nobody is watching for.
    expect($operator->lines)->toHaveCount(2);
});
