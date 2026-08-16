<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Lightspeed\Protocol\PusherPaths;
use Lightspeed\Connections\ConnectionRegistry;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\WaitGroup;

class LightspeedLoadProbe extends Command
{
    protected $signature = 'lightspeed:load-probe
        {--host= : Shared Host header for websocket and publish traffic (defaults to lightspeed.reverb_compat.public_host)}
        {--targets=127.0.0.1:8000 : Comma-separated connectHost:port realtime targets}
        {--tls=0 : Use TLS for websocket and publish traffic (1 or 0)}
        {--connections=100 : Number of websocket clients to open}
        {--channel=private-load.local : Private channel used for fan-out}
        {--event=load.test : Event name to publish}
        {--payload-bytes=128 : Payload size in bytes}
        {--app-id= : Realtime app id override}
        {--app-key= : Realtime app key override}
        {--app-secret= : Realtime app secret override}';

    protected $description = 'Open many websocket clients, publish one event, and report fan-out delivery latency';

    public function handle(ConnectionRegistry $registry): int
    {
        $host = (string) ($this->option('host') ?: config('lightspeed.reverb_compat.public_host', 'localhost'));
        $targets = $this->parseTargets((string) $this->option('targets'));
        $tls = filter_var($this->option('tls'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        $connections = max(1, (int) $this->option('connections'));
        $channel = (string) $this->option('channel');
        $event = (string) $this->option('event');
        $payloadBytes = max(1, (int) $this->option('payload-bytes'));
        $appId = (string) ($this->option('app-id') ?: config('lightspeed.reverb_compat.app_id'));
        $appKey = (string) ($this->option('app-key') ?: config('lightspeed.reverb_compat.app_key'));
        $appSecret = (string) ($this->option('app-secret') ?: config('lightspeed.reverb_compat.app_secret'));

        if ($targets === []) {
            $this->error('Lightspeed load probe requires at least one target.');
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Host', $host);
        $this->components->twoColumnDetail('Targets', implode(', ', array_map(
            static fn (array $target): string => "{$target['connectHost']}:{$target['port']}",
            $targets,
        )));
        $this->components->twoColumnDetail('TLS', $tls ? 'yes' : 'no');
        $this->components->twoColumnDetail('Connections', (string) $connections);
        $this->components->twoColumnDetail('Channel', $channel);
        $this->components->twoColumnDetail('Event', $event);
        $this->components->twoColumnDetail('Payload Bytes', (string) $payloadBytes);

        $summary = [];
        $error = null;

        Coroutine\run(function () use (
            $host,
            $targets,
            $tls,
            $connections,
            $channel,
            $event,
            $payloadBytes,
            $appId,
            $appKey,
            $appSecret,
            $registry,
            &$summary,
            &$error,
        ): void {
            $clients = [];

            try {
                for ($index = 0; $index < $connections; $index++) {
                    $target = $targets[$index % count($targets)];
                    $client = $this->connectClient(
                        connectHost: $target['connectHost'],
                        host: $host,
                        port: $target['port'],
                        path: $this->defaultRealtimePath($appKey),
                        tls: $tls,
                    );
                    $connected = $this->receiveEvent($client);
                    $socketId = (string) ($connected['decoded_data']['socket_id'] ?? '');
                    if ($socketId === '') {
                        throw new \RuntimeException("Load probe expected socket_id for connection {$index}.");
                    }

                    $metadata = $this->resolveSocketMetadata($registry, $socketId);

                    $clients[] = [
                        'index' => $index,
                        'client' => $client,
                        'socketId' => $socketId,
                        'target' => $target,
                        'metadata' => $metadata,
                    ];

                    $this->sendEvent($client, 'pusher:subscribe', null, $this->buildPrivateAuthPayload(
                        appKey: $appKey,
                        appSecret: $appSecret,
                        socketId: $socketId,
                        channel: $channel,
                    ));
                    $subscribed = $this->receiveEvent($client);
                    if (($subscribed['event'] ?? null) !== 'pusher_internal:subscription_succeeded') {
                        throw new \RuntimeException("Load probe subscription failed for connection {$index}.");
                    }
                }

                $payload = [
                    'marker' => 'lightspeed-load-probe',
                    'blob' => str_repeat('x', $payloadBytes),
                ];
                $publishBody = [
                    'name' => $event,
                    'channels' => [$channel],
                    'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                ];

                $publishedAt = microtime(true);
                $publishResponse = $this->publishEvent(
                    host: $host,
                    connectHost: $targets[0]['connectHost'],
                    port: $targets[0]['port'],
                    tls: $tls,
                    appId: $appId,
                    appKey: $appKey,
                    appSecret: $appSecret,
                    payload: $publishBody,
                );

                if (($publishResponse['ok'] ?? false) !== true) {
                    throw new \RuntimeException('Load probe publish request did not succeed: '.json_encode($publishResponse, JSON_THROW_ON_ERROR));
                }

                // Every client waits in its own coroutine, so each number below
                // is that socket's own publish-to-receipt time. Draining the
                // sockets one after another would instead charge each client
                // for the time spent reading all the sockets before it, which
                // measures this loop's speed and inflates with connection
                // count rather than describing server fan-out.
                $latenciesMs = [];
                $delivered = 0;
                $receiveErrors = [];
                $waitGroup = new WaitGroup();

                foreach ($clients as $clientInfo) {
                    $waitGroup->add();

                    Coroutine::create(function () use (
                        $clientInfo,
                        $event,
                        $channel,
                        $publishedAt,
                        $waitGroup,
                        &$latenciesMs,
                        &$delivered,
                        &$receiveErrors,
                    ): void {
                        try {
                            $this->receiveEventMatching(
                                $clientInfo['client'],
                                static fn (array $frame): bool =>
                                    ($frame['event'] ?? null) === $event
                                    && ($frame['channel'] ?? null) === $channel
                            );

                            $latenciesMs[] = (microtime(true) - $publishedAt) * 1000;
                            $delivered++;
                        } catch (\Throwable $throwable) {
                            $receiveErrors[] = "connection {$clientInfo['index']}: ".$throwable->getMessage();
                        } finally {
                            $waitGroup->done();
                        }
                    });
                }

                $waitGroup->wait();

                if ($receiveErrors !== []) {
                    throw new \RuntimeException(
                        'Load probe did not receive the event on every connection ('
                        .count($receiveErrors).' of '.count($clients).' failed). First failure: '.$receiveErrors[0]
                    );
                }

                sort($latenciesMs);

                $byInstance = [];
                foreach ($clients as $clientInfo) {
                    $instance = (string) ($clientInfo['metadata']['instance_id'] ?? 'unknown');
                    $byInstance[$instance] = ($byInstance[$instance] ?? 0) + 1;
                }
                ksort($byInstance);

                $summary = [
                    'connections' => $connections,
                    'delivered' => $delivered,
                    'targets' => array_map(
                        static fn (array $target): string => "{$target['connectHost']}:{$target['port']}",
                        $targets,
                    ),
                    'publishResponse' => $publishResponse,
                    'byInstance' => $byInstance,
                    'latency' => [
                        'min_ms' => round($latenciesMs[0] ?? 0, 2),
                        'p50_ms' => round($this->percentile($latenciesMs, 50), 2),
                        'p95_ms' => round($this->percentile($latenciesMs, 95), 2),
                        'p99_ms' => round($this->percentile($latenciesMs, 99), 2),
                        'max_ms' => round($latenciesMs[count($latenciesMs) - 1] ?? 0, 2),
                    ],
                ];
            } catch (\Throwable $throwable) {
                $error = $throwable;
            } finally {
                foreach ($clients as $clientInfo) {
                    try {
                        $clientInfo['client']->close();
                    } catch (\Throwable) {
                    }
                }
            }
        });

        if ($error !== null) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        foreach ($summary as $key => $value) {
            $this->line("{$key}: ".json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $this->info('Lightspeed load probe completed successfully.');

        return self::SUCCESS;
    }

    private function parseTargets(string $rawTargets): array
    {
        $targets = [];

        foreach (array_filter(array_map('trim', explode(',', $rawTargets))) as $target) {
            [$connectHost, $port] = array_pad(explode(':', $target, 2), 2, null);
            if (!is_string($connectHost) || $connectHost === '' || !is_string($port) || !ctype_digit($port)) {
                continue;
            }

            $targets[] = [
                'connectHost' => $connectHost,
                'port' => (int) $port,
            ];
        }

        return $targets;
    }

    private function connectClient(string $connectHost, string $host, int $port, string $path, bool $tls): Client
    {
        $client = new Client($connectHost, $port, $tls);
        $client->set([
            'timeout' => 10,
            'ssl_host_name' => $host,
            'ssl_verify_peer' => false,
        ]);
        $client->setHeaders([
            'Host' => $host,
            'Origin' => config('app.url'),
        ]);

        if (!$client->upgrade($path)) {
            throw new \RuntimeException("Load probe websocket upgrade failed (target={$connectHost}:{$port}, status={$client->statusCode}, err={$client->errCode}).");
        }

        return $client;
    }

    private function sendEvent(Client $client, string $event, ?string $channel, mixed $data): void
    {
        $payload = [
            'event' => $event,
            'data' => is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR),
        ];

        if ($channel !== null) {
            $payload['channel'] = $channel;
        }

        if ($client->push(json_encode($payload, JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException("Load probe websocket push failed (err={$client->errCode}).");
        }
    }

    private function receiveEvent(Client $client): array
    {
        $frame = $client->recv();
        if ($frame === false || $frame === null) {
            throw new \RuntimeException("Load probe websocket recv failed (err={$client->errCode}).");
        }

        $decoded = json_decode($frame->data, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Load probe expected a JSON object frame.');
        }

        if (is_string($decoded['data'] ?? null)) {
            try {
                $decoded['decoded_data'] = json_decode($decoded['data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded['decoded_data'] = $decoded['data'];
            }
        }

        return $decoded;
    }

    private function receiveEventMatching(Client $client, callable $matches, int $maxFrames = 20): array
    {
        $seen = [];

        for ($i = 0; $i < $maxFrames; $i++) {
            $message = $this->receiveEvent($client);
            $seen[] = $message['event'] ?? 'unknown';

            if ($matches($message)) {
                return $message;
            }
        }

        throw new \RuntimeException('Load probe did not receive the expected event. Saw: '.implode(', ', $seen));
    }

    private function resolveSocketMetadata(ConnectionRegistry $registry, string $socketId): ?array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $metadata = $registry->metadata($socketId);
            if ($metadata !== null) {
                return $metadata;
            }

            Coroutine::sleep(0.01);
        }

        return null;
    }

    private function buildPrivateAuthPayload(string $appKey, string $appSecret, string $socketId, string $channel): array
    {
        $signature = hash_hmac('sha256', "{$socketId}:{$channel}", $appSecret);

        return [
            'channel' => $channel,
            'auth' => "{$appKey}:{$signature}",
        ];
    }

    private function publishEvent(
        string $host,
        string $connectHost,
        int $port,
        bool $tls,
        string $appId,
        string $appKey,
        string $appSecret,
        array $payload,
    ): array {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $path = PusherPaths::eventsPath($appId);
        $query = [
            'auth_key' => $appKey,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $stringToSign = "POST\n{$path}\n{$queryString}";
        $query['auth_signature'] = hash_hmac('sha256', $stringToSign, $appSecret);
        $url = sprintf(
            '%s://%s:%d%s?%s',
            $tls ? 'https' : 'http',
            $connectHost,
            $port,
            $path,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );

        $curl = curl_init();
        if ($curl === false) {
            throw new \RuntimeException('Load probe failed to initialize cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Host: {$host}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 10,
        ]);

        if ($tls) {
            curl_setopt_array($curl, [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
        }

        $rawResponse = curl_exec($curl);
        if ($rawResponse === false) {
            $errno = curl_errno($curl);
            $message = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("Load probe publish failed (curl_errno={$errno}, error={$message}).");
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Load probe publish expected a JSON response.');
        }

        $decoded['status'] = $status;

        return $decoded;
    }

    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        $index = (int) ceil((($percentile / 100) * count($values)) - 1);
        $index = max(0, min(count($values) - 1, $index));

        return (float) $values[$index];
    }

    private function defaultRealtimePath(string $appKey): string
    {
        // The path grammar is PusherPaths', so the probe dials exactly what the
        // server matches on; only the query string is the client's own.
        return PusherPaths::websocketPath(
            config('lightspeed.reverb_compat.path_prefix', PusherPaths::DEFAULT_PATH_PREFIX),
            $appKey,
        ).'?protocol=7&client=js&version=8.4.0&flash=false';
    }
}
