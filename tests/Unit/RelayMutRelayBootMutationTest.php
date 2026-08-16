<?php

use Illuminate\Support\Facades\Redis;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Relay\RedisStreams;
use Lightspeed\Workers\WorkerContext;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * What a worker's relay does when it starts, and what it puts on the wire.
 *
 * Both halves are invisible from inside one process. A poll loop that never
 * armed, armed twice, or armed at a period nobody asked for looks exactly like
 * a healthy one from the outside; so does a publish that normalized its
 * channels wrongly, until a peer worker reads the entry and delivers to nobody.
 * The stream is the contract between workers, so these tests assert the bytes
 * in it rather than the return value of the call that wrote them.
 */

/** A stream nobody else is writing to, so a test reads only what it put there. */
function relayMutStream(): string
{
    return 'lightspeed:test:relaymut:'.bin2hex(random_bytes(6));
}

class RelayMutSwooleServer extends SwooleServer
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

/** A relay pointed at one test's stream, with a socket server attached. */
function relayMutRelay(string $stream, ?RelayMutSwooleServer $swoole = null): RedisRelay
{
    config()->set('lightspeed.relay.enabled', true);
    config()->set('lightspeed.relay.broadcast_stream', $stream);

    $relay = app(RedisRelay::class);

    writeLightspeedProperty($relay, 'server', $swoole ?? RelayMutSwooleServer::make());

    return $relay;
}

/** Every entry currently on a stream, as [id, decoded fields] pairs. */
function relayMutEntries(string $stream): array
{
    $client = Redis::connection((string) config('lightspeed.relay.redis_connection', 'default'))->client();

    return array_map(
        static fn (array $entry): array => [$entry[0], RedisStreams::normalizeFields($entry[1])],
        RedisStreams::read($client, $stream, '0-0', 1000),
    );
}

/** The fields of the one entry a test expects to have been written. */
function relayMutOnlyEntry(string $stream): array
{
    $entries = relayMutEntries($stream);

    expect($entries)->toHaveCount(1);

    return $entries[0][1];
}

/** Write one entry the way a peer worker would, so the origin filter lets it through. */
function relayMutPeerControl(string $stream, string $type, array $payload): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

    RedisStreams::add(
        Redis::connection((string) config('lightspeed.relay.redis_connection', 'default'))->client(),
        $stream,
        [
            'origin_process_key' => 'a-peer-worker',
            'control' => $type,
            'payload' => $encoded,
            'signature' => hash_hmac('sha256', $type."\0".$encoded, (string) config('lightspeed.reverb_compat.app_secret')),
        ],
    );
}

/** Drop the keys this file's tests wrote, so nothing accumulates in a real Redis. */
afterEach(function () {
    foreach (Timer::list() as $timerId) {
        Timer::clear($timerId);
    }

    $stream = (string) config('lightspeed.relay.broadcast_stream');

    if (str_starts_with($stream, 'lightspeed:test:relaymut:')) {
        Redis::connection()->del($stream);
    }
});

test('booting a worker clears whatever a previous boot left running', function () {
    // A worker restart runs this path again in the same process. A second
    // timer polls the same stream twice per interval forever, and the first
    // one's id has been overwritten, so nothing can ever stop it. The failure
    // counters are per-attachment too: carrying them across a boot means the
    // new attachment starts already deep in a backoff it did not earn.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    $relay->bootWorker(RelayMutSwooleServer::make(), 0);
    $first = readLightspeedProperty($relay, 'timerId');

    writeLightspeedProperty($relay, 'readFailures', 4);

    $relay->bootWorker(RelayMutSwooleServer::make(), 0);
    $second = readLightspeedProperty($relay, 'timerId');

    expect($second)->not->toBe($first)
        ->and(Timer::exists($first))->toBeFalse()
        ->and(Timer::exists($second))->toBeTrue()
        ->and(readLightspeedProperty($relay, 'readFailures'))->toBe(0);

    $relay->shutdownWorker();

    expect(Timer::exists($second))->toBeFalse();
});

