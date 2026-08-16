<?php

use Lightspeed\Server;

/**
 * What this package tells Swoole about the connections it will hold.
 *
 * Two of these options decide what happens to a real client, before any code in
 * this package runs, which is why they are worth a test of their own:
 *
 *   package_max_length  the largest request or frame Swoole will assemble at
 *                       all. Left unset, Swoole applies its own 2MB; stated
 *                       here, it is a dial an operator can find. It is NOT the
 *                       client-event limit and cannot be: the same process
 *                       serves the host application's HTTP on the same port, so
 *                       this number is also the largest upload that application
 *                       can accept. See ClientEvents\ClientEventLimits.
 *
 *   heartbeat_*         Swoole's reaping of connections that have sent nothing
 *                       for a while. OFF unless configured, because Swoole
 *                       CLOSES a quiet connection rather than pinging it
 *                       (measured on 6.2.0) and a browser that only listens is
 *                       quiet while it is perfectly healthy.
 *
 * WHAT THIS CANNOT PROVE, stated rather than implied: that Swoole then does the
 * right thing with them. Whether a 2MB frame is really dropped by the runtime,
 * and whether a genuinely half-open socket is really reaped, is Swoole's
 * behaviour on a real socket and needs a real half-open socket to observe. What
 * is testable here is the half that was actually missing: that the settings
 * exist, are config-driven, and reach the option array Server::serve() hands to
 * Swoole::set() rather than stopping in a config file nobody reads.
 */

/** The option array the server would hand Swoole, for one set of settings. */
function swooleOptionsFor(array $settings = []): array
{
    return driveLightspeed(app(Server::class), 'swooleOptions', [$settings]);
}

test('the frame and request bound is passed to Swoole rather than left to its implicit default', function () {
    expect(swooleOptionsFor(['package_max_length' => 4 * 1024 * 1024])['package_max_length'])
        ->toBe(4 * 1024 * 1024);
});

test('a bound that is not a usable size answers with the shipped default, not with a broken server', function () {
    // Every one of these is a size nobody can have meant, and the first is the
    // mundane one: `LIGHTSPEED_PACKAGE_MAX_LENGTH=` with nothing after it is the
    // empty string, and `(int) ''` is 0. Answering that with a floor of 1 would
    // be a server that can assemble no request at all, which is not a smaller
    // limit, it is an outage. A typo gets you the default.
    expect(swooleOptionsFor()['package_max_length'])->toBe(2 * 1024 * 1024)
        ->and(swooleOptionsFor(['package_max_length' => ''])['package_max_length'])->toBe(2 * 1024 * 1024)
        ->and(swooleOptionsFor(['package_max_length' => 0])['package_max_length'])->toBe(2 * 1024 * 1024)
        ->and(swooleOptionsFor(['package_max_length' => -1])['package_max_length'])->toBe(2 * 1024 * 1024)
        ->and(swooleOptionsFor(['package_max_length' => 'unlimited'])['package_max_length'])->toBe(2 * 1024 * 1024);

    // The line is "is this a usable size", not "is this a sensible one": a
    // literal 1 is absurd and is still passed through, because someone typed
    // it. Replacing small-but-explicit numbers as well would make the dial
    // stop working somewhere nothing documents.
    expect(swooleOptionsFor(['package_max_length' => 1])['package_max_length'])->toBe(1);
});

test('the heartbeat is absent unless it was asked for', function () {
    // The default has to be absence rather than a zero: Swoole reads the
    // presence of the key, and a heartbeat this package turned on by default
    // would close every client that only listens.
    expect(swooleOptionsFor())->not->toHaveKey('heartbeat_idle_time')
        ->and(swooleOptionsFor())->not->toHaveKey('heartbeat_check_interval');
});

test('a configured heartbeat reaches Swoole as both halves', function () {
    $options = swooleOptionsFor([
        'heartbeat_idle_time' => 120,
        'heartbeat_check_interval' => 60,
    ]);

    expect($options['heartbeat_idle_time'])->toBe(120)
        ->and($options['heartbeat_check_interval'])->toBe(60);
});

