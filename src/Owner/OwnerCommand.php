<?php

namespace Lightspeed\Owner;

/**
 * Immutable owner-command payload delivered between Lightspeed worker processes.
 */
class OwnerCommand
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $resourceId,
        public readonly string $command,
        public readonly array $payload,
        public readonly ?string $originProcessKey = null,
    ) {
    }
}
