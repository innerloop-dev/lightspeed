<?php

namespace Lightspeed\Owner;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Container\Container as BaseContainer;
use Lightspeed\Contracts\OwnerCommandHandler;

/**
 * Resolves and runs configured owner-command handlers in order.
 *
 * Owner commands execute inside worker tasks, so this dispatcher keeps handler
 * resolution container-aware and resets its cache when the active container
 * changes. That lets the package stay generic while the app supplies policy.
 */
class OwnerCommandDispatcher
{
    private ?array $handlers = null;

    private ?int $handlerContainerId = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private Container $container,
    ) {
    }

    public function useContainer(Container $container): void
    {
        $this->container = $container;
        $this->handlers = null;
        $this->handlerContainerId = null;
    }

    public function dispatch(OwnerCommand $command): ?array
    {
        foreach ($this->handlers() as $handler) {
            try {
                $result = $handler->handle($command);
            } catch (\Throwable $e) {
                $handlerClass = $handler::class;
                throw new \RuntimeException(
                    "Lightspeed owner command handler [{$handlerClass}] failed: {$e->getMessage()}",
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
     * Resolve handlers once per active container instance.
     */
    private function handlers(): array
    {
        $container = $this->currentContainer();
        $containerId = spl_object_id($container);

        if ($this->handlers !== null && $this->handlerContainerId === $containerId) {
            return $this->handlers;
        }

        $resolved = [];
        $handlerClasses = $this->config->get('lightspeed.owner_command_handlers', []);

        foreach ($handlerClasses as $handlerClass) {
            $handler = $container->make($handlerClass);

            if (!$handler instanceof OwnerCommandHandler) {
                throw new \RuntimeException("Lightspeed owner command handler [{$handlerClass}] is invalid.");
            }

            $resolved[] = $handler;
        }

        $this->handlerContainerId = $containerId;

        return $this->handlers = $resolved;
    }

    /**
     * Prefer Laravel's current global container when it looks booted.
     */
    private function currentContainer(): Container
    {
        $current = BaseContainer::getInstance();

        if ($current instanceof Container && $current->bound('config')) {
            return $current;
        }

        return $this->container;
    }
}
