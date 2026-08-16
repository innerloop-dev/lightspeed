<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

class LightspeedRelayProbe extends Command
{
    protected $signature = 'lightspeed:relay-probe
        {--host= : Hostname to connect to (defaults to lightspeed.reverb_compat.public_host)}
        {--connect-host=127.0.0.1 : Network host to connect to}
        {--port= : Port to connect to (defaults to lightspeed.reverb_compat.public_port)}
        {--path=/ : WebSocket path override}
        {--tls= : Use TLS (1 or 0, defaults to lightspeed.reverb_compat.public_scheme)}
        {--channel=relay.dev.local : Channel name to carry the relay traffic}
        {--event=relay.probe : Event name to broadcast}
        {--clients=12 : Number of clients to open while searching for multiple workers}';

    protected $description = 'Verify Lightspeed Redis relay delivery across multiple workers';

    public function handle(): int
    {
        $host = (string) ($this->option('host') ?: config('lightspeed.reverb_compat.public_host', 'localhost'));
        $connectHost = (string) $this->option('connect-host');
        $port = (int) ($this->option('port') ?: config('lightspeed.reverb_compat.public_port', 443));
        $path = (string) $this->option('path');
        $tls = $this->option('tls') === null || $this->option('tls') === ''
            ? config('lightspeed.reverb_compat.public_scheme', 'https') === 'https'
            : (filter_var($this->option('tls'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
        $channel = (string) $this->option('channel');
        $event = (string) $this->option('event');
        $clientCount = max(2, (int) $this->option('clients'));

        // This probe speaks the diagnostic protocol, which the server refuses
        // unless it is enabled and the caller is on the loopback interface.
        if (!(bool) config('lightspeed.diagnostics.enabled', false)) {
            $this->error('The relay probe uses the Lightspeed diagnostic protocol, which is disabled.');
            $this->line('Set LIGHTSPEED_DIAGNOSTICS_ENABLED=true on the server you are probing, restart it, then run this again.');
            $this->line('Leave it disabled anywhere the server is reachable from outside the machine.');

            return self::FAILURE;
        }

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
            $event,
            $clientCount,
            &$summary,
            &$error,
        ): void {
            $clients = [];

            try {
                // Which worker accepts a connection is the server's business,
                // so opening a fixed number of clients and demanding two
                // distinct workers fails by luck on a healthy server. Keep
                // connecting until two workers have answered, up to a ceiling.
                $maxClients = $clientCount * 4;
                $byProcess = [];
                $index = 0;

                while ($index < $maxClients && count($byProcess) < 2) {
                    $client = $this->connectClient($connectHost, $host, $port, $path, $tls);
                    $hello = $this->receiveFrame($client);

                    if (($hello['type'] ?? null) !== 'hello') {
                        throw new \RuntimeException("Relay probe expected hello frame for client {$index}.");
                    }

                    $requestId = "whoami-{$index}";
                    $this->sendMessage($client, [
                        'type' => 'send',
                        'id' => $requestId,
                        'action' => 'whoami',
                        'data' => new \stdClass(),
                    ]);

                    $whoami = $this->receiveFrame($client);
                    if (($whoami['type'] ?? null) !== 'response' || ($whoami['ok'] ?? false) !== true) {
                        throw new \RuntimeException("Relay probe expected successful whoami response for client {$index}.");
                    }

                    $whoamiData = $whoami['data'] ?? null;
                    if (!is_array($whoamiData)) {
                        throw new \RuntimeException("Relay probe expected whoami data for client {$index}.");
                    }

                    $processKey = $whoamiData['processKey'] ?? null;
                    if (!is_string($processKey) || $processKey === '') {
                        throw new \RuntimeException("Relay probe expected processKey for client {$index}.");
                    }

                    $clients[] = [
                        'index' => $index,
                        'client' => $client,
                        'whoami' => $whoamiData,
                    ];

                    $byProcess[$processKey][] = $clients[count($clients) - 1];
                    $index++;
                }

                if (count($byProcess) < 2) {
                    // Every one of many connections landing on one worker is
                    // not bad luck, so report what was seen rather than
                    // guessing at the operator's configuration.
                    $onlyProcess = (string) array_key_first($byProcess);

                    throw new \RuntimeException(
                        "Relay probe opened {$index} connections and every one was accepted by the same worker "
                        ."({$onlyProcess}), so cross-worker relay cannot be exercised. Start the server with "
                        .'--workers=2 or more, or point the probe at a server that has them.'
                    );
                }

                $processKeys = array_keys($byProcess);
                $sender = $byProcess[$processKeys[0]][0];
                $receiver = $byProcess[$processKeys[1]][0];

                $this->sendMessage($receiver['client'], [
                    'type' => 'subscribe',
                    'channel' => $channel,
                ]);

                $subscribed = $this->receiveFrame($receiver['client']);
                if (($subscribed['type'] ?? null) !== 'subscribed' || ($subscribed['channel'] ?? null) !== $channel) {
                    throw new \RuntimeException('Relay probe expected subscribed acknowledgement on receiver.');
                }

                $payload = [
                    'message' => 'relay-ok',
                    'senderProcessKey' => $sender['whoami']['processKey'],
                    'receiverProcessKey' => $receiver['whoami']['processKey'],
                ];

                $requestId = 'broadcast-test';
                $this->sendMessage($sender['client'], [
                    'type' => 'send',
                    'id' => $requestId,
                    'action' => 'broadcast-test',
                    'data' => [
                        'channel' => $channel,
                        'event' => $event,
                        'payload' => $payload,
                    ],
                ]);

                $senderResponse = $this->receiveFrame($sender['client']);
                if (($senderResponse['type'] ?? null) !== 'response' || ($senderResponse['ok'] ?? false) !== true) {
                    throw new \RuntimeException('Relay probe expected successful broadcast-test response from sender.');
                }

                $receiverBroadcast = $this->receiveFrame($receiver['client']);
                if (($receiverBroadcast['type'] ?? null) !== 'broadcast') {
                    throw new \RuntimeException('Relay probe expected broadcast frame on receiver.');
                }

                if (($receiverBroadcast['channel'] ?? null) !== $channel) {
                    throw new \RuntimeException('Relay probe received broadcast on the wrong channel.');
                }

                if (($receiverBroadcast['event'] ?? null) !== $event) {
                    throw new \RuntimeException('Relay probe received the wrong event name.');
                }

                if (($receiverBroadcast['data']['message'] ?? null) !== 'relay-ok') {
                    throw new \RuntimeException('Relay probe received the wrong payload.');
                }

                $summary = [
                    'workers' => array_map(
                        static fn (array $entries) => $entries[0]['whoami'],
                        $byProcess,
                    ),
                    'sender' => $sender['whoami'],
                    'receiver' => $receiver['whoami'],
                    'broadcast' => $receiverBroadcast,
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

        foreach ($summary['workers'] ?? [] as $worker) {
            $this->line('worker: '.json_encode($worker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $this->line('sender: '.json_encode($summary['sender'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->line('receiver: '.json_encode($summary['receiver'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->line('broadcast: '.json_encode($summary['broadcast'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info('Lightspeed relay probe completed successfully.');

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

    private function sendMessage(Client $client, array $payload): void
    {
        $ok = $client->push(json_encode($payload, JSON_THROW_ON_ERROR));
        if ($ok === false) {
            throw new \RuntimeException("WebSocket push failed (err={$client->errCode}).");
        }
    }

    private function receiveFrame(Client $client): array
    {
        $frame = $client->recv();
        if ($frame === false || $frame === null) {
            throw new \RuntimeException("WebSocket recv failed (err={$client->errCode}).");
        }

        $decoded = json_decode($frame->data, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Expected JSON object frame.');
        }

        return $decoded;
    }
}
