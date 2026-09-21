<?php

namespace Flc\WordChat\Application\Handler;

use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\WordChat\Application\Command\SendWordChatMessage;
use Flc\WordChat\Application\CursorWordChatGateway;
use Flc\WordChat\Application\WordChatAgentRepository;
use Flc\WordChat\Application\WordChatMessageRepository;
use Flc\WordChat\Application\WordChatPromptBuilder;
use Flc\WordChat\Application\WordChatRunRepository;
use Flc\WordChat\Domain\WordChatMessage;
use Flc\WordChat\Domain\WordChatRun;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class SendWordChatMessageHandler implements CommandHandler
{
    public function __construct(
        private readonly CursorWordChatGateway $cursor,
        private readonly WordChatAgentRepository $agents,
        private readonly WordChatMessageRepository $messages,
        private readonly WordChatRunRepository $runs,
        private readonly WordChatPromptBuilder $prompts,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SendWordChatMessage);

        if (! $this->cursor->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'Word chat is not configured.');
        }

        $text = trim($command->text);
        if ($text === '') {
            throw new \InvalidArgumentException('Message text is required.');
        }

        $maxLength = (int) config('word_chat.max_message_length', 4000);
        if (strlen($text) > $maxLength) {
            throw new \InvalidArgumentException('Message is too long.');
        }

        $agent = $this->agents->findForUser($command->userId);
        if ($agent === null) {
            throw new ServiceUnavailableHttpException(null, 'Word chat is still preparing. Please wait a moment.');
        }

        $lookup = $this->prompts->resolveDictionaryForMessage($text);
        $prompt = $this->prompts->buildFollowUpPrompt($text);
        $cursorRun = $this->cursor->followUp($agent['cursor_agent_id'], $prompt);

        if ($cursorRun === null) {
            throw new ServiceUnavailableHttpException(null, 'Word chat is temporarily unavailable. Please try again.');
        }

        $userMessage = $this->messages->save(new WordChatMessage(
            id: null,
            userId: $command->userId,
            role: 'user',
            content: $text,
            cursorRunId: $cursorRun['runId'],
            metadata: $lookup !== null ? ['lookup' => $lookup] : null,
        ));

        $run = $this->runs->save(new WordChatRun(
            id: null,
            userId: $command->userId,
            wordChatAgentId: $agent['id'],
            cursorAgentId: $cursorRun['agentId'],
            cursorRunId: $cursorRun['runId'],
            userMessageId: (int) $userMessage->id,
            assistantMessageId: null,
            status: 'streaming',
        ));

        $this->agents->markLastRun($command->userId);

        $response = [
            'message_id' => $userMessage->id,
            'run_id' => $cursorRun['runId'],
            'stream_url' => '/api/word-chat/stream/'.$cursorRun['runId'],
            'word_chat_run_id' => $run->id,
        ];

        if ($lookup !== null) {
            $response['lookup'] = $lookup;
        }

        return $response;
    }
}
