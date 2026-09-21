<?php

namespace Flc\Shared\Application;

interface CommandHandler
{
    public function handle(Command $command): mixed;
}
