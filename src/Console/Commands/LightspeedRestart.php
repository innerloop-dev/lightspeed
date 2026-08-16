<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;

class LightspeedRestart extends Command
{
    protected $signature = 'lightspeed:restart
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
        {--log-payload-limit= : Override logged payload snippet size in bytes}
        {--pid-file= : Path to the Lightspeed pid file for shutdown}
        {--stop-force : When stopping, SIGKILL if the server does not exit in time}
        {--stop-grace=10 : Seconds to wait for SIGTERM shutdown before optional force kill}';

    protected $description = 'Stop Lightspeed if running, then start lightspeed:serve with the given options';

    public function handle(): int
    {
        $stopCode = $this->call('lightspeed:stop', array_filter([
            '--pid-file' => $this->option('pid-file'),
            '--force' => $this->option('stop-force') ?: null,
            '--grace' => (string) (int) $this->option('stop-grace'),
        ], fn ($v) => $v !== null && $v !== ''));

        if ($stopCode !== self::SUCCESS) {
            return $stopCode;
        }

        return $this->call('lightspeed:serve', $this->serveArguments());
    }

    /**
     * @return array<string, mixed>
     */
    private function serveArguments(): array
    {
        $arguments = [];

        foreach (['host', 'port', 'realtime-port', 'http-port', 'workers', 'task-workers', 'relay', 'instance-id', 'log-http', 'log-websocket', 'log-payloads', 'log-payload-limit'] as $name) {
            $value = $this->option($name);
            if ($value !== null && $value !== '') {
                $arguments['--'.$name] = $value;
            }
        }

        if ($this->option('log-verbose')) {
            $arguments['--log-verbose'] = true;
        }

        return $arguments;
    }
}
