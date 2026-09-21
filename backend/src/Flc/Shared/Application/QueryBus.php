<?php

namespace Flc\Shared\Application;

use InvalidArgumentException;

final class QueryBus
{
    /** @param array<class-string<Query>, class-string<QueryHandler>|QueryHandler> $map */
    public function __construct(
        private array $map,
        private readonly \Illuminate\Contracts\Container\Container $container,
    ) {}

    public function ask(Query $query): mixed
    {
        $handler = $this->resolve($query::class, $this->map);

        return $handler->handle($query);
    }

    /**
     * @param  array<class-string, class-string|object>  $map
     */
    private function resolve(string $messageClass, array $map): QueryHandler
    {
        if (! isset($map[$messageClass])) {
            throw new InvalidArgumentException('No query handler for '.$messageClass);
        }

        $handler = $map[$messageClass];

        if (is_string($handler)) {
            $handler = $this->container->make($handler);
        }

        if (! $handler instanceof QueryHandler) {
            throw new InvalidArgumentException('Invalid query handler for '.$messageClass);
        }

        return $handler;
    }
}
