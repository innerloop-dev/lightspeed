<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Lightspeed\Protocol\PusherPaths;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

class LightspeedProbe extends Command
{
    protected $signature = 'lightspeed:probe
        {--host= : Hostname to connect to (defaults to lightspeed.reverb_compat.public_host)}
        {--connect-host=127.0.0.1 : Network host to connect to}
        {--port= : Port to connect to (defaults to lightspeed.reverb_compat.public_port)}
        {--path= : WebSocket path override}
        {--tls= : Use TLS (1 or 0, defaults to lightspeed.reverb_compat.public_scheme)}
        {--private-channel=private-dev.local : Private channel name for probe traffic}
        {--presence-channel=presence-dev.local : Presence channel name for probe traffic}
        {--app-key= : Realtime app key override}
        {--app-secret= : Realtime app secret override}';

    protected $description = 'Run a local multi-client probe against the Lightspeed server';

    public function handle(): int
    {
        $host = (string) ($this->option('host') ?: config('lightspeed.reverb_compat.public_host', 'localhost'));
        $connectHost = (string) $this->option('connect-host');
        $port = (int) ($this->option('port') ?: config('lightspeed.reverb_compat.public_port', 443));
        $tls = $this->option('tls') === null || $this->option('tls') === ''
            ? config('lightspeed.reverb_compat.public_scheme', 'https') === 'https'
            : (filter_var($this->option('tls'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
        $privateChannel = (string) $this->option('private-channel');
        $presenceChannel = (string) $this->option('presence-channel');
        $appKey = (string) ($this->option('app-key') ?: config('lightspeed.reverb_compat.app_key'));
        $appSecret = (string) ($this->option('app-secret') ?: config('lightspeed.reverb_compat.app_secret'));
        $path = (string) ($this->option('path') ?: $this->defaultPath($appKey));

        $this->components->twoColumnDetail('Host', $host);
        $this->components->twoColumnDetail('Connect Host', $connectHost);
        $this->components->twoColumnDetail('Port', (string) $port);
        $this->components->twoColumnDetail('TLS', $tls ? 'yes' : 'no');
        $this->components->twoColumnDetail('Path', $path);
        $this->components->twoColumnDetail('Private', $privateChannel);
        $this->components->twoColumnDetail('Presence', $presenceChannel);

        $results = [];
        $error = null;

        Coroutine\run(function () use (
            $host,
            $connectHost,
            $port,
            $path,
            $tls,
            $privateChannel,
            $presenceChannel,
            $appKey,
            $appSecret,
            &$results,
            &$error,
        ): void {
            try {
                $clientA = $this->connectClient($connectHost, $host, $port, $path, $tls);
                $clientB = $this->connectClient($connectHost, $host, $port, $path, $tls);

                $results['connectedA'] = $this->receiveEvent($clientA);
                $results['connectedB'] = $this->receiveEvent($clientB);

                $socketIdA = (string) ($results['connectedA']['decoded_data']['socket_id'] ?? '');
                $socketIdB = (string) ($results['connectedB']['decoded_data']['socket_id'] ?? '');

                if ($socketIdA === '' || $socketIdB === '') {
                    throw new \RuntimeException('Missing socket_id in handshake response.');
                }

                $this->sendEvent($clientA, 'pusher:ping', null, new \stdClass());
                $results['pongA'] = $this->receiveEvent($clientA);

                $this->sendEvent($clientA, 'pusher:subscribe', null, $this->buildPrivateAuthPayload(
                    $appKey,
                    $appSecret,
                    $socketIdA,
                    $privateChannel,
                ));
                $results['privateSubscribedA'] = $this->receiveEvent($clientA);

                $presenceAuthA = $this->buildPresenceAuthPayload(
                    $appKey,
                    $appSecret,
                    $socketIdA,
                    $presenceChannel,
                    'probe-a',
                    ['name' => 'Probe A', 'tabId' => 'probe-a'],
                );
                $this->sendEvent($clientA, 'pusher:subscribe', null, $presenceAuthA);
                $results['presenceSubscribedA'] = $this->receiveEvent($clientA);

                $presenceAuthB = $this->buildPresenceAuthPayload(
                    $appKey,
                    $appSecret,
                    $socketIdB,
                    $presenceChannel,
                    'probe-b',
                    ['name' => 'Probe B', 'tabId' => 'probe-b'],
                );
                $this->sendEvent($clientB, 'pusher:subscribe', null, $presenceAuthB);
                $results['presenceSubscribedB'] = $this->receiveEventMatching(
                    $clientB,
                    static fn (array $message): bool => ($message['event'] ?? null) === 'pusher_internal:subscription_succeeded'
                );
                $results['memberAddedA'] = $this->receiveEventMatching(
                    $clientA,
                    static fn (array $message): bool => ($message['event'] ?? null) === 'pusher_internal:member_added'
                );

                $this->sendEvent($clientA, 'client-selection', $presenceChannel, [
                    'cursor' => ['x' => 7, 'y' => 11],
                ]);
                $results['clientEventB'] = $this->receiveEventMatching(
                    $clientB,
                    static fn (array $message): bool => ($message['event'] ?? null) === 'client-selection'
                );

                $this->sendEvent($clientB, 'pusher:unsubscribe', null, [
                    'channel' => $presenceChannel,
                ]);
                $results['memberRemovedA'] = $this->receiveEvent($clientA);

                $clientA->close();
                $clientB->close();
            } catch (\Throwable $throwable) {
                $error = $throwable;
            }
        });

        if ($error !== null) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        $this->assertProbeResults($results);

        foreach ($results as $label => $payload) {
            $this->line("{$label}: ".json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $this->info('Lightspeed probe completed successfully.');

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

        $ok = $client->push(json_encode($payload, JSON_THROW_ON_ERROR));
        if ($ok === false) {
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

        throw new \RuntimeException('Probe did not receive the expected realtime event. Saw: '.implode(', ', $seen));
    }

    private function assertProbeResults(array $results): void
    {
        $expectedEvents = [
            'connectedA' => 'pusher:connection_established',
            'connectedB' => 'pusher:connection_established',
            'pongA' => 'pusher:pong',
            'privateSubscribedA' => 'pusher_internal:subscription_succeeded',
            'presenceSubscribedA' => 'pusher_internal:subscription_succeeded',
            'presenceSubscribedB' => 'pusher_internal:subscription_succeeded',
            'memberAddedA' => 'pusher_internal:member_added',
            'clientEventB' => 'client-selection',
            'memberRemovedA' => 'pusher_internal:member_removed',
        ];

        foreach ($expectedEvents as $key => $event) {
            $actual = $results[$key]['event'] ?? null;
            if ($actual !== $event) {
                $actualLabel = is_string($actual) ? $actual : gettype($actual);
                throw new \RuntimeException("Probe assertion failed for [{$key}]: expected event [{$event}], got [{$actualLabel}].");
            }
        }

        if (($results['privateSubscribedA']['channel'] ?? null) === null) {
            throw new \RuntimeException('Probe assertion failed for [privateSubscribedA]: missing channel.');
        }

        $presenceSnapshot = $results['presenceSubscribedB']['decoded_data']['presence'] ?? null;
        if (!is_array($presenceSnapshot) || ($presenceSnapshot['count'] ?? 0) < 2) {
            throw new \RuntimeException('Probe assertion failed for [presenceSubscribedB]: missing presence snapshot.');
        }
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

    private function buildPrivateAuthPayload(string $appKey, string $appSecret, string $socketId, string $channel): array
    {
        $signature = hash_hmac('sha256', "{$socketId}:{$channel}", $appSecret);

        return [
            'channel' => $channel,
            'auth' => "{$appKey}:{$signature}",
        ];
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
