<?php

namespace App\Jobs;

use Flc\Media\Application\Command\ProcessMediaContent;
use Flc\Shared\Application\CommandBus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMediaContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public int $mediaItemId) {}

    public function handle(CommandBus $commands): void
    {
        $commands->dispatch(new ProcessMediaContent($this->mediaItemId));
    }
}
