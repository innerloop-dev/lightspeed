<?php

/**
 * One server, one identity, and a banner PID that is worth killing.
 *
 * Observed live before the fix: `$server->master_pid` read inside a forked
 * worker is not the serving process. The banner printed a worker's pid, so
 * `kill <banner pid>` respawned a worker instead of stopping the server, and
 * after a worker respawn the two live workers of ONE server published Redis
 * state under TWO instance ids (host:40965 and host:41653, measured). Presence
 * sweep ownership, the connection registry and owner routing all key off the
 * instance id, so a respawn quietly split the server in two.
 *
 * The rule these tests pin: process identity is captured in the process that
 * runs serve(), before any fork, and nothing downstream re-derives it from
 * what Swoole reports inside a child.
 */

use Lightspeed\Logging\OperatorLog;
use Lightspeed\Workers\WorkerContext;
use Lightspeed\Server;
use Swoole\WebSocket\Server as SwooleServer;

class IdentityRecordingSwooleServer extends SwooleServer
{
    /** @var array<string, callable> */
    public array $callbacks = [];

    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function on(string $event_name, callable $callback): bool
    {
        $this->callbacks[strtolower($event_name)] = $callback;

        return true;
    }
}

class IdentityRecordingOperatorLog extends OperatorLog
{
    /** @var list<string> */
    public array $lines = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }
}

test('two workers reporting different master pids still share one instance id', function () {
    // The measured divergence: worker 1 kept reporting the original first
    // worker's pid while a respawned worker 0 reported its own. Identity must
    // not come from either.
    config()->set('lightspeed.server.instance_id', null);

    $workerA = new WorkerContext(app('config'));
    $workerB = new WorkerContext(app('config'));

    $serverSeenByA = IdentityRecordingSwooleServer::make();
    $serverSeenByA->master_pid = 40965;

    $serverSeenByB = IdentityRecordingSwooleServer::make();
    $serverSeenByB->master_pid = 41653;

    $workerA->boot($serverSeenByA, 0);
    $workerB->boot($serverSeenByB, 1);

    expect($workerA->instanceId())->toBe($workerB->instanceId());
});

test('a configured instance id still wins over any derived one', function () {
    // The positive control for the test above: an operator who names the
    // instance is naming it for every worker, bound pid or not.
    config()->set('lightspeed.server.instance_id', 'named-by-operator');

    $context = new WorkerContext(app('config'));
    $context->bindServingPid(4242);
    $context->boot(IdentityRecordingSwooleServer::make(), 0);

    expect($context->instanceId())->toBe('named-by-operator');
});

test('the bound serving pid is the identity a worker publishes under', function () {
    // In a real worker getmypid() is the worker, not the server. The pid
    // bound before the fork is the only one that is the same in every child.
    config()->set('lightspeed.server.instance_id', null);

    $context = new WorkerContext(app('config'));
    $context->bindServingPid(4242);

    $server = IdentityRecordingSwooleServer::make();
    $server->master_pid = 999999;
    $context->boot($server, 0);

    expect($context->instanceId())->toBe((gethostname() ?: 'localhost').':4242');
});

test('shutdown clears the external-key record, so the next boot is not punished for the last life', function () {
    // Adversarial review proved this line deletable with a green suite. The record
    // exists to catch a read-before-boot ORDERING error within one worker
    // life; a worker that shut down cleanly starts a new life, and a boot()
    // there throwing over the previous life's external read would take down
    // every worker reuse after any external-key read. Deleting the
    // `externalKeyPid = null` from shutdown() fails exactly this test.
    config()->set('lightspeed.server.instance_id', null);

    $context = new WorkerContext(app('config'));
    $context->currentProcessKey();
    $context->shutdown();

    $context->boot(IdentityRecordingSwooleServer::make(), 0);

    expect($context->workerId())->toBe(0);
});

test('the banner prints the pid whose death stops the server', function () {
    // The 'start' callback runs in a forked child where master_pid lies. The
    // banner must carry the pid captured at registration time, in the process
    // that ran serve(): the same pid Swoole writes to the pid file.
    $swoole = IdentityRecordingSwooleServer::make();
    $swoole->master_pid = 888888;

    $log = new IdentityRecordingOperatorLog();

    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();

    $property = $reflection->getProperty('operatorLog');
    $property->setAccessible(true);
    $property->setValue($server, $log);

    $workerContext = $reflection->getProperty('workerContext');
    $workerContext->setAccessible(true);
    $workerContext->setValue($server, new WorkerContext(app('config')));

    driveLightspeed($server, 'registerCallbacks', [$swoole, '0.0.0.0', 8000, 8000, true]);

    ($swoole->callbacks['start'])($swoole);

    $pidLines = array_values(array_filter($log->lines, fn (string $line) => str_starts_with($line, 'PID: ')));

    expect($pidLines)->toHaveCount(1)
        ->and($pidLines[0])->toBe('PID: '.getmypid());
});
