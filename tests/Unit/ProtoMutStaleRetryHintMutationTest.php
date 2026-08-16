<?php

use Lightspeed\Protocol\Delivery;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The retry hint on a `lightspeed:stale` frame, as a range rather than a value.
 *
 * Revoking a busy tag refuses every connection carrying it in the same instant,
 * and the jitter on this hint is the only thing that stops all of them going
 * back through `/broadcasting/auth`, and therefore the application's database,
 * together. A hint that is always the same number is not jitter; a hint that
 * can be negative, or that overruns the window an operator configured, is a
 * client waiting for a length of time nobody asked for.
 *
 * None of that is observable from one frame, so these tests read many frames
 * and assert what the SET of them looks like.
 */
class ProtoMutStaleRecordingServer extends SwooleServer
{
    /** @var list<array> decoded frames, in push order */
    public array $pushed = [];

    /** Built without Swoole\Server's constructor, which would bind a port. */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push(int $fd, \Swoole\WebSocket\Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->pushed[] = json_decode((string) $data, true);

        return true;
    }
}

/** The `retry_in_ms` of every stale frame produced by `$count` refusals. */
function proto_mut_stale_hints(int $count): array
{
    $swoole = ProtoMutStaleRecordingServer::make();
    $delivery = app(Delivery::class);

    for ($i = 0; $i < $count; $i++) {
        $delivery->pushStale($swoole, 7, 'private-orders.7', 'revoked');
    }

    return array_map(
        static fn (array $frame) => json_decode($frame['data'], true)['retry_in_ms'],
        $swoole->pushed,
    );
}

test('the retry hint is spread across the configured window, never past it and never negative', function () {
    // A window of one millisecond is the smallest one that still has two
    // answers in it, so "did this vary at all" and "did it stay inside the
    // window" are both answerable from the same sample.
    config()->set('lightspeed.auth.stale_retry_max_ms', 1);

    $hints = proto_mut_stale_hints(60);

    expect(array_unique($hints))->toHaveCount(2)
        ->and(min($hints))->toBe(0)
        ->and(max($hints))->toBe(1);
});

test('a nonsense retry window still produces a usable hint instead of throwing', function () {
    // These dials are written by hand into a config file or an env var, and an
    // operator who means "do not wait" reaches for 0 or -1. The floor is what
    // keeps the range random_int() is handed a valid one: without it the whole
    // revocation drain dies inside the frame it was trying to send, and the
    // refused client is told nothing at all.
    config()->set('lightspeed.auth.stale_retry_max_ms', -1);

    $hints = proto_mut_stale_hints(60);

    expect(max($hints))->toBe(1)
        ->and(min($hints))->toBe(0);
});

test('a non-numeric retry window is read as no window rather than dying on the way to the wire', function () {
    // `LIGHTSPEED_AUTH_STALE_RETRY_MAX_MS=soon` is a typo, not an attack, and
    // the frame it garbles is the one that tells a refused client how to come
    // back. Uncast, PHP compares the int floor against the string as strings,
    // `max()` hands the string on, and random_int() rejects it.
    config()->set('lightspeed.auth.stale_retry_max_ms', 'soon');

    $hints = proto_mut_stale_hints(20);

    expect($hints)->toHaveCount(20)
        ->and(max($hints))->toBeLessThanOrEqual(1)
        ->and(min($hints))->toBeGreaterThanOrEqual(0);
});
