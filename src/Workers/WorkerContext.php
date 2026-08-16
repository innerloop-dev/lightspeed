<?php

namespace Lightspeed\Workers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Lightspeed\Relay\RedisRelay;
use Swoole\WebSocket\Server;

/**
 * Per-worker runtime identity shared by the package coordination services.
 *
 * Why this file exists: owner routing, presence, the relay, the connection
 * registry, and the runtime logger all key off one "process key" that must be
 * identical for every module inside a process and unique across processes.
 * This class is the single place that decides it.
 *
 * Lifecycle (there is no uninitialized state a caller can observe):
 *
 *   inside a worker      boot($server, $workerId)  -> "<instance>:worker:<n>"
 *   anywhere else        first read of the key     -> "external:<pid>"
 *   worker stop          shutdown()                -> identity cleared
 *
 * The external identity is resolved lazily on first read, which is what keeps
 * probes, tests, and HTTP-only paths working without a boot call. The one
 * ordering mistake that used to be silent is now loud: if something reads the
 * process key inside a worker before the worker identity is bound, that read
 * is served the external key, and the later boot() in the same process throws
 * rather than quietly moving the key out from under the modules that already
 * published Redis state under it.
 *
 * boot() is called today by RedisRelay::bootWorker, the first hook
 * that has both the server and the worker id.
 *
 * Owns: process key, instance id, worker id, and the identity payload.
 * Deliberately does not own: the Redis keys or ownership rules built on top of
 * that identity; those belong to the presence store, resource router, and
 * owner-command bus.
 */
class WorkerContext
{
    private ?Server $server = null;

    private ?int $workerId = null;

    private ?string $instanceId = null;

    /**
     * PID of the process that ran serve(), captured before any fork.
     *
     * The one pid that is the same in every worker. `$server->master_pid`
     * read inside a forked child is not: measured live, a respawned worker
     * reports its own pid there, so two workers of one server derived two
     * instance ids and split every identity-keyed Redis structure in half.
     */
    private ?int $servingPid = null;

    /** PID that was already served the external fallback key, if any. */
    private ?int $externalKeyPid = null;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Record the serving process's pid, in that process, before it forks.
     *
     * Called by Server::serve() ahead of $server->start(); every worker
     * inherits the value through the fork. Without it, instance identity
     * falls back to the current process, which is only correct where no
     * fork happened (tests, probes, HTTP-only paths).
     */
    public function bindServingPid(int $pid): void
    {
        $this->servingPid = $pid;
    }

    /**
     * Bind the current worker identity to an attached Swoole server.
     *
     * @throws \RuntimeException if this process already handed out the external
     *                           process key, which means some module is holding
     *                           an identity this call would invalidate.
     */
    public function boot(Server $server, int $workerId): void
    {
        // The PID check matters: workers are forked from the master, so a
        // master that ever read the key leaves $externalKeyPid set in every
        // child. Only a read by *this* process is an ordering error.
        if ($this->externalKeyPid === getmypid()) {
            throw new \RuntimeException(
                'WorkerContext::boot() ran after this process already used the external '
                . 'process key. Worker identity must be bound before any module reads the key.'
            );
        }

        $this->server = $server;
        $this->workerId = $workerId;
        $this->instanceId = $this->configuredInstanceId();
        $this->externalKeyPid = null;
    }

    /** Clear the current worker identity when the worker stops. */
    public function shutdown(): void
    {
        $this->server = null;
        $this->workerId = null;
        $this->instanceId = null;
        $this->externalKeyPid = null;
    }

    public function server(): ?Server
    {
        return $this->server;
    }

    public function workerId(): ?int
    {
        return $this->workerId;
    }

    public function instanceId(): ?string
    {
        return $this->instanceId;
    }

    /** Return the stable instance+worker identifier used in Redis metadata. */
    public function workerKey(): ?string
    {
        if ($this->instanceId === null || $this->workerId === null) {
            return null;
        }

        return "{$this->instanceId}:worker:{$this->workerId}";
    }

    /**
     * Return the active process key, falling back to the current PID.
     *
     * The fallback is a real identity, not a placeholder: HTTP-only requests,
     * artisan probes, and tests legitimately run outside a booted worker and
     * still need a stable key. Taking it is recorded so that a boot() arriving
     * afterwards in the same process fails loudly instead of silently
     * renaming the process mid-flight.
     */
    public function currentProcessKey(): string
    {
        $workerKey = $this->workerKey();
        if ($workerKey !== null) {
            return $workerKey;
        }

        $this->externalKeyPid = getmypid();

        return sprintf('external:%d', $this->externalKeyPid);
    }

    /** Build the serialized identity payload stored in Redis coordination keys. */
    public function currentIdentity(string $reason = 'unknown'): array
    {
        return [
            'process_key' => $this->currentProcessKey(),
            'instance_id' => $this->instanceId,
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'reason' => $reason,
            'claimed_at' => now()->toIso8601String(),
        ];
    }

    private function configuredInstanceId(): string
    {
        $configured = $this->config->get('lightspeed.server.instance_id');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $host = gethostname() ?: 'localhost';

        // Never `$server->master_pid`: read inside a forked worker it is not
        // stable (see bindServingPid), and identity that differs between two
        // workers of one server is a split brain, not an identity. When no
        // pid was bound, no fork happened and the current process IS the
        // serving process.
        $pid = $this->servingPid ?? getmypid();

        return "{$host}:{$pid}";
    }
}