test('a booting worker starts from the tail of the stream, and a disabled one does not poll at all', function () {
    // Starting from '0-0' replays the whole retained stream into the sockets of
    // a worker that has only just come up: every client that survived the
    // restart receives the last ten thousand broadcasts again.
    //
    // And with the relay off there is nothing to poll. Arming the timer anyway
    // means a Redis round trip every 25ms, forever, on a deployment that
    // configured Redis out.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    relayMutPeerControl($stream, 'lightspeed-relaymut', ['n' => 1]);
    relayMutPeerControl($stream, 'lightspeed-relaymut', ['n' => 2]);

    $entries = relayMutEntries($stream);

    $relay->bootWorker(RelayMutSwooleServer::make(), 0);

    expect(readLightspeedProperty($relay, 'timerId'))->not->toBeNull()
        // The LAST id, not the first: the tail is where "now" is.
        ->and(readLightspeedProperty($relay, 'lastBroadcastId'))->toBe($entries[1][0]);

    $relay->shutdownWorker();

    config()->set('lightspeed.relay.enabled', false);
    $relay->bootWorker(RelayMutSwooleServer::make(), 0);

    expect(readLightspeedProperty($relay, 'timerId'))->toBeNull()
        ->and(readLightspeedProperty($relay, 'lastBroadcastId'))->toBe('0-0');
});

test('the poll runs every 25ms, floors at 10ms, and is read as a number', function () {
    // The floor is what stops a configured 0 from becoming a timer with no
    // period on the event loop that serves every socket this worker holds, and
    // an interval read as text is not an interval at all.
    $relay = relayMutRelay(relayMutStream());

    $intervals = [];

    foreach ([null, 5, 'fast', 3000] as $dial) {
        $relayConfig = config('lightspeed.relay');

        if ($dial === null) {
            unset($relayConfig['poll_interval_ms']);
        } else {
            $relayConfig['poll_interval_ms'] = $dial;
        }

        config()->set('lightspeed.relay', $relayConfig);

        $relay->bootWorker(RelayMutSwooleServer::make(), 0);
        $intervals[] = Timer::info(readLightspeedProperty($relay, 'timerId'))['interval'];
    }

    $relay->shutdownWorker();

    expect($intervals)->toBe([25, 10, 10, 3000]);
});

test('one poll of the timer really drains the stream', function () {
    // The timer body is a single call wrapped in a try, and the try is there
    // because an exception escaping a Swoole timer callback is fatal to the
    // worker. A body that swallowed the call instead of guarding it would
    // leave a relay that ticks forever and receives nothing.
    $stream = relayMutStream();
    $swoole = RelayMutSwooleServer::make();
    $relay = relayMutRelay($stream, $swoole);

    $heard = [];
    $relay->onControl('lightspeed-relaymut', function (array $payload) use (&$heard): void {
        $heard[] = $payload;
    });

    relayMutPeerControl($stream, 'lightspeed-relaymut', ['n' => 1]);

    driveLightspeed($relay, 'tick');

    expect($heard)->toBe([['n' => 1]]);
});

test('a published message reaches peer workers as a list of the channels that were strings', function () {
    // The entry is the whole of what a peer worker has to go on. A channel
    // list that arrives with a null in it, with a non-string left in, or keyed
    // rather than as a list is one that json_decode hands back as something the
    // peer's fan-out cannot use, so the message is delivered on this worker and
    // nowhere else, which is the failure mode that looks like "broadcasts work
    // in development".
    $stream = relayMutStream();
    $swoole = RelayMutSwooleServer::make();
    $relay = relayMutRelay($stream, $swoole);

    app(ChannelManager::class)->connect(1, 'socket-1');
    app(ChannelManager::class)->subscribe(1, 'private-a');

    $delivered = $relay->publishMessage(
        [123, 'private-a', null],
        ['event' => 'resource.updated', 'data' => '{}'],
    );

    $fields = relayMutOnlyEntry($stream);

    expect($delivered)->toBe(1)
        ->and($fields['channels'])->toBe('["private-a"]')
        ->and($fields['message'])->toBe('{"event":"resource.updated","data":"{}"}')
        ->and($fields['origin_process_key'])->toBe(app(WorkerContext::class)->currentProcessKey())
        // Present and empty, rather than absent: a peer reads this field
        // unconditionally, and "nobody is excluded" is a value it has to
        // carry rather than a shape it has to guess at.
        ->and($fields['except_socket_id'])->toBe('')
        ->and($swoole->pushed)->toHaveCount(1);
});

