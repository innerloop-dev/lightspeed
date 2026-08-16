<?php

/**
 * Proves the claim the whole package rests on: an event broadcast by Laravel
 * reaches a subscribed websocket client.
 *
 * It runs from a plain CLI process with no Swoole server in it, which is the
 * interesting case: the broadcast has to travel through the Redis relay into a
 * running server's worker and out to that worker's socket. A queue worker or a
 * scheduled job is in exactly this position.
 *
 * Run it from the root of a Laravel app that has Lightspeed installed and a
 * server already listening:
 *
 *   php vendor/innerloop-dev/lightspeed/tests/integration/broadcast-reaches-socket.php 127.0.0.1 8000
 *
 * Exit code 0 means the event arrived.
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
$channel = 'private-lightspeed-broadcast-check';
$event = 'LightspeedBroadcastCheck';

if ($key === '' || $secret === '') {
    echo "FAIL: no app key/secret configured.\n";
    exit(1);
}

$failure = null;

Swoole\Coroutine\run(function () use ($host, $port, $key, $secret, $channel, $event, $app, &$failure): void {
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

    $client->push(json_encode([
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => $channel,
            'auth' => $key.':'.hash_hmac('sha256', "{$socketId}:{$channel}", $secret),
        ],
    ]));

    $subscribed = json_decode($client->recv(3)->data ?? '', true);

    if (($subscribed['event'] ?? null) !== 'pusher_internal:subscription_succeeded') {
        $failure = 'subscription failed: '.json_encode($subscribed);

        return;
    }

    $app->make(Illuminate\Contracts\Broadcasting\Factory::class)
        ->connection()
        ->broadcast([$channel], $event, ['sent' => 'from laravel']);

    $frame = $client->recv(5);

    if (!$frame) {
        $failure = 'the broadcast never arrived on the socket';

        return;
    }

    $message = json_decode($frame->data, true);

    if (($message['event'] ?? null) !== $event) {
        $failure = 'unexpected frame: '.$frame->data;

        return;
    }

    $client->close();
    echo "OK: broadcast() reached the socket: {$frame->data}\n";
});

if ($failure !== null) {
    echo "FAIL: {$failure}\n";
    exit(1);
}

exit(0);
