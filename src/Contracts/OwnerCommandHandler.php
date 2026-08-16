<?php

namespace Lightspeed\Contracts;

use Lightspeed\Owner\OwnerCommand;

interface OwnerCommandHandler
{
    /**
     * Handle one owner command or return `null` to let the next handler decide.
     */
    public function handle(OwnerCommand $command): ?array;
}
