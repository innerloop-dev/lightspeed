<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Lightspeed\Protocol\PusherPaths;
use Lightspeed\Connections\ConnectionRegistry;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

class LightspeedPresenceProbe extends Command
{
    protected $signature = 'lightspeed:presence-probe
        {--host= : Hostname to connect to (defaults to lightspeed.reverb_compat.public_host)}
        {--connect-host=127.0.0.1 : Network host to connect to}
        {--port= : Port to connect to (defaults to lightspeed.reverb_compat.public_port)}
        {--path= : WebSocket path override}
        {--tls= : Use TLS (1 or 0, defaults to lightspeed.reverb_compat.public_scheme)}
        {--channel=presence-dev.local : Presence channel name for probe traffic}
        {--app-key= : Realtime app key override}
        {--app-secret= : Realtime app secret override}
        {--clients=12 : Number of clients to open while searching for multiple workers}';

    protected $description = 'Verify Lightspeed shared presence across multiple workers';

    public function handle(ConnectionRegistry $registry): int
    {
        $host = (string) ($this->option('host') ?: config('lightspeed.reverb_compat.public_host', 'localhost'));
        $connectHost = (string) $this->option('connect-host');
        $port = (int) ($this->option('port') ?: config('lightspeed.reverb_compat.public_port', 443));
        $tls = $this->option('tls') === null || $this->option('tls') === ''
            ? config('lightspeed.reverb_compat.public_scheme', 'https') === 'https'
            : (filter_var($this->option('tls'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
        $channel = (string) $this->option('channel');
        $appKey = (string) ($this->option('app-key') ?: config('lightspeed.reverb_compat.app_key'));
        $appSecret = (string) ($this->option('app-secret') ?: config('lightspeed.reverb_compat.app_secret'));
        $path = (string) ($this->option('path') ?: $this->defaultPath($appKey));
        $clientCount = max(2, (int) $this->option('clients'));

        $this->components->twoColumnDetail('Host', $host);
        $this->components->twoColumnDetail('Connect Host', $connectHost);
        $this->components->twoColumnDetail('Port', (string) $port);
        $this->components->twoColumnDetail('Path', $path);
        $this->components->twoColumnDetail('TLS', $tls ? 'yes' : 'no');
        $this->components->twoColumnDetail('Channel', $channel);
        $this->components->twoColumnDetail('Clients', (string) $clientCount);

        $summary = [];
        $error = null;

        Coroutine\run(function () use (
            $connectHost,
            $host,
            $port,
            $path,
            $tls,
            $channel,
            $appKey,
            $appSecret,
            $clientCount,
            $registry,
            &$summary,
            &$error,
        ): void {
            $clients = [];

            try {
                // Which worker accepts a connection is the server's business,
                // so opening a fixed number of clients and demanding two
                // distinct workers fails by luck on a perfectly healthy
                // server. Keep opening connections until two workers have
                // answered, up to a hard ceiling.
                $maxClients = $clientCount * 4;
                $byProcess = [];
                $index = 0;

                while ($index < $maxClients && count($byProcess) < 2) {
                    $client = $this->connectClient($connectHost, $host, $port, $path, $tls);
                    $connected = $this->receiveEvent($client);

                    $socketId = (string) ($connected['decoded_data']['socket_id'] ?? '');
                    if ($socketId === '') {
                        throw new \RuntimeException("Presence probe expected socket_id for client {$index}.");
                    }

                    $metadata = $this->resolveSocketMetadata($registry, $socketId);
                    if (!is_array($metadata) || !is_string($metadata['process_key'] ?? null)) {
                        throw new \RuntimeException("Presence probe could not resolve process key for client {$index}.");
                    }

                    $clients[] = [
                        'index' => $index,
                        'client' => $client,
                        'socketId' => $socketId,
                        'metadata' => $metadata,
                    ];

                    $byProcess[$metadata['process_key']][] = $clients[count($clients) - 1];
                    $index++;
                }

                if (count($byProcess) < 2) {
                    // Every one of a lot of connections landing on the same
                    // worker is not bad luck, so name what was actually seen
                    // instead of guessing at the operator's configuration.
                    $onlyProcess = (string) array_key_first($byProcess);

                    throw new \RuntimeException(
                        "Presence probe opened {$index} connections and every one was accepted by the same worker "
                        ."({$onlyProcess}), so cross-worker presence cannot be exercised. Start the server with "
                        .'--workers=2 or more, or point the probe at a server that has them.'
                    );
                }

                $processKeys = array_keys($byProcess);
                $clientA = $byProcess[$processKeys[0]][0];
                $clientB = $byProcess[$processKeys[1]][0];

                $this->sendEvent($clientA['client'], 'pusher:subscribe', null, $this->buildPresenceAuthPayload(
                    $appKey,
                    $appSecret,
                    $clientA['socketId'],
                    $channel,
                    'presence-probe-a',
                    ['name' => 'Presence Probe A'],
                ));
                $subscribedA = $this->receiveEvent($clientA['client']);

                $this->sendEvent($clientB['client'], 'pusher:subscribe', null, $this->buildPresenceAuthPayload(
                    $appKey,
                    $appSecret,
                    $clientB['socketId'],
                    $channel,
                    'presence-probe-b',
                    ['name' => 'Presence Probe B'],
                ));

                $subscribedB = $this->receiveEvent($clientB['client']);
                $memberAddedA = $this->receiveEvent($clientA['client']);

                $this->sendEvent($clientB['client'], 'pusher:unsubscribe', null, [
                    'channel' => $channel,
                ]);
                $memberRemovedA = $this->receiveEvent($clientA['client']);

                $presenceSnapshot = $subscribedB['decoded_data']['presence'] ?? null;
                if (!is_array($presenceSnapshot) || ($presenceSnapshot['count'] ?? 0) < 2) {
                    throw new \RuntimeException('Presence probe expected a shared presence snapshot with both users.');
                }

                if (($memberAddedA['event'] ?? null) !== 'pusher_internal:member_added') {
                    throw new \RuntimeException('Presence probe expected member_added on the first client.');
                }

                if (($memberRemovedA['event'] ?? null) !== 'pusher_internal:member_removed') {
                    throw new \RuntimeException('Presence probe expected member_removed on the first client.');
                }

                $summary = [
                    'clientA' => [
                        'socketId' => $clientA['socketId'],
                        'metadata' => $clientA['metadata'],
                    ],
                    'clientB' => [
                        'socketId' => $clientB['socketId'],
                        'metadata' => $clientB['metadata'],
                    ],
                    'subscribedA' => $subscribedA,
                    'subscribedB' => $subscribedB,
                    'memberAddedA' => $memberAddedA,
                    'memberRemovedA' => $memberRemovedA,
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

        foreach (['clientA', 'clientB', 'subscribedA', 'subscribedB', 'memberAddedA', 'memberRemovedA'] as $key) {
            $this->line("{$key}: ".json_encode($summary[$key] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $this->info('Lightspeed presence probe completed successfully.');

        return self::SUCCESS;
    }

    private function connectClient(string $connectHost, string $host, int $port, string $path, bool $tls): Client
    {
        $client = new Client($connectHost, $port, $tls);
        $client->set([
            'timeout' => 5,
            'ssl_host_name' => $host,
            'ssl_verify_peer' => false,
        ]);
        $client->setHeaders([
            'Host' => $host,
            'Origin' => config('app.url'),
        ]);

        if (!$client->upgrade($path)) {
            throw new \RuntimeException("WebSocket upgrade failed (connectHost={$connectHost}, host={$host}, status={$client->statusCode}, err={$client->errCode}).");
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
            throw new \RuntimeException("WebSocket push failed (err={$client->errCode}).");
        }
    }

    private function receiveEvent(Client $client): array
    {
        $frame = $client->recv();
        if ($frame === false || $frame === null) {
            throw new \RuntimeException("WebSocket recv failed (err={$client->errCode}).");
        }

        $decoded = json_decode($frame->data, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Expected JSON object frame.');
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

    private function defaultPath(string $appKey): string
    {
        // The path grammar is PusherPaths', so the probe dials exactly what the
        // server matches on; only the query string is the client's own.
        return PusherPaths::websocketPath(
            config('lightspeed.reverb_compat.path_prefix', PusherPaths::DEFAULT_PATH_PREFIX),
            $appKey,
        ).'?protocol=7&client=js&version=8.4.0&flash=false';
    }

    private function buildPresenceAuthPayload(
        string $appKey,
        string $appSecret,
        string $socketId,
        string $channel,
        string $userId,
        array $userInfo,
    ): array {
        $channelData = json_encode([
            'user_id' => $userId,
            'user_info' => $userInfo,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', "{$socketId}:{$channel}:{$channelData}", $appSecret);

        return [
            'channel' => $channel,
            'auth' => "{$appKey}:{$signature}",
            'channel_data' => $channelData,
        ];
    }
}
