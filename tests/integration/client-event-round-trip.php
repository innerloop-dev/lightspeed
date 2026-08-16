<?php

/**
 * Proves the package's headline feature end to end: a browser sends a message
 * over the socket, the application's handler runs it inside Laravel, and the
 * reply comes back down the same socket.
 *
 * Every other check in this repo verifies delivery outward. This is the only
 * one that verifies the upward direction, which is the thing that makes
 * Lightspeed different from a broadcast-only server. It also pins the exact
 * frame the README tells people to bind to, including the channel that makes
 * `channel.bind('lightspeed:response')` work in a real Pusher client.
 *
 * It needs one handler in your app that answers `client-round-trip-check` by
 * echoing back what it was sent (see the failure message below for the exact
 * shape). The check exercises YOUR wiring, not a fixture the package provides
 * for itself.
 *
 *   php vendor/innerloop-dev/lightspeed/tests/integration/client-event-round-trip.php 127.0.0.1 8000
 *
 * With `--round-trips=N` it does the same thing N times on one connection and
 * reports how long each took: the median and p95 of send-to-reply, which is the
 * number behind the README's claim about a keystroke. Every reply is still
 * checked exactly as the single round trip's is, so the measurement cannot
 * quietly be timing a server that is answering the wrong thing.
 *
 *   php .../client-event-round-trip.php 127.0.0.1 8000 --round-trips=500
 */

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8000);

$roundTrips = 1;

foreach (array_slice($argv, 3) as $argument) {
    if (preg_match('/^--round-trips=(\d+)$/', $argument, $matches) === 1) {
        $roundTrips = max(1, (int) $matches[1]);
    }
}

$appRoot = getcwd();
require $appRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = (string) config('lightspeed.reverb_compat.app_key');
$secret = (string) config('lightspeed.reverb_compat.app_secret');
$channel = 'presence-round-trip-check';
if (config('lightspeed.client_event_handlers', []) === []) {
    echo "FAIL: the server under test has no client event handlers registered.\n";
    echo "This check needs a handler that answers 'client-round-trip-check' by returning\n";
    echo "ClientEventResult::response(requestId: \$data['requestId'], response: [\n";
    echo "    'echoed' => \$data['say'], 'userId' => \$event->userId,\n";
    echo "]);\n";
    exit(1);
}

$failure = null;

/** @var list<float> milliseconds, one per round trip */
$timings = [];

Swoole\Coroutine\run(function () use ($host, $port, $key, $secret, $channel, $roundTrips, &$failure, &$timings): void {
    $client = new Swoole\Coroutine\Http\Client($host, $port, false);
    $client->set(['timeout' => 5]);

    if (!$client->upgrade("/app/{$key}?protocol=7")) {
        $failure = "websocket upgrade refused (status {$client->statusCode})";

        return;
    }

    $established = json_decode($client->recv(3)->data ?? '', true);
    $socketId = json_decode($established['data'] ?? '', true)['socket_id'] ?? null;

    if (!is_string($socketId) || $socketId === '') {
        $failure = 'no socket_id in the handshake response';

        return;
    }

    // A presence channel, so the handler also sees the identity the app's own
    // authorization approved.
    $memberData = json_encode(['user_id' => '99', 'user_info' => ['name' => 'Round Trip']]);

    $client->push(json_encode([
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => $channel,
            'auth' => $key.':'.hash_hmac('sha256', "{$socketId}:{$channel}:{$memberData}", $secret),
            'channel_data' => $memberData,
        ],
    ]));

    if ((json_decode($client->recv(3)->data ?? '', true)['event'] ?? null) !== 'pusher_internal:subscription_succeeded') {
        $failure = 'could not subscribe to the presence channel';

        return;
    }

    for ($attempt = 0; $attempt < $roundTrips; $attempt++) {
        $requestId = 'round-trip-'.bin2hex(random_bytes(4));

        // The clock starts on the write and stops on the reply, so what is
        // measured is everything the claim covers: the frame up the socket, the
        // gate, the handler inside the booted application, and the answer back
        // down the same socket. It is one client on one connection, sending the
        // next only after the last has answered, which is the shape a person
        // typing produces.
        $startedAt = microtime(true);

        $client->push(json_encode([
            'event' => 'client-round-trip-check',
            'channel' => $channel,
            'data' => ['requestId' => $requestId, 'say' => 'hello'],
        ]));

        $frame = $client->recv(5);

        if (!$frame) {
            $failure = 'the handler never replied on the socket';

            return;
        }

        $timings[] = (microtime(true) - $startedAt) * 1000;

        $message = json_decode($frame->data, true);

        if (($message['event'] ?? null) !== 'lightspeed:response') {
            $failure = 'expected a lightspeed:response frame, got: '.$frame->data;

            return;
        }

        // The channel is what makes channel.bind() work in a real Pusher client.
        if (($message['channel'] ?? null) !== $channel) {
            $failure = 'the response frame did not name its channel: '.$frame->data;

            return;
        }

        $payload = json_decode($message['data'] ?? '', true);

        if (($payload['requestId'] ?? null) !== $requestId) {
            $failure = 'the response did not correlate with the request id: '.$frame->data;

            return;
        }

        if (($payload['response']['echoed'] ?? null) !== 'hello') {
            $failure = 'the handler did not run against the payload we sent: '.$frame->data;

            return;
        }

        // The handler saw the identity the subscription authorized, not a claim
        // made in the frame itself.
        if (($payload['response']['userId'] ?? null) !== '99') {
            $failure = 'the handler did not receive the authorized identity: '.$frame->data;

            return;
        }

        $lastFrame = $frame->data;
    }

    $client->close();
    echo "OK: the handler answered on the same socket: {$lastFrame}\n";
});

if ($failure !== null) {
    echo "FAIL: {$failure}\n";
    exit(1);
}

if (count($timings) > 1) {
    sort($timings);

    // The percentile of a sorted sample, by nearest rank, which is the same
    // rule lightspeed:load-probe reports its fan-out percentiles under. With a
    // few hundred samples the choice of rule moves the answer by less than the
    // run-to-run spread, and using one rule everywhere means the numbers in
    // docs/verifying.md can be read against each other.
    $at = static function (array $sorted, float $percentile): float {
        $rank = (int) ceil(($percentile * count($sorted)) - 1);

        return $sorted[max(0, min(count($sorted) - 1, $rank))];
    };

    printf(
        "round trips: %d  min %.2fms  p50 %.2fms  p95 %.2fms  max %.2fms\n",
        count($timings),
        $timings[0],
        $at($timings, 0.50),
        $at($timings, 0.95),
        $timings[count($timings) - 1],
    );
}

exit(0);
