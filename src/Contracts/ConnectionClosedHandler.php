<?php

namespace Lightspeed\Contracts;

use Lightspeed\Connections\ConnectionClosed;

interface ConnectionClosedHandler
{
    /**
     * React to one connection leaving.
     *
     * A notification, not a pipeline: there is nothing to return and nothing to
     * decline, so no handler answers on another's behalf and one that throws
     * does not cost the rest their turn. That is not "every close": a killed
     * worker runs no teardown at all, a connection that held no channel and no
     * grant never reaches application code, and a handler that cannot be
     * resolved or does not implement this interface is logged and stepped over.
     * See Connections\ConnectionClosed for what the event carries and for the
     * bound on when it arrives at all.
     *
     * RUNS ON THE WORKER'S EVENT LOOP, inside an Octane sandbox but not inside a
     * queue: whatever this method spends is time the worker is not serving its
     * other connections. Keep it fast and dispatch real work.
     */
    public function connectionClosed(ConnectionClosed $event): void;
}
