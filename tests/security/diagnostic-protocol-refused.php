<?php

/**
 * Proves the README's third claim: Laravel's channel authorization is the
 * socket's authority.
 *
 * The diagnostic protocol can subscribe to any channel without running a single
 * Broadcast::channel rule. It shipped enabled and reachable on any path once,
 * which made that claim false: an anonymous socket could read a private channel
 * and inject forged broadcasts across the whole cluster. This script is the
 * standing proof that the door is shut.
 *
 * WHY THIS SCRIPT HAS A POSITIVE CONTROL, AND WHAT IT USED TO BE WITHOUT ONE.
 *
 * Every check here is a refusal check, so every check passes when the probe
 * cannot reach anything at all. Pointed at a port with NOTHING LISTENING, the
 * old version printed "OK: the diagnostic protocol refused an unauthenticated
 * socket." and exited 0. So did deleting Diagnostics\DiagnosticProtocol
 * outright. CI runs this on every push to main and every pull request, which
 * means the one job whose name says "Channel authorization cannot be bypassed"
 * was reporting success for a server that had never started.
 *
 * A refusal only means something once "not refused" is a state this script can
 * actually observe. So before it asserts that anything is denied it establishes,
 * and fails loudly on:
 *
 *   1. the HTTP surface answers            the process is up and serving
 *   2. a legitimate Pusher client handshakes on the very port being probed, 
 *      it upgrades, and is told `pusher:connection_established`
 *
 * Only then are the refusals evidence of policy rather than of an empty port.
 *
 * AND THE OTHER HALF, run in CI against the server that deliberately opts in:
 * with `--diagnostics=reachable`, the same probe asserts the diagnostic
 * protocol ANSWERS. That is what makes the default run mean "this server
 * refuses", rather than "this build no longer has a diagnostic protocol to
 * refuse with". The failure mode that deleting the class produced silently.
 *
 * Run it against a server started with default configuration:
 *
 *   php tests/security/diagnostic-protocol-refused.php 127.0.0.1 8000 ci-key
 *
 * And against one started with LIGHTSPEED_DIAGNOSTICS_ENABLED=true:
 *
 *   php tests/security/diagnostic-protocol-refused.php 127.0.0.1 8000 ci-key --diagnostics=reachable
 *
 * Exit code 0 means the server behaved as the mode requires. Exit code 1 means
 * either the hole is open, or the probe could not establish that it was probing
 * a running server, which is treated as a failure and never as a pass.
 */

$arguments = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument) => !str_starts_with($argument, '--'),
));

$flags = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument) => str_starts_with($argument, '--'),
));

$host = $arguments[0] ?? '127.0.0.1';
$port = (int) ($arguments[1] ?? 8000);
$appKey = $arguments[2] ?? (getenv('LIGHTSPEED_APP_KEY') ?: '');
$expectReachable = in_array('--diagnostics=reachable', $flags, true);

$channel = 'private-lightspeed-security-probe';

/** Everything that went wrong. A non-empty list is a failing run. */
$failures = [];

/** Everything that was positively established, printed on success. */
$established = [];

/** True when a POSITIVE CONTROL failed, which is a different report entirely. */
$controlFailed = false;

if ($appKey === '') {
    fwrite(STDERR, "usage: diagnostic-protocol-refused.php <host> <port> <app-key> [--diagnostics=reachable]\n");
    fwrite(STDERR, "the app key is required: without it this script cannot prove a legitimate client can connect,\n");
    fwrite(STDERR, "and a refusal it cannot contrast with an acceptance is not evidence of anything.\n");

    exit(1);
}

