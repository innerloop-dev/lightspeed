<?php

namespace Lightspeed\Contracts;

use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;

interface ClientEventHandler
{
    /**
     * Handle one client event or return `null` to let the next handler decide.
     */
    public function handle(ClientEvent $event): ?ClientEventResult;
}
