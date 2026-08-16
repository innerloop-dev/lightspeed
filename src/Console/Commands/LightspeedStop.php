<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;

class LightspeedStop extends Command
{
    /** Artisan commands that claim the same realtime ports. */
    private const REALTIME_COMMANDS = ['lightspeed:serve', 'reverb:start', 'octane:start'];

    protected $signature = 'lightspeed:stop
        {--pid-file= : Path to the Lightspeed pid file}
        {--port= : Realtime port to scan, when the server was started on a port other than the configured one}
        {--http-port= : App HTTP port to scan, when the server was started on a port other than the configured one}
        {--force : Send SIGKILL if the process is still running after --grace seconds}
        {--grace=10 : Seconds to wait after SIGTERM before an optional force kill}
        {--no-verify : Do not check that the process looks like a realtime server before signalling}
        {--no-port-scan : Only use the pid file; do not look for listeners on configured ports}';

    protected $description = 'Stop Lightspeed (and any Reverb/Octane process) on the configured ports';

    public function handle(): int
    {
        $pidFile = $this->option('pid-file') ?: config('lightspeed.server.pid_file');
        $pidFile = is_string($pidFile) ? $pidFile : '';

        $grace = max(0, (int) $this->option('grace'));
        $pids = [];

        if ($pidFile !== '' && is_readable($pidFile)) {
            $raw = trim((string) file_get_contents($pidFile));
            if ($raw !== '' && ! ctype_digit($raw)) {
                $this->error('Pid file does not contain a valid PID.');

                return self::FAILURE;
            }

            if ($raw !== '') {
                $pid = (int) $raw;
                if (! $this->processExists($pid)) {
                    $this->components->warn("Stale pid file (process {$pid} not found); removing {$pidFile}.");
                    @unlink($pidFile);
                } elseif (! $this->option('no-verify') && ! $this->looksLikeRealtimeServer($pid)) {
                    $this->error("Process {$pid} does not look like a known realtime server. Refusing to signal. Use --no-verify to override.");

                    return self::FAILURE;
                } else {
                    $pids[] = $pid;
                }
            }
        }

        if (! $this->option('no-port-scan')) {
            foreach ($this->realtimeListenerPidsFromPorts() as $pid) {
                $pids[] = $pid;
            }
        }

        $pids = array_values(array_unique($pids));

        if ($pids === []) {
            if ($pidFile !== '' && ! is_readable($pidFile)) {
                $this->components->info('No pid file and no realtime listener found on configured ports.');
            } else {
                $this->components->info('Lightspeed does not appear to be running.');
            }

            return self::SUCCESS;
        }

        foreach ($pids as $pid) {
            $result = $this->terminatePid($pid, $grace);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        if ($pidFile !== '' && is_file($pidFile)) {
            @unlink($pidFile);
        }

        $this->components->info('Lightspeed stopped.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function realtimeListenerPidsFromPorts(): array
    {
        // `lightspeed:serve --port=` can put the server on a port the config
        // does not name. Scanning only the configured port would then miss that
        // server and signal whatever else happens to be listening there, so the
        // same flags serve accepts are accepted here and win over config.
        $ports = array_unique(array_filter([
            (int) ($this->option('port') ?: config('lightspeed.server.port')),
            (int) ($this->option('http-port') ?: config('lightspeed.server.http_port')),
        ], fn (int $p) => $p > 0));

        $found = [];

        foreach ($ports as $port) {
            foreach ($this->pidsListeningOnPort($port) as $pid) {
                if ($this->option('no-verify')) {
                    $found[] = $pid;

                    continue;
                }

                if ($this->looksLikeRealtimeServer($pid)) {
                    $found[] = $pid;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<int, int>
     */
    private function pidsListeningOnPort(int $port): array
    {
        $output = shell_exec(sprintf('lsof -nP -iTCP:%d -sTCP:LISTEN -t 2>/dev/null', $port));
        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        $pids = [];
        foreach (preg_split('/\s+/', trim($output)) as $token) {
            if (ctype_digit($token)) {
                $pids[] = (int) $token;
            }
        }

        return array_values(array_unique($pids));
    }

    private function terminatePid(int $pid, int $grace): int
    {
        $label = $this->describeProcess($pid) ?? 'pid';
        $this->components->twoColumnDetail("Stopping {$label}", (string) $pid);

        if (! $this->signalProcess($pid, 'term')) {
            if (! $this->processExists($pid)) {
                return self::SUCCESS;
            }
            $this->error("Failed to send SIGTERM to process {$pid}.");

            return self::FAILURE;
        }

        $deadline = microtime(true) + $grace;
        while (microtime(true) < $deadline) {
            usleep(150_000);
            if (! $this->processExists($pid)) {
                return self::SUCCESS;
            }
        }

        if (! $this->option('force')) {
            $this->components->warn("Process {$pid} still running after {$grace}s. Use --force to SIGKILL.");

            return self::FAILURE;
        }

        if (! $this->signalProcess($pid, 'kill')) {
            $this->error("Failed to send SIGKILL to process {$pid}.");

            return self::FAILURE;
        }

        usleep(200_000);
        if ($this->processExists($pid)) {
            $this->error("Process {$pid} still running after SIGKILL.");

            return self::FAILURE;
        }

        $this->components->info("Process {$pid} force-stopped.");

        return self::SUCCESS;
    }

    private function looksLikeRealtimeServer(int $pid): bool
    {
        return $this->describeProcess($pid) !== null;
    }

    private function describeProcess(int $pid): ?string
    {
        $line = strtolower($this->commandLineForPid($pid));

        foreach (self::REALTIME_COMMANDS as $cmd) {
            if (str_contains($line, $cmd)) {
                return $cmd;
            }
        }

        return null;
    }

    private function commandLineForPid(int $pid): string
    {
        if (@is_readable('/proc/'.(int) $pid.'/cmdline')) {
            $raw = @file_get_contents('/proc/'.(int) $pid.'/cmdline');

            return is_string($raw) ? str_replace("\0", ' ', $raw) : '';
        }

        $out = shell_exec('ps -p '.(int) $pid.' -o args= 2>/dev/null');

        return is_string($out) ? trim($out) : '';
    }

    private function processExists(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        exec('ps -p '.(int) $pid.' -o pid= 2>/dev/null', $_, $code);

        return $code === 0;
    }

    private function signalProcess(int $pid, string $kind): bool
    {
        if (function_exists('posix_kill')) {
            $sig = $kind === 'kill' ? SIGKILL : SIGTERM;

            return @posix_kill($pid, $sig);
        }

        $flag = $kind === 'kill' ? '-9' : '-15';
        exec('kill '.$flag.' '.(int) $pid.' 2>/dev/null', $_, $code);

        return $code === 0;
    }
}
