<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Lightspeed\Server;
use Swoole\Exception as SwooleException;

class LightspeedServe extends Command
{
    protected $signature = 'lightspeed:serve
        {--host= : Override the bind host}
        {--port= : Override the shared bind port}
        {--realtime-port= : Override the realtime bind port}
        {--http-port= : Override the app HTTP bind port}
        {--workers= : Override the Swoole worker count}
        {--task-workers= : Override the Swoole task worker count}
        {--relay= : Override Redis relay enabled state (1 or 0)}
        {--instance-id= : Override the worker instance identity for relay routing}
        {--log-verbose : Enable HTTP and websocket access logs}
        {--log-http= : Override HTTP access logging (1 or 0)}
        {--log-websocket= : Override websocket access logging (1 or 0)}
        {--log-payloads= : Override payload snippet logging (1 or 0)}
        {--log-payload-limit= : Override logged payload snippet size in bytes}';

    protected $description = 'Start the local Lightspeed Swoole server';

    public function handle(Server $lightspeed): int
    {
        $host = $this->option('host') ?: config('lightspeed.server.host', '0.0.0.0');
        $sharedPort = $this->option('port');
        $realtimePort = (int) ($this->option('realtime-port') ?: $sharedPort ?: config('lightspeed.server.port', 8000));
        $httpPort = (int) ($this->option('http-port') ?: $sharedPort ?: config('lightspeed.server.http_port', $realtimePort));
        $workerNum = (int) ($this->option('workers') ?: config('lightspeed.server.worker_num', 1));
        $taskWorkerNum = (int) ($this->option('task-workers') ?: config('lightspeed.server.task_worker_num', 0));
        $relayEnabled = $this->option('relay') === null
            ? (bool) config('lightspeed.relay.enabled', true)
            : (filter_var($this->option('relay'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
        $instanceId = $this->option('instance-id') ?: config('lightspeed.server.instance_id');
        $logVerbose = (bool) $this->option('log-verbose');
        $httpLogging = $this->resolveLoggingOverride('log-http', $logVerbose ? true : null, 'lightspeed.logging.http');
        $websocketLogging = $this->resolveLoggingOverride('log-websocket', $logVerbose ? true : null, 'lightspeed.logging.websocket');
        $payloadLogging = $this->resolveLoggingOverride('log-payloads', null, 'lightspeed.logging.payloads');
        $payloadLimit = (int) ($this->option('log-payload-limit') ?: config('lightspeed.logging.payload_limit', 600));

        config([
            'lightspeed.server.host' => $host,
            'lightspeed.server.port' => $realtimePort,
            'lightspeed.server.http_port' => $httpPort,
            'lightspeed.server.worker_num' => $workerNum,
            'lightspeed.server.task_worker_num' => $taskWorkerNum,
            'lightspeed.server.instance_id' => $instanceId,
            'lightspeed.relay.enabled' => $relayEnabled,
            'lightspeed.logging.http' => $httpLogging,
            'lightspeed.logging.websocket' => $websocketLogging,
            'lightspeed.logging.payloads' => $payloadLogging,
            'lightspeed.logging.payload_limit' => $payloadLimit,
        ]);

        $settings = [
            'http_port' => $httpPort,
            'enable_coroutine' => (bool) config('lightspeed.server.enable_coroutine', false),
            'http_compression' => (bool) config('lightspeed.server.http_compression', false),
            'worker_num' => $workerNum,
            'task_worker_num' => $taskWorkerNum,
            'package_max_length' => (int) config('lightspeed.server.package_max_length', 2 * 1024 * 1024),
            'heartbeat_idle_time' => (int) config('lightspeed.server.heartbeat_idle_time', 0),
            'heartbeat_check_interval' => (int) config('lightspeed.server.heartbeat_check_interval', 0),
            'log_file' => config('lightspeed.server.log_file'),
            'pid_file' => config('lightspeed.server.pid_file'),
        ];

        $this->components->twoColumnDetail('Host', $host);
        $this->components->twoColumnDetail('HTTP Port', (string) $httpPort);
        $this->components->twoColumnDetail('Realtime Port', (string) $realtimePort);

        if ($httpPort === $realtimePort) {
            $this->components->twoColumnDetail('Public Shape', 'same-host / same-port');
        }
        $this->components->twoColumnDetail('Coroutines', $settings['enable_coroutine'] ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('HTTP Compression', $settings['http_compression'] ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Workers', (string) $settings['worker_num']);
        $this->components->twoColumnDetail('Task Workers', (string) $settings['task_worker_num']);
        // Printed only when it is on, because it is the one setting here that
        // closes working connections, and an operator debugging a reconnect
        // loop should find it in the banner rather than in a config file.
        //
        // Gated on the PAIR, exactly as Server::swooleOptions() is: half a
        // heartbeat arms nothing, and a banner announcing one ("checked every
        // 0s") would send that operator hunting for a reaper that is not there.
        if ($settings['heartbeat_idle_time'] > 0 && $settings['heartbeat_check_interval'] > 0) {
            $this->components->twoColumnDetail('Heartbeat', sprintf(
                'idle %ds, checked every %ds',
                $settings['heartbeat_idle_time'],
                $settings['heartbeat_check_interval'],
            ));
        }
        $this->components->twoColumnDetail('Relay', $relayEnabled ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('HTTP Logs', $httpLogging ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Websocket Logs', $websocketLogging ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Payload Logs', $payloadLogging ? 'enabled' : 'disabled');

        if (is_string($instanceId) && $instanceId !== '') {
            $this->components->twoColumnDetail('Instance ID', $instanceId);
        }

        // Channel-auth callbacks register on the DEFAULT connection's broadcaster
        // at boot, so that broadcaster has to be this one; flipping it here would
        // be too late for auth. What matters is the driver, never the connection's
        // name: 'realtime' => ['driver' => 'lightspeed'] is a perfectly good
        // configuration, and refusing it was a bug that outlived three rounds of
        // review because the check read like a requirement rather than a mistake.
        $defaultConnection = (string) config('broadcasting.default');
        $defaultDriver = (string) config("broadcasting.connections.{$defaultConnection}.driver", '');

        if ($defaultDriver !== 'lightspeed') {
            $this->error(sprintf(
                "Broadcasting default connection is '%s', whose driver is '%s'. Lightspeed needs the default connection to use the 'lightspeed' driver, so channel-auth callbacks register on this broadcaster. The connection can be called anything.",
                $defaultConnection,
                $defaultDriver === '' ? 'not set' : $defaultDriver,
            ));

            return self::FAILURE;
        }

        try {
            $lightspeed->serve($host, $realtimePort, $settings);
        } catch (SwooleException $e) {
            $this->error($e->getMessage());

            if (str_contains($e->getMessage(), 'Address already in use')) {
                $this->line('Tip: stop the current process on that port or use --http-port / --realtime-port to run Lightspeed elsewhere.');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveLoggingOverride(string $optionName, ?bool $fallback, string $configKey): bool
    {
        if ($this->option($optionName) === null) {
            return $fallback ?? (bool) config($configKey, false);
        }

        return filter_var($this->option($optionName), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? ($fallback ?? (bool) config($configKey, false));
    }
}
