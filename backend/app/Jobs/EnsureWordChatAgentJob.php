<?php

namespace App\Jobs;

use Flc\WordChat\Application\Command\CreateWordChatAgent;
use Flc\Shared\Application\CommandBus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnsureWordChatAgentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $userId) {}

    public function handle(CommandBus $commands): void
    {
        $commands->dispatch(new CreateWordChatAgent($this->userId));
    }
}
