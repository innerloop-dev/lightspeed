<?php

namespace Lightspeed\Workers;

/**
 * Whether this worker finished starting, and what stopped it if it did not.
 *
 * ONE FACT, HELD IN ONE PLACE, BECAUSE THREE SURFACES HAVE TO AGREE ON IT.
 * `/healthz` is what a load balancer reads, the websocket `open` callback is
 * what a client meets, and the operator log is what a human reads. Before this
 * existed those three answered separately and one of them was wrong: the health
 * endpoint returned 200 unconditionally, so a worker whose relay never attached
 * was drained into by the load balancer while every broadcast it accepted went
 * nowhere.
 *
 * The default is NOT READY, and that direction is the whole design. A flag that
 * starts true and is cleared on failure reports health for the entire window
 * between the listener binding and the worker finishing its start-up, which is
 * exactly the window a rolling deploy drives traffic through. Readiness is
 * something a worker earns by reaching the end of its start-up, not something
 * it holds until proven otherwise.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: restart the worker. Swoole would respawn
 * a worker that exited, and the causes of a failed start (Redis unreachable,
 * most of them) are exactly the causes that would still be true on the next
 * attempt, so exiting turns one broken node into a crash loop. A worker that
 * stays up, says why, and reports itself unfit is inspectable and lets a load
 * balancer drain it. That is a choice about failure MODE and it is worth
 * re-deciding if the health signal is ever not wired to something that acts on
 * it.
 *
 * WHAT THAT CHOICE COSTS, STATED HONESTLY, BECAUSE IT IS EASY TO OVERSELL.
 * Server::bringWorkerUp() attaches the relay near the START of the sequence and
 * boots the host application near the END, so the failure this class was
 * written for -- Redis unreachable at worker start -- happens BEFORE
 * Http\OctaneWorker::boot() has run. On that worker there is no application at
 * all: Http\RequestRouter answers every application request 503 ("Lightspeed
 * HTTP worker is not ready"), and Server::handleOpen refuses every websocket
 * upgrade, the diagnostic protocol included. So a drained worker is not a
 * worker that "keeps serving the application"; it is a worker that has stopped
 * serving everything except /healthz and stdout.
 *
 * AND IT IS TERMINAL. Nothing calls markReady() but the end of a successful
 * workerStart, and Swoole fires workerStart once per process. There is no timer
 * that retries the attach and no path back to ready. When Redis returns, this
 * worker does not. Recovery is a restart, by a human or by an orchestrator, and
 * docs/PRODUCTION.md has to say so rather than telling operators to drain
 * instead of restarting.
 *
 * Owns: the readiness flag and the failure summary.
 * Deliberately does not own: what a caller does about either. Http\RequestRouter
 * decides the status code, Server decides what happens to a websocket upgrade,
 * and Logging\OperatorLog decides what an operator is told.
 */
class WorkerHealth
{
    private bool $ready = false;

    /**
     * A one-line summary of what stopped the worker starting, or null.
     *
     * The exception's message and class, not its trace: this is written into an
     * HTTP body that a health checker logs, and a stack trace in a `/healthz`
     * response is a detail nobody reads and a shape somebody could parse.
     */
    private ?string $failure = null;

    /** The worker reached the end of its start-up. */
    public function markReady(): void
    {
        $this->ready = true;
        $this->failure = null;
    }

    /** The worker's start-up did not finish, and this is why. */
    public function markFailed(\Throwable $e): void
    {
        $this->ready = false;
        $this->failure = $e::class.': '.$e->getMessage();
    }

    /**
     * Back to the starting state.
     *
     * Called at worker stop, so a worker on its way down stops claiming to be a
     * load balancer target before it stops answering rather than after.
     */
    public function reset(): void
    {
        $this->ready = false;
        $this->failure = null;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /** Why this worker is not ready, when there is a reason more specific than "not yet". */
    public function failure(): ?string
    {
        return $this->failure;
    }
}
