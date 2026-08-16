<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Channels\ChannelManager;
use Lightspeed\Relay\RedisRelay;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The poll loop: what it believes, what it refuses, and what it says out loud.
 *
 * This loop is a Swoole timer callback with `enable_coroutine` off, so an
 * escaping exception is fatal to the worker rather than logged, and the worker
 * is the server at the default `worker_num` of 1. That shapes every decision
 * here. One malformed entry may cost that entry and nothing else; a Redis
 * outage must be visible while it lasts without becoming forty identical log
 * lines a second per worker; and a control entry, which is a COMMAND, may only
 * be obeyed if it proves it came from this application.
 *
 * The failure paths are driven through a scripted client rather than a real
 * outage: what is under test is the reporting policy, and a real outage cannot
 * be asked to fail four times with the same message on demand.
 */

/** A phpredis handle whose reads a test writes the script for. */
class RelayMutDrainClient extends \Redis
{
    /** Message to throw from a read, or null to answer normally. */
    public ?string $failure = null;

    /** The reply a successful read hands back, in phpredis' shape. */
    public array $reply = [];

    public function __construct()
    {
    }

    public function xread(array $streams, int $count = -1, int $block = -1): \Redis|array|bool
    {
        if ($this->failure !== null) {
            throw new \RuntimeException($this->failure);
        }

        return $this->reply;
    }
}

/** Stands in for the Laravel connection wrapper, which the relay only asks for a client. */
class RelayMutDrainConnection
{
    public function __construct(private readonly RelayMutDrainClient $client)
    {
    }

    public function client(): RelayMutDrainClient
    {
        return $this->client;
    }
}

class RelayMutDrainSwoole extends SwooleServer
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

/** Put one scripted client behind every Redis call the relay makes. */
function relayMutDrainRedis(RelayMutDrainClient $client): void
{
    Redis::shouldReceive('connection')->andReturn(new RelayMutDrainConnection($client));
    Redis::shouldReceive('purge')->andReturnNull();
}

/** A relay with a socket server attached, polling its own stream. */
function relayMutDrainRelay(RelayMutDrainSwoole $swoole): RedisRelay
{
    config()->set('lightspeed.relay.enabled', true);
    config()->set('lightspeed.relay.broadcast_stream', 'lightspeed:test:relaymut-drain');

    $relay = app(RedisRelay::class);
    writeLightspeedProperty($relay, 'server', $swoole);

    return $relay;
}

/** One entry as RedisStreams::read() hands it over, written by a peer worker. */
function relayMutDrainEntry(array $fields, string $id = '5-1'): array
{
    return [$id, array_merge(['origin_process_key' => 'a-peer-worker'], $fields)];
}

/** The fields of a control entry a peer signed with the application secret. */
function relayMutDrainControl(string $type, string $payload, ?string $signature = null): array
{
    return [
        'control' => $type,
        'payload' => $payload,
        'signature' => $signature ?? hash_hmac('sha256', $type."\0".$payload, (string) config('lightspeed.reverb_compat.app_secret')),
    ];
}

/** JSON nested exactly `$levels` deep, for probing the decoder's ceiling. */
function relayMutDrainNested(int $levels): string
{
    return str_repeat('[', $levels).str_repeat(']', $levels);
}

// ---------------------------------------------------------------------------
// Reporting a Redis outage
// ---------------------------------------------------------------------------

