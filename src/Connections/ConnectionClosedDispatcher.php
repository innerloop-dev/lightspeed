<?php

namespace Lightspeed\Connections;

use Illuminate\Container\Container as BaseContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Application;
use Laravel\Octane\CurrentApplication;
use Lightspeed\Contracts\ConnectionClosedHandler;
use Lightspeed\Http\OctaneWorker;
use Lightspeed\Logging\RuntimeLogger;

/**
 * Resolves and runs the configured connection-closed handlers, in order.
 *
 * Two things make this different from ClientEvents\ClientEventDispatcher, and
 * both come from where it is called rather than from taste:
 *
 * IT NEVER THROWS. Its callers are a Swoole `close` callback and a Swoole timer
 * tick, both of which run directly on the event loop with `enable_coroutine`
 * off, where an escaping exception ends the worker serving every other
 * connection. A client event can be refused on the wire; a connection that has
 * already gone cannot be told anything. So a handler that throws, a handler
 * class that cannot be resolved and a handler that does not implement the
 * contract are all reported through Logging\RuntimeLogger and stepped over.
 *
 * IT RUNS IN THE OCTANE SANDBOX, through the same Http\OctaneWorker::runTask()
 * a client event goes through, and for two reasons.
 * Handlers resolved out of whatever `Container::getInstance()`
 * happened to hold ran either against the BASE application, where a scoped
 * binding a handler set is inherited by every request the worker serves
 * afterwards, or, when a close ran synchronously
 * inside another request (Channels\ChannelManager::closeDroppedFds calls
 * `$server->close()`, and Swoole runs the close callback on that stack), against
 * a stranger's request sandbox that was flushed moments later. One path, no
 * exceptions: when there is no booted application worker to run in, which is the
 * state a timer tick can genuinely find, the event is logged and dropped rather
 * than run unsandboxed. The sandbox is a shallow clone, so a handler can still
 * reach a service the base application shares with it; undoing that is the task
 * boundary's job and lives in Http\OctaneWorker::runTask(), which every handler
 * this package runs passes through. What is this class's alone is the container
 * restore below, because only this dispatcher can be interrupting a request.
 *
 * EVERY HANDLER RUNS, on every event that gets this far. There is no result to
 * return, so there is nothing for one handler to decide on another's behalf, and
 * one that fails does not cost the rest their turn. What does NOT reach here is
 * a connection that never passed channel authorization (see Protocol\Teardown)
 * and a connection whose worker was killed (see ConnectionClosed).
 *
 * hasHandlers() is the whole cost for an application that registers nothing:
 * one config lookup, and no event is built.
 */
