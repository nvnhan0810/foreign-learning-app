<?php

namespace Flc\WordChat\Application\Handler;

use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\WordChat\Application\Query\ListWordChatMessages;
use Flc\WordChat\Application\WordChatMessageRepository;
use Flc\WordChat\Domain\WordChatMessage;

final class ListWordChatMessagesHandler implements QueryHandler
{
    public function __construct(
        private readonly WordChatMessageRepository $messages,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof ListWordChatMessages);

        $limit = $query->limit > 0
            ? min($query->limit, (int) config('word_chat.history_page_size', 50))
            : (int) config('word_chat.history_page_size', 50);

        $items = $this->messages->listForUser($query->userId, $query->beforeId, $limit);

        return array_map(
            fn (WordChatMessage $message) => $message->toApiArray(),
            $items,
        );
    }
}
