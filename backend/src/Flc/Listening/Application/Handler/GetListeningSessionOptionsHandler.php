<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Query\GetListeningSessionOptions;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAssessment;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Shared\Application\Config;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class GetListeningSessionOptionsHandler implements QueryHandler
{
    public function __construct(
        private readonly MediaItemRepository $mediaItems,
        private readonly ListeningAssessmentRepository $assessments,
        private readonly Config $config,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetListeningSessionOptions);

        $mediaItem = $this->mediaItems->find($query->mediaItemId);

        if ($mediaItem === null) {
            return [];
        }

        $bankCount = $this->assessments->questionBankCount($query->mediaItemId);
        $bankReady = $mediaItem->isQuestionBankReady();

        $options = [];

        foreach ([ListeningAssessment::TYPE_QUIZ, ListeningAssessment::TYPE_TEST, ListeningAssessment::TYPE_EXAM] as $type) {
            $config = $this->config->get("listening.assessments.{$type}");

            if (! is_array($config)) {
                continue;
            }

            $sessionSize = (int) $config['question_count'];

            $options[] = [
                'type' => $type,
                'title' => "{$mediaItem->title} — {$config['title_suffix']}",
                'question_count' => $sessionSize,
                'time_limit_minutes' => $config['time_limit_minutes'],
                'available' => $bankReady && $bankCount >= $sessionSize,
                'bank_count' => $bankCount,
                'bank_status' => $mediaItem->questionBankStatus,
            ];
        }

        return $options;
    }
}
