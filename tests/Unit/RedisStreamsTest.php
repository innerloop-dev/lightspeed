<?php

use Lightspeed\Relay\RedisStreams;

/**
 * Laravel hands the package whichever Redis client the host app configured,
 * and phpredis and Predis return stream fields in different shapes. Decoding
 * one shape and not the other silently drops broadcasts and owner commands,
 * which is exactly what happened when the package was first installed into a
 * stock Laravel app.
 */

test('it decodes the flat field list Predis returns', function () {
    expect(RedisStreams::normalizeFields([
        'origin_process_key',
        'worker-1',
        'channels',
        '["private-resource.1"]',
        'message',
        '{"event":"resource.updated"}',
    ]))->toBe([
        'origin_process_key' => 'worker-1',
        'channels' => '["private-resource.1"]',
        'message' => '{"event":"resource.updated"}',
    ]);
});

test('it decodes the associative field map phpredis returns', function () {
    expect(RedisStreams::normalizeFields([
        'origin_process_key' => 'worker-2',
        'channels' => '["private-resource.2"]',
        'message' => '{"event":"resource.created"}',
    ]))->toBe([
        'origin_process_key' => 'worker-2',
        'channels' => '["private-resource.2"]',
        'message' => '{"event":"resource.created"}',
    ]);
});

test('it yields nothing for an empty entry', function () {
    expect(RedisStreams::normalizeFields([]))->toBe([]);
});

test('it nulls non-string values rather than guessing at them', function () {
    expect(RedisStreams::normalizeFields(['message' => 42]))
        ->toBe(['message' => null]);
});

test('it skips a trailing key with no value', function () {
    expect(RedisStreams::normalizeFields(['message', '{"ok":true}', 'orphan']))
        ->toBe(['message' => '{"ok":true}', 'orphan' => null]);
});

// ---------------------------------------------------------------------------
// A failed read must not look like an idle one (F9)
// ---------------------------------------------------------------------------

/**
 * The relay's poll loop recovers from an outage by catching an exception:
 * that is where it logs, and where it drops the dead handle so the next tick
 * resolves a fresh one. So a read that FAILED and a read that found nothing
 * have to be distinguishable here, or a worker stops receiving broadcasts,
 * presence events and revocations permanently, silently, while the server
 * carries on serving. Observed on a live two-worker server.
 */

/** A handle whose socket has died: false replies, and it knows it is gone. */
class DeadPhpRedis extends \Redis
{
    public function xread(array $streams, int $count = -1, int $block = -1): \Redis|array|bool
    {
        return false;
    }

    public function isConnected(): bool
    {
        return false;
    }

    public function getLastError(): ?string
    {
        return 'went away';
    }
}

/** A healthy handle on one of the clients that answers an idle stream false. */
class IdlePhpRedis extends \Redis
{
    public function xread(array $streams, int $count = -1, int $block = -1): \Redis|array|bool
    {
        return false;
    }

    public function isConnected(): bool
    {
        return true;
    }
}

test('a read from a dead handle throws rather than reporting an idle stream', function () {
    expect(fn () => RedisStreams::read(new DeadPhpRedis(), 'lightspeed:broadcasts', '0-0', 100))
        ->toThrow(RuntimeException::class, 'not connected');
});

test('a false reply from a live handle is still just an idle stream', function () {
    expect(RedisStreams::read(new IdlePhpRedis(), 'lightspeed:broadcasts', '0-0', 100))->toBe([]);
});
