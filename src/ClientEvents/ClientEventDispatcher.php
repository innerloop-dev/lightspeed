<?php

namespace Lightspeed\ClientEvents;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Lightspeed\Contracts\ClientEventHandler;

/**
 * Resolves and runs configured package client-event handlers in order.
 *
 * The dispatcher is intentionally small: handler order comes from config,
 * container resolution stays lazy, and the first non-null result wins. That
 * keeps package transport logic separate from any application-specific policy.
 */
class ClientEventDispatcher
{
    private ?array $handlers = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
    ) {
    }

    public function dispatch(ClientEvent $event): ?ClientEventResult
    {
        foreach ($this->handlers() as $handler) {
            try {
                $result = $handler->handle($event);
            } catch (\Throwable $e) {
                $handlerClass = $handler::class;
                throw new \RuntimeException(
                    "Lightspeed handler [{$handlerClass}] failed: {$e->getMessage()}",
                    previous: $e,
                );
            }

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Resolve handlers once per dispatcher instance from the configured class list.
     */
    private function handlers(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }

        $resolved = [];

        try {
            $handlerClasses = $this->config->get('lightspeed.client_event_handlers', []);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Lightspeed client event handler config lookup failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        foreach ($handlerClasses as $handlerClass) {
            try {
                $handler = $this->container->make($handlerClass);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Lightspeed handler resolution failed for [{$handlerClass}]: {$e->getMessage()}",
                    previous: $e,
                );
            }

            if (!$handler instanceof ClientEventHandler) {
                throw new \RuntimeException("Lightspeed client event handler [{$handlerClass}] is invalid.");
            }

            $resolved[] = $handler;
        }

        return $this->handlers = $resolved;
    }
}
