<?php

namespace Flc\Shared\Application;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
