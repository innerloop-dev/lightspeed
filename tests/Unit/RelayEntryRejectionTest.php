<?php

use Lightspeed\Channels\ChannelManager;
use Lightspeed\Relay\RedisRelay;
use Lightspeed\Workers\WorkerContext;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The claim this file exists to defend: a relay entry this worker cannot make
 * sense of costs that entry, and nothing else.
 *
 * The relay's poll loop is a Swoole timer callback with `enable_coroutine` off,
 * so an exception that escapes it is fatal to the worker rather than logged.
 * The loop guards each delivery with a try, which is what keeps ONE bad entry
 * from ending the poll; deliverEntry() then guards the CONTENT, which is what
 * keeps a bad entry from reaching the fan-out at all.
 *
 * THE CONTENT GUARD WAS UNTESTED. Deleting
 *
 *     if (!is_array($channels) || !is_array($message)) {
 *         return;
 *     }
 *
 * left the entire suite green. What that mutant does is hand a scalar to
 * deliverLocal(array $channels, array $baseMessage), which is a TypeError: not
 * a wrong delivery but a thrown one, caught by the loop's try and turned into a
 * log line. So the visible cost is one warning per bad entry, forever, on every
 * worker, for an entry that will be re-read by every worker in the fleet.
 *
 * The entries here are the shapes a real stream produces. This is a SHARED
 * Redis stream: anything in the deployment that can write to Redis can put a
 * row in it, an older or newer build of the package can write a shape this one
 * does not know, and a truncated or half-written value is a normal outcome of a
 * client that died mid-XADD. "Nothing will ever write a bad entry" is not a
 * property of a shared stream.
 *
 * Nothing here touches Redis. deliverEntry() is handed the entry directly, in
 * the shape RedisStreams::read() returns, so what is under test is the decision
 * rather than the transport.
 */

/** Records what was pushed to a socket, in place of a real websocket. */
class RelayEntrySwooleServer extends SwooleServer
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

/** A relay with a socket attached, ready to be handed stream entries. */
function relayWithAttachedServer(RelayEntrySwooleServer $swoole): RedisRelay
{
    $relay = app(RedisRelay::class);

    $server = new ReflectionProperty(RedisRelay::class, 'server');
    $server->setAccessible(true);
    $server->setValue($relay, $swoole);

    return $relay;
}

/**
 * One stream entry, in the shape RedisStreams::read() hands back.
 *
 * The origin key is deliberately not this process's, because a worker never
 * replays its own publishes and an entry that looked like one would be dropped
 * before anything below it was read.
 *
 * @param array<string, string|null> $fields
 */
function relayStreamEntry(array $fields): array
{
    return ['1700000000000-0', array_merge([
        'origin_process_key' => 'some-other-instance:worker:9',
    ], $fields)];
}

function deliverRelayEntry(RedisRelay $relay, array $entry): void
{
    $deliver = new ReflectionMethod(RedisRelay::class, 'deliverEntry');
    $deliver->setAccessible(true);
    $deliver->invoke($relay, $entry);
}

test('a well-formed relay entry is delivered to this worker sockets', function () {
    // The positive control, first, because every assertion below is about
    // something NOT being delivered and they all pass against a relay that
    // delivers nothing at all.
    $swoole = RelayEntrySwooleServer::make();
    $relay = relayWithAttachedServer($swoole);
    $channel = 'relay-entry-'.bin2hex(random_bytes(6));
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel);

    deliverRelayEntry($relay, relayStreamEntry([
        'channels' => json_encode([$channel]),
        'message' => json_encode(['event' => 'order.updated', 'data' => ['id' => 7]]),
        'except_socket_id' => '',
    ]));

    expect($swoole->pushed)->toHaveCount(1)
        ->and($swoole->pushed[0]['event'])->toBe('order.updated')
        ->and($swoole->pushed[0]['channel'])->toBe($channel);
});

test('a relay entry whose channels or message are not arrays is rejected, not delivered', function () {
    // The mutation this file exists for. Each of these decodes as valid JSON,
    // so the JsonException guard above does not catch them; what is wrong is
    // the TYPE, which is the thing the deleted line was reading.
    $malformed = [
        'channels is a bare string' => ['channels' => json_encode('private-orders'), 'message' => json_encode(['event' => 'x'])],
        'channels is a number' => ['channels' => '7', 'message' => json_encode(['event' => 'x'])],
        'channels is null' => ['channels' => 'null', 'message' => json_encode(['event' => 'x'])],
        'message is a bare string' => ['channels' => json_encode(['private-orders']), 'message' => json_encode('an event')],
        'message is a number' => ['channels' => json_encode(['private-orders']), 'message' => '7'],
        'message is null' => ['channels' => json_encode(['private-orders']), 'message' => 'null'],
        'message is a boolean' => ['channels' => json_encode(['private-orders']), 'message' => 'true'],
    ];

    foreach ($malformed as $why => $fields) {
        $swoole = RelayEntrySwooleServer::make();
        $relay = relayWithAttachedServer($swoole);

        $thrown = null;

        try {
            deliverRelayEntry($relay, relayStreamEntry($fields + ['except_socket_id' => '']));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // Two things, and the second is the one the mutant breaks. Nothing may
        // be delivered, AND nothing may be thrown: deliverEntry() runs inside a
        // timer whose only protection is a try that turns a throw into a log
        // line, so a type error here is one warning per bad entry, per worker,
        // for as long as the entry stays in the stream.
        expect($swoole->pushed)->toBe([], "an entry where {$why} should deliver nothing")
            ->and($thrown)->toBeNull("an entry where {$why} should not throw, it should be ignored");
    }
});

test('a relay entry whose JSON does not parse is rejected, not delivered', function () {
    $swoole = RelayEntrySwooleServer::make();
    $relay = relayWithAttachedServer($swoole);

    $thrown = null;

    try {
        deliverRelayEntry($relay, relayStreamEntry([
            'channels' => '{not json',
            'message' => json_encode(['event' => 'x']),
            'except_socket_id' => '',
        ]));
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($swoole->pushed)->toBe([])
        ->and($thrown)->toBeNull();
});

test('a relay entry with no channels and no message at all is harmless', function () {
    // A row written by something that is not this package, or by a build that
    // writes a different shape. The defaults ('[]' and '{}') make this a
    // delivery to nowhere rather than a crash, and the empty channel list is
    // dropped by deliverLocal.
    $swoole = RelayEntrySwooleServer::make();
    $relay = relayWithAttachedServer($swoole);

    $thrown = null;

    try {
        deliverRelayEntry($relay, relayStreamEntry([]));
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($swoole->pushed)->toBe([])
        ->and($thrown)->toBeNull();
});

test('a worker does not replay its own publishes back to itself', function () {
    // The guard above the content checks, and the one that makes the origin key
    // load-bearing. Without it every broadcast this worker sent would be
    // delivered to its own sockets a second time when it read the entry back.
    $swoole = RelayEntrySwooleServer::make();
    $relay = relayWithAttachedServer($swoole);
    $channel = 'relay-entry-'.bin2hex(random_bytes(6));
    $fd = random_int(1000, 999999);

    app(ChannelManager::class)->connect($fd, "{$fd}.1", []);
    app(ChannelManager::class)->subscribe($fd, $channel);

    deliverRelayEntry($relay, [
        '1700000000000-0',
        [
            'origin_process_key' => app(WorkerContext::class)->currentProcessKey(),
            'channels' => json_encode([$channel]),
            'message' => json_encode(['event' => 'order.updated', 'data' => []]),
            'except_socket_id' => '',
        ],
    ]);

    expect($swoole->pushed)->toBe([]);
});