test('the socket the publisher wants excluded travels with the message', function () {
    // fds are per worker, so the exclusion can only cross as a socket id. A
    // peer that never receives it delivers the event back to the client that
    // published it, which is the double-apply every optimistic UI is written
    // to avoid.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    $relay->publishMessage(['private-a'], ['event' => 'e'], 'sock-publisher');

    expect(relayMutOnlyEntry($stream)['except_socket_id'])->toBe('sock-publisher');
});

test('a message that cannot be encoded is refused rather than published blank', function () {
    // json_encode answers unencodable input with `false`, and the entry would
    // carry that as an empty field: every peer worker then decodes an empty
    // channel list or an empty message and delivers nothing, silently, while
    // the publishing worker's own sockets got the real thing. The throw is
    // what makes it the caller's problem instead.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    $connection = (string) config('lightspeed.relay.redis_connection', 'default');

    // Resolved and cached, which is what a process that has published
    // anything at all is holding.
    Redis::connection($connection)->ping();

    expect(array_key_exists($connection, app('redis')->connections()))
        ->toBeTrue('the connection was not cached, so discarding it would prove nothing');

    expect(fn () => $relay->publishMessage(["private-\xB1\x31"], ['event' => 'e']))
        ->toThrow(JsonException::class);

    // And the handle goes with it. The relay takes the RAW client, opting out
    // of the wrapper that rebuilds one after a dropped socket, so dropping the
    // cached connection is its own substitute for that: without it, a publish
    // that failed because the socket died is followed by publishes that fail
    // on the same dead handle forever.
    expect(array_key_exists($connection, app('redis')->connections()))
        ->toBeFalse('a failed publish kept its handle, so the next publish inherits it');

    expect(fn () => $relay->publishMessage(['private-a'], ['event' => "\xB1\x31"]))
        ->toThrow(JsonException::class);

    expect(relayMutEntries($stream))->toBe([]);
});

test('a relay with no worker attached delivers to nobody and says so', function () {
    // The count is what the HTTP publish endpoint reports back to the caller.
    // A process with no socket server (an artisan command, a queue worker)
    // still publishes to peers, and claiming a local delivery it did not make
    // would be a lie the caller cannot check.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    writeLightspeedProperty($relay, 'server', null);

    expect($relay->publishMessage(['private-a'], ['event' => 'e']))->toBe(0)
        ->and(relayMutEntries($stream))->toHaveCount(1);
});

test('the relay publishes to Redis unless it is turned off', function () {
    // The default is on, and it is the whole of cross-worker delivery. A
    // default of off is a deployment where every worker serves its own sockets
    // correctly and no message ever crosses between them.
    $stream = relayMutStream();

    $relayConfig = config('lightspeed.relay');
    unset($relayConfig['enabled']);
    $relayConfig['broadcast_stream'] = $stream;
    config()->set('lightspeed.relay', $relayConfig);

    $relay = app(RedisRelay::class);
    writeLightspeedProperty($relay, 'server', RelayMutSwooleServer::make());

    $relay->publishMessage(['private-a'], ['event' => 'e']);

    expect(relayMutEntries($stream))->toHaveCount(1);
});

