<?php

namespace Flc\Media\Application\Repository;

use Flc\Media\Domain\MediaItem;

interface MediaItemRepository
{
    public function find(int $id): ?MediaItem;

    public function markProcessing(int $id): void;

    /**
     * @param  array{transcript?: ?string, analysis_payload?: array<string, mixed>, difficulty?: string}  $fields
     */
    public function markReady(int $id, array $fields): void;

    public function markFailed(int $id, string $error): void;

    public function appendTranscriptUnavailableNote(int $id): void;

    public function markQuestionBankGenerating(int $id): void;

    public function markQuestionBankReady(int $id, int $count): void;

    public function markQuestionBankFailed(int $id): void;
}
