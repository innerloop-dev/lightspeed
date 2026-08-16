<?php

namespace Lightspeed\ClientEvents;

/**
 * Normalized result contract for package-managed client-event handlers.
 *
 * Handlers can:
 *
 * - return `null` to decline the event and let the package fall back to normal
 *   channel fan-out
 * - return `handled()` to consume the event without sending a response
 * - return `error()` to push a pusher-style error frame
 * - return `response()` to send a Lightspeed request/response payload
 */
class ClientEventResult
{
    private function __construct(
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $requestId = null,
        public readonly ?array $response = null,
        public readonly int $status = 200,
    ) {
    }

    public static function handled(): self
    {
        return new self();
    }

    /** Build a package error response that should be pushed back to the caller. */
    public static function error(string $code, string $message): self
    {
        return new self(
            errorCode: $code,
            errorMessage: $message,
        );
    }

    /** Build a Lightspeed request/response payload for the originating socket. */
    public static function response(string $requestId, array $response, int $status = 200): self
    {
        return new self(
            requestId: $requestId,
            response: $response,
            status: $status,
        );
    }
}
