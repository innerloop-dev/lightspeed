<?php

/**
 * Proves the server enforces subscription authorization on protected channels.
 *
 * In the Pusher model an app authorizes a subscription out of band: the client
 * asks the application's own `/broadcasting/auth` endpoint, that endpoint runs
 * the app's `Broadcast::channel` rules against the real session, and returns a
 * signed string. The server's job is to accept only signatures that could have
 * come from that endpoint. If it accepts anything else, every channel rule the
 * app wrote is decorative.
 *
 * This checks the enforceable half: a correct signature is accepted, and four
 * kinds of wrong signature are refused, on both private and presence channels.
 *
 *   php vendor/innerloop-dev/lightspeed/tests/integration/subscription-authorization-enforced.php 127.0.0.1 8000
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

if ($key === '' || $secret === '') {
    echo "FAIL: no app key/secret configured.\n";
    exit(1);
}

$failures = [];

Swoole\Coroutine\run(function () use ($host, $port, $key, $secret, &$failures): void {
    /**
     * Open a socket, send one subscribe frame, and report whether the server
     * accepted it.
     */
    $attempt = function (string $channel, ?callable $makeAuth, ?string $channelData) use ($host, $port, $key, &$failures): ?bool {
        $client = new Swoole\Coroutine\Http\Client($host, $port, false);
        $client->set(['timeout' => 5]);

        if (!$client->upgrade("/app/{$key}?protocol=7")) {
            $failures[] = "websocket upgrade refused (status {$client->statusCode})";

            return null;
        }

        $established = json_decode($client->recv(3)->data ?? '', true);
        $socketId = json_decode($established['data'] ?? '', true)['socket_id'] ?? null;

        if (!is_string($socketId) || $socketId === '') {
            $failures[] = 'no socket_id in the handshake response';

            return null;
        }

        $data = ['channel' => $channel];

        if ($makeAuth !== null) {
            // The signature covers the socket id, so it can only be built once
            // the handshake has told us what that id is.
            $data['auth'] = $makeAuth($socketId);
        }

        if ($channelData !== null) {
            $data['channel_data'] = $channelData;
        }

        $client->push(json_encode(['event' => 'pusher:subscribe', 'data' => $data]));

        $reply = json_decode($client->recv(3)->data ?? '', true);
        $client->close();

        return ($reply['event'] ?? null) === 'pusher_internal:subscription_succeeded';
    };

    $sign = fn (string $payload, ?string $withSecret = null): string
        => $key.':'.hash_hmac('sha256', $payload, $withSecret ?? $secret);

    $private = 'private-authz-check';
    $presence = 'presence-authz-check';
    $memberData = json_encode(['user_id' => 'user-1', 'user_info' => ['name' => 'Probe']]);

    // The control: a correctly signed subscription must be accepted, otherwise
    // the rejections below would prove nothing.
    if ($attempt($private, fn (string $sid) => $sign("{$sid}:{$private}"), null) !== true) {
        $failures[] = 'a correctly signed private subscription was refused';
    }

    if ($attempt($presence, fn (string $sid) => $sign("{$sid}:{$presence}:{$memberData}"), $memberData) !== true) {
        $failures[] = 'a correctly signed presence subscription was refused';
    }

    // Each of these must be refused.
    $rejections = [
        'no auth at all' => [$private, null, null],
        'a signature made with the wrong secret' => [
            $private,
            fn (string $sid) => $sign("{$sid}:{$private}", 'not-the-secret'),
            null,
        ],
        'a signature for a different channel' => [
            $private,
            fn (string $sid) => $sign("{$sid}:private-somewhere-else"),
            null,
        ],
        'a signature bound to a different socket' => [
            $private,
            fn (string $sid) => $sign("99.99:{$private}"),
            null,
        ],
        'presence data tampered with after signing' => [
            $presence,
            fn (string $sid) => $sign("{$sid}:{$presence}:{$memberData}"),
            json_encode(['user_id' => 'someone-else', 'user_info' => ['name' => 'Impostor']]),
        ],
    ];

    foreach ($rejections as $description => [$channel, $makeAuth, $channelData]) {
        if ($attempt($channel, $makeAuth, $channelData) === true) {
            $failures[] = "the server accepted {$description} on {$channel}";
        }
    }
});

if ($failures !== []) {
    echo "FAIL: subscription authorization is not enforced.\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }

    exit(1);
}

echo "OK: only correctly signed subscriptions were accepted.\n";
exit(0);