Swoole\Coroutine\run(function () use ($host, $port, $appKey, $channel, $expectReachable, &$failures, &$established, &$controlFailed): void {

    // -----------------------------------------------------------------------
    // Positive control 1: something is actually serving on this port.
    // -----------------------------------------------------------------------

    $health = new Swoole\Coroutine\Http\Client($host, $port, false);
    $health->set(['timeout' => 5]);
    $health->get('/up');
    $healthStatus = $health->statusCode;
    $health->close();

    if ($healthStatus !== 200) {
        $failures[] = sprintf(
            'no server answered GET /up on %s:%d (status %s). Every check below is a refusal check, so a '
            .'dead port would pass them all; refusing to report success.',
            $host,
            $port,
            var_export($healthStatus, true),
        );
        $controlFailed = true;

        return;
    }

    $established[] = "the server answers HTTP on {$host}:{$port}";

    // -----------------------------------------------------------------------
    // Positive control 2: the websocket surface works for a legitimate client.
    //
    // Same host, same port, same instant. This is what separates "the server
    // refuses the diagnostic protocol" from "the server refuses everything",
    // and it is the check that would have caught a websocket listener that
    // never came up.
    // -----------------------------------------------------------------------

    $pusher = new Swoole\Coroutine\Http\Client($host, $port, false);
    $pusher->set(['timeout' => 5]);

    if (!$pusher->upgrade('/app/'.$appKey)) {
        $failures[] = sprintf(
            'a legitimate Pusher client could not upgrade at /app/%s (status %s), so this script cannot tell '
            .'a closed door from a broken server.',
            $appKey,
            var_export($pusher->statusCode, true),
        );
        $controlFailed = true;
        $pusher->close();

        return;
    }

    $handshake = $pusher->recv(5);
    $handshakeData = $handshake ? $handshake->data : '';
    $pusher->close();

    if (!str_contains($handshakeData, 'pusher:connection_established')) {
        $failures[] = 'a legitimate Pusher client upgraded but was never told pusher:connection_established, so the '
            .'websocket surface is not serving: '.var_export($handshakeData, true);
        $controlFailed = true;

        return;
    }

    $established[] = 'a legitimate Pusher client completes the handshake on the same port';

    // -----------------------------------------------------------------------
    // The diagnostic protocol itself.
    // -----------------------------------------------------------------------

    $client = new Swoole\Coroutine\Http\Client($host, $port, false);
    $client->set(['timeout' => 5]);

    // No app key, no handshake, straight at the diagnostic protocol.
    $upgraded = $client->upgrade('/');

    if (!$upgraded) {
        if ($expectReachable) {
            $failures[] = sprintf(
                'the diagnostic protocol was expected to be reachable on this server but the upgrade was refused '
                .'(status %s).',
                var_export($client->statusCode, true),
            );
        } else {
            $established[] = "the diagnostic protocol refused the upgrade (status {$client->statusCode})";
        }

        $client->close();

        return;
    }

    $hello = $client->recv(3);
    $helloData = $hello ? $hello->data : '';
    $greeted = str_contains($helloData, '"type":"hello"');

    if ($expectReachable) {
        if (!$greeted) {
            $failures[] = 'the diagnostic protocol was expected to greet an enabled loopback socket and did not: '
                .var_export($helloData, true);
        } else {
            $established[] = 'the diagnostic protocol greets a socket on a server that opted into it';
        }
    } elseif ($greeted) {
        $failures[] = 'server advertised the diagnostic protocol to an unauthenticated socket: '.$helloData;
    }

    $client->push(json_encode(['type' => 'subscribe', 'channel' => $channel]));
    $subscribe = $client->recv(3);
    $subscribed = $subscribe && str_contains($subscribe->data, '"type":"subscribed"');

    if ($expectReachable) {
        if (!$subscribed) {
            $failures[] = 'the diagnostic protocol was expected to subscribe on this server and did not: '
                .var_export($subscribe ? $subscribe->data : null, true);
        } else {
            $established[] = 'the diagnostic protocol subscribes on a server that opted into it';
        }
    } elseif ($subscribed) {
        $failures[] = 'unauthenticated socket subscribed to '.$channel.': '.$subscribe->data;
    }

    // -----------------------------------------------------------------------
    // The injection, and the two frames it produces.
    //
    // THIS CHECK USED TO BE UNABLE TO FIRE, WHICH IS THE WORST THING A SECURITY
    // ASSERTION CAN BE. It did one recv() and looked for `"ok":true`. But the
    // probe subscribed to this very channel a moment ago, so the server pushes
    // the forged broadcast BACK to it first and the acknowledgement is the
    // SECOND frame. The single recv() therefore always read the broadcast,
    // which contains no `"ok"` at all.
    //
    // Both directions were wrong at once. On the mutant with the gate removed a
    // forged broadcast really was injected and this script said nothing about
    // it, and the `--diagnostics=reachable` arm failed against unmodified src
    // because the acknowledgement it demanded was a frame it never read.
    //
    // So: read every frame until the acknowledgement arrives or the clock runs
    // out, and judge on both of the things that are evidence.
    //
    //   the ack   the server accepted a broadcast-test from this socket
    //   the echo  the forged event actually reached a subscriber
    //
    // The echo is the stronger of the two, because it is the harm rather than
    // the permission, and it is the one a `delivered: 0` acknowledgement would
    // not have shown.
    // -----------------------------------------------------------------------

    $forgedEvent = 'lightspeed.security.probe.forged';

    $client->push(json_encode([
        'type' => 'send',
        'id' => 'security-probe',
        'action' => 'broadcast-test',
        'data' => [
            'channel' => $channel,
            'event' => $forgedEvent,
            // `payload`, and not `data`. Diagnostics\DiagnosticProtocol reads
            // `$data['payload']` for the body of the broadcast it sends, so the
            // old `data` key put NOTHING in the forged event: the probe was
            // asking the server to inject an empty payload and then judging the
            // answer.
            'payload' => ['forged' => true],
        ],
    ]));

    $ack = null;
    $echo = null;
    $seen = [];
    $deadline = microtime(true) + 3.0;

    while ($ack === null || $echo === null) {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            break;
        }

        $frame = $client->recv($remaining);

        if (!$frame || !is_string($frame->data ?? null)) {
            break;
        }

        $seen[] = $frame->data;
        $decoded = json_decode($frame->data, true);

        if (!is_array($decoded)) {
            continue;
        }

        if (($decoded['type'] ?? null) === 'response' && ($decoded['id'] ?? null) === 'security-probe') {
            $ack = $decoded;
            continue;
        }

        if (($decoded['event'] ?? null) === $forgedEvent) {
            $echo = $decoded;
        }
    }

    $accepted = is_array($ack) && ($ack['ok'] ?? null) === true;

    if ($expectReachable) {
        if (!$accepted) {
            $failures[] = 'the diagnostic protocol was expected to accept a broadcast-test on this server and did not: '
                .var_export($seen, true);
        } else {
            $established[] = 'the diagnostic protocol answers broadcast-test on a server that opted into it';
        }

        if ($echo === null) {
            $failures[] = 'the diagnostic protocol acknowledged a broadcast-test that never reached the channel, so '
                .'this script cannot tell an injection from an acknowledgement: '.var_export($seen, true);
        } else {
            $established[] = 'a broadcast-test on a server that opted in really does reach the channel';
        }
    } else {
        if ($accepted) {
            $failures[] = 'unauthenticated socket was allowed to inject a broadcast: '.json_encode($ack);
        }

        if ($echo !== null) {
            $failures[] = 'a forged broadcast from an unauthenticated socket reached '.$channel.': '.json_encode($echo);
        }
    }

    $client->close();
});

// A run that established nothing establishes nothing. The coroutine returns
// early on every unrecoverable step, and this is the backstop that stops a
// future early return from being a silent success.
if ($failures === [] && $established === []) {
    echo "FAIL: the probe finished without establishing anything at all.\n";

    exit(1);
}

if ($failures !== []) {
    if ($controlFailed) {
        // NOT a security verdict. The probe could not establish that it was
        // talking to a running server, so it has no opinion on what that server
        // refuses, and says so rather than claiming either answer.
        echo "FAIL: this probe could not establish that it was probing a running server.\n";
    } else {
        echo $expectReachable
            ? "FAIL: the diagnostic protocol is not reachable on a server that enabled it.\n"
            : "FAIL: the diagnostic protocol is reachable without authorization.\n";
    }

    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }

    exit(1);
}

foreach ($established as $fact) {
    echo "  proved: {$fact}\n";
}

echo $expectReachable
    ? "OK: the diagnostic protocol is reachable exactly where it was enabled.\n"
    : "OK: the diagnostic protocol refused an unauthenticated socket, on a server proven to be serving.\n";

exit(0);
