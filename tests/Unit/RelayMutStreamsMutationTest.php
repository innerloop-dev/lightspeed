<?php

use Lightspeed\Relay\RedisStreams;

/**
 * The client-shape layer under the relay, pinned at the calling convention.
 *
 * Everything this helper does is invisible from above: pick the right argument
 * order for the client the host app happens to have configured, and turn two
 * different reply shapes into one. A mutation here does not break a test that
 * only ever runs against phpredis, it breaks the OTHER half of the deployment
 * matrix, silently, on somebody else's machine. So these doubles stand in for
 * both clients and assert the calls that were actually made, not merely the
 * value that came back.
 */

/** A Predis-shaped client: records the call and answers in Predis' entry shape. */
class RelayMutPredisDouble
{
    /** @var list<array> the calls made on this client, in order */
    public array $calls = [];

    public mixed $xreadReply = null;

    public bool $connected = true;

    public function xadd(mixed $a = null, mixed $b = null, mixed $c = null, mixed $d = null): mixed
    {
        $this->calls[] = ['xadd', $a, $b, $c, $d];

        return '1-1';
    }

    public function xread(mixed $a = null, mixed $b = null, mixed $c = null, mixed $d = null): mixed
    {
        $this->calls[] = ['xread', $a, $b, $c, $d];

        return $this->xreadReply;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}

/**
 * A phpredis-shaped client.
 *
 * It extends \Redis rather than imitating it because the helper branches on
 * `instanceof \Redis`, so a stand-in that only quacks like one would take the
 * wrong branch and prove nothing. Nothing here connects: every method the
 * helper reaches is overridden.
 */
class RelayMutPhpRedisDouble extends \Redis
{
    /** @var list<array> the calls made on this client, in order */
    public array $calls = [];

    public mixed $xreadReply = false;

    public bool $connected = false;

    public ?string $lastError = null;

    public function __construct()
    {
    }

    public function xadd(string $key, string $id, array $values, int $maxlen = 0, bool $approx = false, bool $nomkstream = false): \Redis|string|false
    {
        $this->calls[] = ['xadd', $key, $id, $values, $maxlen, $approx];

        return '1-1';
    }

    public function xread(array $streams, int $count = -1, int $block = -1): \Redis|array|bool
    {
        $this->calls[] = ['xread', $streams, $count];

        return $this->xreadReply;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}

test('a Predis client is written to in Predis argument order', function () {
    // The two clients disagree about where the id goes and how a trim is
    // expressed. Sending phpredis' order to Predis is not a wrong entry, it is
    // no entry at all: cross-worker broadcast simply stops for that deployment.
    $client = new RelayMutPredisDouble();

    RedisStreams::add($client, 'lightspeed:broadcasts', ['control' => 'revoke'], 500);

    expect($client->calls)->toBe([
        ['xadd', 'lightspeed:broadcasts', ['control' => 'revoke'], '*', ['trim' => ['MAXLEN', '~', 500]]],
    ]);
});

test('the trim asked of phpredis is the approximate one', function () {
    // MAXLEN without the tilde is an exact trim, which Redis documents as the
    // expensive form: it walks entries rather than dropping whole radix nodes.
    // This is on the publish path of every broadcast, so an exact trim is a
    // per-message cost paid on the event loop that serves the sockets.
    $client = new RelayMutPhpRedisDouble();

    RedisStreams::add($client, 'lightspeed:broadcasts', ['control' => 'revoke'], 500);

    expect($client->calls)->toBe([
        ['xadd', 'lightspeed:broadcasts', '*', ['control' => 'revoke'], 500, true],
    ]);
});

test('a Predis client is read from in Predis argument order, and its entry shape decoded', function () {
    // Predis returns a list of [id, fields] pairs where phpredis returns a map
    // keyed by id. Reading one shape as the other yields entries whose id is an
    // array offset, so the poll loop advances to "0" and re-reads the same
    // batch forever.
    $client = new RelayMutPredisDouble();
    $client->xreadReply = ['lightspeed:broadcasts' => [['5-1', ['control', 'revoke']]]];

    $entries = RedisStreams::read($client, 'lightspeed:broadcasts', '4-0', 100);

    expect($client->calls)->toBe([['xread', 100, null, ['lightspeed:broadcasts'], '4-0']])
        ->and($entries)->toBe([['5-1', ['control', 'revoke']]]);
});

test('a phpredis entry id arrives as a string even when Redis numbered it', function () {
    // PHP casts a numeric array key to int, and the id is a key in phpredis'
    // reply. The caller stores it as its resume point and compares it as a
    // string, so an int here is the same class of bug as the numeric user id
    // that once emptied every presence snapshot.
    $client = new RelayMutPhpRedisDouble();
    $client->xreadReply = ['lightspeed:broadcasts' => [5 => ['control' => 'revoke']]];

    expect(RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))
        ->toBe([['5', ['control' => 'revoke']]]);
});

test('a phpredis reply whose stream payload is not a map costs one entry, not the poll', function () {
    // The cast is what keeps a reply this code did not expect inside the
    // foreach. Without it the loop is handed a scalar, and in an application
    // that promotes warnings the poll loop throws instead of dropping one
    // unreadable entry.
    $client = new RelayMutPhpRedisDouble();
    $client->xreadReply = ['lightspeed:broadcasts' => 'not-a-map'];

    expect(RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))
        ->toBe([['0', []]]);
});