test('half a heartbeat is not a heartbeat', function () {
    // A check interval with no idle time is a timer that can never close
    // anything. An idle time with no interval is WORSE than that, and is the
    // reason this is asserted on both keys rather than only on the interval:
    // Swoole arms its reaper on the idle time ALONE, checking on its own
    // default cadence (measured on 6.2.0, a healthy silent client was cut), so
    // emitting that key by itself hands an operator who set one env var exactly
    // the listen-only-browser regression this pair ships off to avoid.
    //
    // Neither half-configuration is what the person who set one dial meant, and
    // both are the kind that looks armed. So: both keys, or no keys.
    $intervalOnly = swooleOptionsFor(['heartbeat_check_interval' => 60]);
    $idleOnly = swooleOptionsFor(['heartbeat_idle_time' => 120]);

    expect($intervalOnly)->not->toHaveKey('heartbeat_check_interval')
        ->and($intervalOnly)->not->toHaveKey('heartbeat_idle_time')
        ->and($idleOnly)->not->toHaveKey('heartbeat_idle_time')
        ->and($idleOnly)->not->toHaveKey('heartbeat_check_interval');
});

test('a one-second heartbeat is still a heartbeat', function () {
    // The comparison is "was one asked for", not "is one big enough". An
    // off-by-one here silently ignores the smallest settings, which are exactly
    // the ones an operator reaches for when reproducing a reap on purpose.
    $options = swooleOptionsFor([
        'heartbeat_idle_time' => 1,
        'heartbeat_check_interval' => 1,
    ]);

    expect($options['heartbeat_idle_time'])->toBe(1)
        ->and($options['heartbeat_check_interval'])->toBe(1);
});

test('settings that arrive as strings reach Swoole as numbers', function () {
    // Everything here can come from an env var, where every value is a string.
    // Swoole rejects an option of the wrong type, so a server configured
    // entirely from the environment would refuse to start.
    $options = swooleOptionsFor([
        'package_max_length' => '4096',
        'heartbeat_idle_time' => '120',
        'heartbeat_check_interval' => '60',
        'enable_coroutine' => '1',
        // The classic env trap: the string "0" is truthy to anything that does
        // not cast it, and this one turns compression on for a server whose
        // operator wrote it off.
        'http_compression' => '0',
    ]);

    expect($options['package_max_length'])->toBe(4096)
        ->and($options['heartbeat_idle_time'])->toBe(120)
        ->and($options['heartbeat_check_interval'])->toBe(60)
        ->and($options['enable_coroutine'])->toBeTrue()
        ->and($options['http_compression'])->toBeFalse();
});

test('the defaults with no settings at all are the ones this package ships', function () {
    // serve() is not the only caller shape that has ever existed, and a missing
    // key must not turn coroutines on, compression on, or the worker count into
    // zero, which is a server with nothing to serve on.
    $options = swooleOptionsFor();

    expect($options['enable_coroutine'])->toBeFalse()
        ->and($options['http_compression'])->toBeFalse()
        ->and($options['worker_num'])->toBe(1)
        ->and($options['task_worker_num'])->toBe(0);
});

test('the options that were already passed are still passed', function () {
    // The positive control. Every assertion above passes on an option array
    // that has lost the settings the server has always needed.
    $options = swooleOptionsFor([
        'enable_coroutine' => true,
        'http_compression' => true,
        'worker_num' => 4,
        'task_worker_num' => 2,
        'log_file' => '/tmp/lightspeed.log',
        'pid_file' => '/tmp/lightspeed.pid',
    ]);

    expect($options['enable_coroutine'])->toBeTrue()
        ->and($options['http_compression'])->toBeTrue()
        ->and($options['worker_num'])->toBe(4)
        ->and($options['task_worker_num'])->toBe(2)
        ->and($options['log_file'])->toBe('/tmp/lightspeed.log')
        ->and($options['pid_file'])->toBe('/tmp/lightspeed.pid');
});
