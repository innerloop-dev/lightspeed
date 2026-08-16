<?php

/**
 * Proves the connection-closed hook on a real server: a real socket opens,
 * subscribes to a presence channel with a real grant, and hangs up, and the
 * application's own PHP is told inside the running server.
 *
 * Nothing else here checks that direction. Every other integration check
 * watches something arrive on a socket; this one watches something arrive in
 * the APPLICATION, at the one moment the socket is no longer there to report
 * it, which is exactly when a wiring mistake cannot be seen from the wire.
 *
 * It needs one handler in your app that records what it is given (see the
 * failure message below for the exact shape). The package provides no fixture
 * handler for it to use, because a fixture would exercise the package's own
 * wiring rather than the wiring an application has to get right.
 *
 *   php vendor/innerloop-dev/lightspeed/tests/integration/connection-closed-handler.php 127.0.0.1 8000
 */

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8000);

$appRoot = getcwd();
require $appRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = (string) config('lightspeed.reverb_compat.app_key');
$secret = (string) config('lightspeed.reverb_compat.app_secret');
$channel = 'presence-connection-closed-check';
$tag = 'closed-check:'.bin2hex(random_bytes(4));
$recordPath = storage_path('app/lightspeed-connection-closed.log');

if (config('lightspeed.connection_closed_handlers', []) === []) {
    echo "FAIL: the server under test has no connection closed handlers registered.\n";
    echo "This check needs a handler that appends one JSON line per event to\n";
    echo "storage/app/lightspeed-connection-closed.log:\n";
    echo "    file_put_contents(storage_path('app/lightspeed-connection-closed.log'), json_encode([\n";
    echo "        'socket_id' => \$event->socketId, 'channels' => \$event->channels,\n";
    echo "        'presence_leaves' => \$event->presenceLeaves, 'tags' => \$event->tags,\n";
    echo "        'auth' => \$event->authPayloads, 'reason' => \$event->reason,\n";
    echo "    ]).PHP_EOL, FILE_APPEND);\n";
    exit(1);
}

// The record is the evidence, so a stale one is worse than none: it would let
// a server that never fired the hook pass on a previous run's line.
@unlink($recordPath);

$failure = null;
$socketId = null;

Swoole\Coroutine\run(function () use ($host, $port, $key, $secret, $channel, $tag, &$failure, &$socketId): void {
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

    $memberData = json_encode(['user_id' => '77', 'user_info' => ['name' => 'Closing Time']]);

    // A real grant, minted and signed here exactly as an application's channel
    // callback signs one through Lightspeed::tag(...)->with([...]). Without it
    // this check could not tell a hook that carries the connection's
    // authorization from one that reports an empty list.
    $now = (int) (microtime(true) * 1_000_000);
    $grant = (new Lightspeed\Auth\Grant([$tag], ['can_edit' => true], $now, $now + 60_000_000))->encode();

    $signature = hash_hmac(
        'sha256',
        Lightspeed\Protocol\SubscriptionAuthorizer::signingString($socketId, $channel, $memberData, $grant),
        $secret,
    );

    $client->push(json_encode([
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => $channel,
            'auth' => $key.':'.$signature.':'.$grant,
            'channel_data' => $memberData,
        ],
    ]));

    if ((json_decode($client->recv(3)->data ?? '', true)['event'] ?? null) !== 'pusher_internal:subscription_succeeded') {
        $failure = 'could not subscribe to the presence channel with a granted auth string';

        return;
    }

    // The orderly close this hook is about.
    $client->close();
});

if ($failure !== null) {
    echo "FAIL: {$failure}\n";
    exit(1);
}

// The close travels to the server and the handler runs there, so the record
// appears shortly after this process stopped talking.
$record = null;

for ($attempt = 0; $attempt < 50; $attempt++) {
    foreach (file_exists($recordPath) ? (array) file($recordPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $decoded = json_decode((string) $line, true);

        if (is_array($decoded) && ($decoded['socket_id'] ?? null) === $socketId) {
            $record = $decoded;

            break 2;
        }
    }

    usleep(100_000);
}

if ($record === null) {
    echo "FAIL: the application was never told that socket {$socketId} closed.\n";
    exit(1);
}

$checks = [
    'the event did not report an orderly close' => ($record['reason'] ?? null) === 'closed',
    'the channels the connection held were not reported' => in_array($channel, (array) ($record['channels'] ?? []), true),
    'the presence member that left was not reported' => ($record['presence_leaves'][0]['user_id'] ?? null) === '77'
        && ($record['presence_leaves'][0]['channel'] ?? null) === $channel,
    // The half that cannot be recovered after the fact: the teardown drops the
    // connection's grants before it works out what closed.
    'the grant tags the connection carried were not reported' => in_array($tag, (array) ($record['tags'] ?? []), true),
    // Keyed by the channel it was signed for, so a connection holding grants
    // on several channels reports each one rather than whichever the client
    // re-subscribed to last.
    'the grant payload the application signed was not reported' => ($record['auth'][$channel]['can_edit'] ?? null) === true,
];

foreach ($checks as $message => $passed) {
    if (!$passed) {
        echo "FAIL: {$message}: ".json_encode($record)."\n";
        exit(1);
    }
}

// The other half of the gate: a connection that never joined a channel and was
// never granted anything is the server's business alone, and must not reach the
// application at all. Anyone with the public app key can open one.
$anonymousSocketId = null;

Swoole\Coroutine\run(function () use ($host, $port, $key, &$anonymousSocketId): void {
    $client = new Swoole\Coroutine\Http\Client($host, $port, false);
    $client->set(['timeout' => 5]);

    if (!$client->upgrade("/app/{$key}?protocol=7")) {
        return;
    }

    $established = json_decode($client->recv(3)->data ?? '', true);
    $anonymousSocketId = json_decode($established['data'] ?? '', true)['socket_id'] ?? null;

    $client->close();
});

if (!is_string($anonymousSocketId) || $anonymousSocketId === '') {
    echo "FAIL: the anonymous connection never completed a handshake.\n";
    exit(1);
}

// Long enough that a handler which was going to run has run: the granted close
// above was recorded within this same window.
usleep(1_500_000);

foreach (file_exists($recordPath) ? (array) file($recordPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    $decoded = json_decode((string) $line, true);

    if (is_array($decoded) && ($decoded['socket_id'] ?? null) === $anonymousSocketId) {
        echo "FAIL: a connection that held no channel and no grant reached an application handler: {$line}\n";
        exit(1);
    }
}

echo "OK: the application was told the connection closed: ".json_encode($record)."\n";

exit(0);