class ConnectionClosedDispatcher
{
    public function __construct(
        private readonly OctaneWorker $httpWorker,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * Is there anything to notify?
     *
     * Deliberately the cheapest question this class can answer, because the
     * close path asks it on every connection and the answer for most
     * applications is no.
     */
    public function hasHandlers(): bool
    {
        return $this->configuredClasses() !== [];
    }

    /**
     * @return bool whether there was an application worker to run the handlers
     *              in. False is "nobody was told, and nobody could have been",
     *              which is the one outcome a caller holding a backlog has to
     *              be able to tell apart from a delivered event. A handler that
     *              threw is still a delivered event: it ran.
     */
    public function dispatch(ConnectionClosed $event): bool
    {
        if (!$this->httpWorker->isBooted()) {
            // The honest answer, and the only safe one. A presence sweep is a
            // timer that starts with the worker, so it can tick before the
            // application has booted and after it has been terminated;
            // resolving application classes then would mean running them
            // outside the sandbox, which is the thing this class exists to
            // stop.
            $this->report('connection-closed-worker-unavailable', '', 'no booted application worker', $event);

            return false;
        }

        // WHOSE CONTAINER WE INTERRUPTED. Octane's handleTask() restores the
        // BASE application when it is done, which is right for a task dispatched
        // from the event loop and wrong for this one alone: a close can run
        // synchronously inside another request's stack, and returning that
        // request to the base application mid-flight would be the same
        // cross-request bleed the sandbox is here to prevent.
        $interrupted = BaseContainer::getInstance();

        try {
            $this->httpWorker->runTask(function () use ($event): void {
                foreach ($this->handlers() as $handler) {
                    try {
                        $handler->connectionClosed($event);
                    } catch (\Throwable $e) {
                        $this->report('connection-closed-handler-failed', $handler::class, $e->getMessage(), $event);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Nothing inside the task throws, so reaching here means the task
            // machinery itself did.
            $this->report('connection-closed-dispatch-failed', '', $e->getMessage(), $event);
        } finally {
            $this->restoreContainer($interrupted);
        }

        return true;
    }

    /**
     * The handlers to run, resolved from the sandbox the task is running in.
     *
     * Resolved per event rather than cached, and the close is the reason that
     * is affordable: it happens once in a connection's life, against a list most
     * applications leave empty. A cache would have to be keyed on both the
     * container (a fresh sandbox per event, by design) and the configured list,
     * which is more state to keep true than the resolution it saves.
     *
     * NOTHING HERE THROWS. A class that cannot be resolved, or one that does
     * not implement the contract, is reported and stepped over, because the
     * callers are Swoole callbacks and the alternative to skipping one handler
     * is losing the worker.
     *
     * @return list<ConnectionClosedHandler>
     */
    private function handlers(): array
    {
        $container = $this->currentContainer();
        $resolved = [];

        foreach ($this->configuredClasses() as $handlerClass) {
            if (!is_string($handlerClass) || $handlerClass === '') {
                $this->report('connection-closed-handler-invalid', '', 'not a class name');

                continue;
            }

            try {
                $handler = $container->make($handlerClass);
            } catch (\Throwable $e) {
                $this->report('connection-closed-handler-invalid', $handlerClass, $e->getMessage());

                continue;
            }

            if (!$handler instanceof ConnectionClosedHandler) {
                $this->report('connection-closed-handler-invalid', $handlerClass, 'does not implement '.ConnectionClosedHandler::class);

                continue;
            }

            $resolved[] = $handler;
        }

        return $resolved;
    }

    /**
     * The configured handler class names, read from the application running NOW.
     *
     * Through `config()` rather than through a repository held since
     * construction, exactly as Presence\PresenceSweeper, Boot\BootValidation and
     * the doctor read theirs. This object is built in the process that runs
     * `lightspeed:serve`, BEFORE Swoole forks and before any worker bootstraps
     * an application, so a captured repository is a snapshot of the console
     * process: a provider that registers a handler in the worker would never be
     * seen, and a list edited before the fork would go on running forever.
     * Neither failure says anything.
     *
     * Guarded, because `config()` reaches through the container and this runs
     * inside Swoole callbacks: an application that cannot answer is nobody
     * notified, never an exception on the event loop.
     *
     * @return array<array-key, mixed>
     */
    private function configuredClasses(): array
    {
        try {
            return (array) config('lightspeed.connection_closed_handlers', []);
        } catch (\Throwable $e) {
            $this->report('connection-closed-handler-invalid', '', $e->getMessage());

            return [];
        }
    }

    /**
     * The container to resolve handlers out of, which is the task's sandbox.
     *
     * runTask() has already made that sandbox the current application, so this
     * is a read of where Octane put us rather than a choice being made here.
     */
    private function currentContainer(): Container
    {
        return BaseContainer::getInstance();
    }

    /**
     * Put back the container that was current before the task ran.
     *
     * Unconditionally, because the case worth spending a branch on is the one
     * where nothing changed, and there is none: Octane's handleTask() ends by
     * making the BASE application current, so after a dispatch that started
     * inside a request this is always a different container from the one that
     * came in, and after one that started on the event loop it is the same
     * container being made current again, which is what Octane itself does.
     *
     * Through CurrentApplication for an application, because the facades point
     * at it too and a container swapped underneath a facade root is half a
     * restore. Guarded like everything else here: this is a `finally` on a
     * Swoole callback's stack.
     */
    private function restoreContainer(Container $interrupted): void
    {
        try {
            if ($interrupted instanceof Application) {
                CurrentApplication::set($interrupted);

                return;
            }

            // KNOWINGLY HALF A RESTORE, and the boundary is what makes it safe.
            // This branch is only reachable when the container that was current
            // is not an Illuminate application, which in a booted worker it
            // never is: every path into a close runs under the application the
            // worker booted or under an Octane clone of it. A bare container is
            // therefore a thing only a test puts there, and the facades were
            // never pointing at it, so there is no facade root to put back. The
            // asymmetry with the branch above is the point, not an omission.
            BaseContainer::setInstance($interrupted);
        } catch (\Throwable) {
            // The alternative to leaving the container as Octane left it is
            // throwing out of a close callback, which is worse.
        }
    }

    /**
     * Say what went wrong, without becoming the next thing that goes wrong.
     *
     * The log stack is the one dependency this class has left at the point it
     * is reporting a failure, so it is guarded too: a logger that throws here
     * would turn a handler's bug into the fatal this whole class exists to
     * avoid.
     */
    private function report(string $reason, string $handlerClass, string $message, ?ConnectionClosed $event = null): void
    {
        try {
            $this->runtimeLogger->logWebsocket('error', [
                'reason' => $reason,
                'handler' => $handlerClass,
                'socket' => $event?->socketId,
                'close_reason' => $event?->reason,
                'message' => $message,
            ]);
        } catch (\Throwable) {
            // Nothing left to report through, and the connection this was
            // about has already gone.
        }
    }
}
