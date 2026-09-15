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

        /**
         * Whether the caller is still waiting for the owner's answer.
         *
         * True for a forwarded command: the caller is polling a response key,
         * so the owner must write one. False for a sent one
         * (OwnerCommandBus::send()), where the caller returned the moment the
         * entry was written and there is nobody left to answer. Defaults to
         * true, so an entry written by a worker that predates this field is
         * still answered.
         */
        public readonly bool $expectsReply = true,
    ) {
    }
}