test('a Redis outage is reported at the first failure and then on a doubling backoff', function () {
    // The poll runs on a timer: at the default 25ms cadence an outage is 40
    // identical warnings a second, per worker, for as long as it lasts. But
    // pure silence after the first is worse, because the operator's only
    // evidence that cross-worker delivery is still dead is that the warnings
    // keep coming. So: the first failure immediately, then 2, 4, 8, and never
    // the ones in between.
    Log::spy();

    $client = new RelayMutDrainClient();
    $client->failure = 'read timed out';
    relayMutDrainRedis($client);

    $relay = relayMutDrainRelay(RelayMutDrainSwoole::make());

    for ($poll = 0; $poll < 4; $poll++) {
        driveLightspeed($relay, 'drainBroadcasts');
    }

    foreach ([1, 2, 4] as $failure) {
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Lightspeed relay read failed'
                && $context === ['message' => 'read timed out', 'consecutive_failures' => $failure],
        )->once();
    }

    // Three lines for four failures: the third is the one the backoff ate.
    Log::shouldHaveReceived('warning')->times(3);

    expect(readLightspeedProperty($relay, 'readFailures'))->toBe(4);
});

test('a read that recovers says so once, and the next one says nothing', function () {
    // An operator needs the END of an outage as much as its beginning, and
    // exactly once: a relay that announced its own health on every quiet tick
    // would bury the outage it just came out of. Which is also why the
    // counters are cleared rather than left to drift.
    Log::spy();

    $client = new RelayMutDrainClient();
    $client->failure = 'read timed out';
    relayMutDrainRedis($client);

    $relay = relayMutDrainRelay(RelayMutDrainSwoole::make());

    driveLightspeed($relay, 'drainBroadcasts');

    $client->failure = null;

    driveLightspeed($relay, 'drainBroadcasts');
    driveLightspeed($relay, 'drainBroadcasts');

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context): bool => $message === 'Lightspeed relay read recovered'
            && $context === ['failed_reads' => 1],
    )->once();

    Log::shouldHaveReceived('info')->once();

    expect(readLightspeedProperty($relay, 'readFailures'))->toBe(0);
});

// ---------------------------------------------------------------------------
// What one bad entry may cost
// ---------------------------------------------------------------------------

test('an entry with no id of its own is skipped rather than obeyed', function () {
    // The id is the poll's resume point. An entry with a blank one cannot move
    // it forward, so obeying the entry means re-reading and re-obeying it on
    // every tick from then on: for a control entry, that is one command being
    // executed forty times a second forever.
    $client = new RelayMutDrainClient();
    relayMutDrainRedis($client);

    $relay = relayMutDrainRelay(RelayMutDrainSwoole::make());

    $heard = [];
    $relay->onControl('lightspeed-relaymut-drain', function (array $payload) use (&$heard): void {
        $heard[] = $payload;
    });

    // The unusable entry comes FIRST, and the batch continues past it: a read
    // returns up to a hundred entries at a time, so giving up on the rest of
    // the batch would let one unreadable entry cost this worker every
    // broadcast, presence event and revocation that shared its poll.
    $client->reply = ['lightspeed:test:relaymut-drain' => [
        '' => relayMutDrainControl('lightspeed-relaymut-drain', '{"n":0}'),
        '9-1' => relayMutDrainControl('lightspeed-relaymut-drain', '{"n":1}'),
    ]];

    driveLightspeed($relay, 'drainBroadcasts');

    expect($heard)->toBe([['n' => 1]])
        ->and(readLightspeedProperty($relay, 'lastBroadcastId'))->toBe('9-1');
});

test('an entry the fan-out cannot use costs that entry, and is named in the log', function () {
    // The loop guards each delivery because an exception escaping this timer
    // callback kills the worker. What the guard must not do is swallow: an
    // entry that cannot be delivered is either a foreign writer on the shared
    // stream or a build mismatch in the fleet, and the id is the only way to
    // go and look at it.
    Log::spy();

    $client = new RelayMutDrainClient();
    relayMutDrainRedis($client);

    $relay = relayMutDrainRelay(RelayMutDrainSwoole::make());

    // A channels list whose members are not channel names: decodable, and
    // unusable, which is a TypeError inside the fan-out.
    $client->reply = ['lightspeed:test:relaymut-drain' => [
        '5-1' => ['origin_process_key' => 'a-peer-worker', 'channels' => '[[]]', 'message' => '{}'],
        '5-2' => ['origin_process_key' => 'a-peer-worker', 'channels' => '["private-a"]', 'message' => '{"event":"e"}'],
    ]];

    driveLightspeed($relay, 'drainBroadcasts');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'Lightspeed relay could not deliver a stream entry'
            && array_keys($context) === ['entry', 'message']
            && $context['entry'] === '5-1'
            && is_string($context['message'])
            && $context['message'] !== '',
    )->once();

    // And the poll went on past it: the entry after the bad one still moved
    // the resume point.
    expect(readLightspeedProperty($relay, 'lastBroadcastId'))->toBe('5-2');
});

