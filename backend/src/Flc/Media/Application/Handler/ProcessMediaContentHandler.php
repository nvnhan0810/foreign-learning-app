<?php

namespace Flc\Media\Application\Handler;

use Flc\Media\Application\Command\ProcessMediaContent;
use Flc\Media\Application\ContentAnalyzer;
use Flc\Media\Application\Exception\TranscriptUnavailableException;
use Flc\Media\Application\ListeningAssessmentGenerator;
use Flc\Media\Application\MediaContentResolver;
use Flc\Media\Application\MediaKeyVocabularyImporter;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Media\Domain\MediaItem;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Application\Logger;
use Throwable;

final class ProcessMediaContentHandler implements CommandHandler
{
    public function __construct(
        private readonly MediaItemRepository $mediaItems,
        private readonly MediaContentResolver $contentResolver,
        private readonly ContentAnalyzer $contentAnalyzer,
        private readonly MediaKeyVocabularyImporter $vocabularyImporter,
        private readonly ListeningAssessmentGenerator $assessmentGenerator,
        private readonly Logger $logger,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ProcessMediaContent);

        $mediaItem = $this->mediaItems->find($command->mediaItemId);

        if ($mediaItem === null) {
            return null;
        }

        $this->mediaItems->markProcessing($mediaItem->id);

        try {
            $resolved = $this->contentResolver->resolve($mediaItem);
            $content = $resolved['content'];
            $contentSource = $resolved['source'];

            $analysis = $this->contentAnalyzer->analyze(
                $content,
                $mediaItem->title,
                $mediaItem->language,
                $contentSource,
            );

            $analysis['source_content'] = $content;
            $analysis['content_source'] = $contentSource;

            $analysis['vocabulary_import'] = $mediaItem->userId !== null
                ? $this->vocabularyImporter->importFromAnalysis($mediaItem->userId, $analysis)
                : ['imported' => 0, 'skipped' => 0, 'words' => []];

            $this->mediaItems->markReady($mediaItem->id, [
                'transcript' => $contentSource === MediaContentResolver::SOURCE_TRANSCRIPT
                    ? $content
                    : $mediaItem->transcript,
                'analysis_payload' => $analysis,
                'difficulty' => MediaItem::normalizeDifficulty($analysis['difficulty'] ?? null),
            ]);

            $this->assessmentGenerator->generateQuestionBank($mediaItem->id);

            return null;
        } catch (TranscriptUnavailableException $e) {
            $this->mediaItems->appendTranscriptUnavailableNote($mediaItem->id);
            $this->mediaItems->markFailed($mediaItem->id, $e->getMessage());

            return null;
        } catch (Throwable $e) {
            $this->logger->error('ProcessMediaContent failed', [
                'media_item_id' => $mediaItem->id,
                'error' => $e->getMessage(),
            ]);

            $this->mediaItems->markFailed($mediaItem->id, $e->getMessage());

            throw $e;
        }
    }
}
