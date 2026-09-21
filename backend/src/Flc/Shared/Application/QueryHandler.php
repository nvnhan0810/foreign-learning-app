<?php

namespace Flc\Shared\Application;

interface QueryHandler
{
    public function handle(Query $query): mixed;
}