test('a control entry carries its type, its payload and a signature over both', function () {
    // The signature is what makes a control entry a command from this
    // application rather than from anything that can reach Redis, and the type
    // is inside it so an entry cannot be replayed as a different control. The
    // exact bytes matter because every peer recomputes them independently: a
    // change here is a fleet that stops obeying its own revocations.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    $relay->publishControl('lightspeed-relaymut', ['path' => 'a/b']);

    $fields = relayMutOnlyEntry($stream);

    expect($fields['control'])->toBe('lightspeed-relaymut')
        // Slashes unescaped, because the signature is over these exact bytes
        // and the peer signs what it reads.
        ->and($fields['payload'])->toBe('{"path":"a/b"}')
        ->and($fields['signature'])->toBe(hash_hmac('sha256', "lightspeed-relaymut\0".'{"path":"a/b"}', 'test-secret'))
        ->and($fields['origin_process_key'])->toBe(app(WorkerContext::class)->currentProcessKey());
});

test('an application with no configured secret still signs under a key both peers agree on', function () {
    // The default is the empty key, and it has to stay the empty key: a
    // placeholder here would be a shared secret nobody chose, and a fleet where
    // one worker has the placeholder and another has none obeys nothing.
    $stream = relayMutStream();

    $compat = config('lightspeed.reverb_compat');
    unset($compat['app_secret']);
    config()->set('lightspeed.reverb_compat', $compat);

    $relay = relayMutRelay($stream);
    $relay->publishControl('lightspeed-relaymut', ['n' => 1]);

    $fields = relayMutOnlyEntry($stream);

    expect($fields['signature'])->toBe(hash_hmac('sha256', "lightspeed-relaymut\0".'{"n":1}', ''));
});

test('a disabled relay reads nothing, even with a server attached', function () {
    // Both halves of the guard: no server means nothing to deliver to, and a
    // disabled relay means there is no shared stream to believe. Draining
    // anyway on a deployment that configured Redis out is a Redis round trip
    // per tick and, worse, delivery of entries this deployment never opted in
    // to receiving.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    $heard = [];
    $relay->onControl('lightspeed-relaymut', function (array $payload) use (&$heard): void {
        $heard[] = $payload;
    });

    relayMutPeerControl($stream, 'lightspeed-relaymut', ['n' => 1]);

    config()->set('lightspeed.relay.enabled', false);
    driveLightspeed($relay, 'drainBroadcasts');

    expect($heard)->toBe([]);

    // The positive control: the entry was there to be read all along.
    config()->set('lightspeed.relay.enabled', true);
    driveLightspeed($relay, 'drainBroadcasts');

    expect($heard)->toBe([['n' => 1]]);
});

test('one poll reads at most a hundred entries, and a count it cannot read does not stop the poll', function () {
    // The count bounds how much of a backlog one tick puts on the event loop.
    // It also has to survive being configured as text: falling back to no
    // limit is a slower tick, while letting the text through is a read that
    // throws, which the loop reports as a Redis outage that is not happening.
    $stream = relayMutStream();
    $relay = relayMutRelay($stream);

    // The published config sets this dial, so the default only decides for an
    // application that has not republished its config file: take it away.
    $relayConfig = config('lightspeed.relay');
    unset($relayConfig['read_count']);
    config()->set('lightspeed.relay', $relayConfig);

    $heard = 0;
    $relay->onControl('lightspeed-relaymut', function () use (&$heard): void {
        $heard++;
    });

    for ($i = 0; $i < 101; $i++) {
        relayMutPeerControl($stream, 'lightspeed-relaymut', ['n' => $i]);
    }

    driveLightspeed($relay, 'drainBroadcasts');
    expect($heard)->toBe(100);

    driveLightspeed($relay, 'drainBroadcasts');
    expect($heard)->toBe(101);

    // Read as text, and therefore as no limit at all: the whole backlog in one
    // tick, rather than a poll that reports itself as broken.
    writeLightspeedProperty($relay, 'lastBroadcastId', '0-0');
    config()->set('lightspeed.relay.read_count', 'plenty');

    driveLightspeed($relay, 'drainBroadcasts');
    expect($heard)->toBe(202);
});