test('the decoder ceiling for an entry is the one every worker agrees on', function () {
    // A peer encodes what this worker decodes, so the ceiling is part of the
    // wire contract rather than a local safety margin. Moving it does not
    // break the worker that moved it: it makes one worker in the fleet accept
    // payloads its peers drop, which is a broadcast delivered to some of the
    // sockets in a channel and not the others.
    //
    // Both outcomes at the ceiling are safe, and that is the point: one level
    // under it the entry is decoded, one level over it the entry is dropped in
    // silence. Neither ends the poll.
    Log::spy();

    $client = new RelayMutDrainClient();
    relayMutDrainRedis($client);

    $swoole = RelayMutDrainSwoole::make();
    $relay = relayMutDrainRelay($swoole);

    app(ChannelManager::class)->connect(1, 'socket-1');
    app(ChannelManager::class)->subscribe(1, 'private-a');

    $client->reply = ['lightspeed:test:relaymut-drain' => [
        // Decoded, then unusable as a channel list: one logged entry.
        '5-1' => ['origin_process_key' => 'a-peer-worker', 'channels' => relayMutDrainNested(511), 'message' => '{}'],
        // Over the ceiling: refused before anything looks at it.
        '5-2' => ['origin_process_key' => 'a-peer-worker', 'channels' => relayMutDrainNested(512), 'message' => '{}'],
        // A message at the ceiling is delivered whole.
        '5-3' => ['origin_process_key' => 'a-peer-worker', 'channels' => '["private-a"]', 'message' => relayMutDrainNested(511)],
        // And one over it is not delivered at all.
        '5-4' => ['origin_process_key' => 'a-peer-worker', 'channels' => '["private-a"]', 'message' => relayMutDrainNested(512)],
    ]];

    driveLightspeed($relay, 'drainBroadcasts');

    expect($swoole->pushed)->toHaveCount(1);

    Log::shouldHaveReceived('warning')->once();
});

// ---------------------------------------------------------------------------
// One entry, handed straight to the delivery decision
// ---------------------------------------------------------------------------

test('the publisher of a message is the one socket it is not delivered to', function () {
    // A client that publishes through the HTTP endpoint has already applied
    // the change locally, so the exclusion is what stops it seeing its own
    // event twice. It travels as a socket id because fds are per worker and
    // this entry is read by every worker in the fleet.
    $swoole = RelayMutDrainSwoole::make();
    $relay = relayMutDrainRelay($swoole);

    $channels = app(ChannelManager::class);
    $channels->connect(1, 'sock-publisher');
    $channels->connect(2, 'sock-listener');
    $channels->subscribe(1, 'private-a');
    $channels->subscribe(2, 'private-a');

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry([
        'channels' => '["private-a"]',
        'message' => '{"event":"e"}',
        'except_socket_id' => 'sock-publisher',
    ])]);

    expect($swoole->pushed)->toHaveCount(1);

    // With no exclusion named, both sockets receive it: the field is honoured
    // rather than the delivery merely being lossy.
    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry([
        'channels' => '["private-a"]',
        'message' => '{"event":"e"}',
    ])]);

    expect($swoole->pushed)->toHaveCount(3);
});

