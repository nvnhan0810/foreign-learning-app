<?php

namespace Flc\Shared\Application;

use InvalidArgumentException;

final class CommandBus
{
    /** @param array<class-string<Command>, class-string<CommandHandler>|CommandHandler> $map */
    public function __construct(
        private array $map,
        private readonly \Illuminate\Contracts\Container\Container $container,
    ) {}

    public function dispatch(Command $command): mixed
    {
        $handler = $this->resolve($command::class, $this->map);

        return $handler->handle($command);
    }

    /**
     * @param  array<class-string, class-string|object>  $map
     */
    private function resolve(string $messageClass, array $map): CommandHandler
    {
        if (! isset($map[$messageClass])) {
            throw new InvalidArgumentException('No command handler for '.$messageClass);
        }

        $handler = $map[$messageClass];

        if (is_string($handler)) {
            $handler = $this->container->make($handler);
        }

        if (! $handler instanceof CommandHandler) {
            throw new InvalidArgumentException('Invalid command handler for '.$messageClass);
        }

        return $handler;
    }
}