test('a dead handle reports the error the client itself recorded', function () {
    // The relay logs this message and nothing else, so it is the whole of what
    // an operator gets for a worker that has stopped receiving. Reporting "no
    // error reported" while the client is holding a real one turns a diagnosis
    // into a guess.
    $client = new RelayMutPhpRedisDouble();
    $client->connected = false;
    $client->lastError = 'READONLY You cannot write against a read only replica';

    expect(fn () => RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))
        ->toThrow(RuntimeException::class, 'READONLY You cannot write against a read only replica');
});

test('a dead handle with nothing to say still says so', function () {
    // The empty case has to read as an absence rather than as a message that
    // trails off, because this string is what a human sees.
    $client = new RelayMutPhpRedisDouble();
    $client->connected = false;
    $client->lastError = '';

    expect(fn () => RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))
        ->toThrow(RuntimeException::class, 'no error reported');

    $silent = new RelayMutPhpRedisDouble();
    $silent->connected = false;
    $silent->lastError = null;

    expect(fn () => RedisStreams::read($silent, 'lightspeed:broadcasts', '0-0', 100))
        ->toThrow(RuntimeException::class, 'no error reported');
});

test('a Predis handle that answers false with a dead socket fails loudly', function () {
    // Same rule as phpredis, and it has to be stated twice because the two
    // clients answer an idle stream differently. A failure taken for an idle
    // stream is a worker that silently stops receiving.
    $client = new RelayMutPredisDouble();
    $client->xreadReply = false;
    $client->connected = false;

    expect(fn () => RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))
        ->toThrow(RuntimeException::class, 'not connected');
});

test('a Predis idle reply is nothing new rather than a failure', function () {
    // Predis answers an idle stream with null. Treating that as a reply to be
    // unpacked reaches reset(null), which is a TypeError inside the poll loop:
    // an outage reported on every quiet tick of a perfectly healthy stream.
    $client = new RelayMutPredisDouble();
    $client->xreadReply = null;

    expect(RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))->toBe([]);
});

test('a Predis reply this build cannot read costs its entries, not the poll', function () {
    // A shared stream carries whatever any build in the deployment wrote to it,
    // so an entry of an unknown shape is ordinary. Each one is skipped; none of
    // them may escape as a thrown error into a Swoole timer callback.
    $client = new RelayMutPredisDouble();
    $client->xreadReply = ['lightspeed:broadcasts' => 'not-a-list'];

    expect(RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))->toBe([]);

    $mixed = new RelayMutPredisDouble();
    $mixed->xreadReply = ['lightspeed:broadcasts' => [
        'a-scalar-instead-of-an-entry',
        [1 => ['control' => 'revoke']],
        [5, ['control' => 'revoke']],
    ]];

    // The scalar and the id-less entry are dropped; the entry that has an id
    // survives, with that id as a string even though Redis numbered it.
    expect(RedisStreams::read($mixed, 'lightspeed:broadcasts', '0-0', 100))
        ->toBe([['5', ['control' => 'revoke']]]);
});

test('a Predis entry with an id and no fields is still an entry', function () {
    // The id is what the poll loop stores as its resume point, so dropping the
    // entry because its fields are missing would replay it on every tick
    // forever.
    $client = new RelayMutPredisDouble();
    $client->xreadReply = ['lightspeed:broadcasts' => [['7-2']]];

    expect(RedisStreams::read($client, 'lightspeed:broadcasts', '0-0', 100))
        ->toBe([['7-2', []]]);
});

test('a numerically named field does not turn a map into a flat list', function () {
    // A stream field may be called "0", and PHP hands it back as an int key, so
    // the reply is a map that is not a list. Falling through into the flat-list
    // decoder after decoding it as a map reads the same array twice and invents
    // fields whose names are other fields' values.
    expect(RedisStreams::normalizeFields([0 => 'origin_process_key', 'message' => '{"event":"x"}']))
        ->toBe(['message' => '{"event":"x"}']);
});