test('a channel list that arrives as a JSON object is still a channel list', function () {
    // json_encode writes an object rather than an array whenever the keys are
    // not a list, so this is what a peer running a build that did not
    // re-index its channels sends. Decoding it as an object hands the fan-out
    // something it cannot iterate, and the message is delivered on the
    // publishing worker only.
    $swoole = RelayMutDrainSwoole::make();
    $relay = relayMutDrainRelay($swoole);

    app(ChannelManager::class)->connect(1, 'socket-1');
    app(ChannelManager::class)->subscribe(1, 'private-a');

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry([
        'channels' => '{"1":"private-a"}',
        'message' => '{"event":"e"}',
    ])]);

    expect($swoole->pushed)->toHaveCount(1);
});

test('a control entry goes to its listener and never to a socket', function () {
    // Control entries ride the broadcast stream because the poll loop, the
    // origin filter and the failure backoff are already correct here. What
    // must not follow from sharing the stream is a control entry being fanned
    // out to clients: its payload is internal, and an entry carrying both
    // shapes is exactly what a foreign writer would send to get at one.
    $swoole = RelayMutDrainSwoole::make();
    $relay = relayMutDrainRelay($swoole);

    app(ChannelManager::class)->connect(1, 'socket-1');
    app(ChannelManager::class)->subscribe(1, 'private-a');

    $heard = [];
    $relay->onControl('lightspeed-relaymut-drain', function (array $payload) use (&$heard): void {
        $heard[] = $payload;
    });

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry(array_merge(
        relayMutDrainControl('lightspeed-relaymut-drain', '{"n":1}'),
        ['channels' => '["private-a"]', 'message' => '{"event":"e"}'],
    ))]);

    expect($heard)->toBe([['n' => 1]])
        ->and($swoole->pushed)->toBe([]);

    // A blank control type is not a control entry, so the same entry without
    // one is an ordinary broadcast. Treating it as a control instead would
    // silently drop every message a peer published.
    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry([
        'control' => '',
        'channels' => '["private-a"]',
        'message' => '{"event":"e"}',
    ])]);

    expect($heard)->toBe([['n' => 1]])
        ->and($swoole->pushed)->toHaveCount(1);
});

test('a control payload at the decoder ceiling is decoded, and one over it is dropped', function () {
    // Same wire contract as an ordinary entry, and the same reason: the peer
    // that signed this payload encoded it under its own ceiling.
    $relay = relayMutDrainRelay(RelayMutDrainSwoole::make());

    $heard = 0;
    $relay->onControl('lightspeed-relaymut-drain', function () use (&$heard): void {
        $heard++;
    });

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry(
        relayMutDrainControl('lightspeed-relaymut-drain', relayMutDrainNested(511)),
    )]);

    expect($heard)->toBe(1);

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry(
        relayMutDrainControl('lightspeed-relaymut-drain', relayMutDrainNested(512)),
    )]);

    expect($heard)->toBe(1);
});

test('a control entry that cannot prove where it came from is refused, loudly', function () {
    // A control entry is a COMMAND, and the revocation one force-unsubscribes
    // every connection carrying a tag. Without the signature check anything
    // that could reach Redis could issue it. The log line is not decoration:
    // either something is writing to this stream that should not be, or a
    // deployment is running two app secrets, and the second one silently
    // breaks revocation on every worker that does not match.
    Log::spy();

    $relay = relayMutDrainRelay(RelayMutDrainSwoole::make());

    $heard = 0;
    $relay->onControl('lightspeed-relaymut-drain', function () use (&$heard): void {
        $heard++;
    });

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry(
        relayMutDrainControl('lightspeed-relaymut-drain', '{"n":1}', 'not-the-signature'),
    )]);

    driveLightspeed($relay, 'deliverEntry', [relayMutDrainEntry([
        'control' => 'lightspeed-relaymut-drain',
        'payload' => '{"n":1}',
    ])]);

    expect($heard)->toBe(0);

    foreach ([true, false] as $signed) {
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Lightspeed relay refused an unsigned or badly signed control entry'
                && $context === ['control' => 'lightspeed-relaymut-drain', 'signed' => $signed],
        )->once();
    }
});
