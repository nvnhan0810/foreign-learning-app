<?php

namespace Flc\Media\Infrastructure\Persistence;

use App\Models\MediaItem as EloquentMediaItem;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Media\Domain\MediaItem;

final class EloquentMediaItemRepository implements MediaItemRepository
{
    public function find(int $id): ?MediaItem
    {
        $model = EloquentMediaItem::query()->find($id);

        return $model ? self::toDomain($model) : null;
    }

    public function markProcessing(int $id): void
    {
        EloquentMediaItem::query()->whereKey($id)->update([
            'analysis_status' => MediaItem::ANALYSIS_PROCESSING,
            'analysis_error' => null,
        ]);
    }

    public function markReady(int $id, array $fields): void
    {
        $model = EloquentMediaItem::query()->findOrFail($id);

        $update = [
            'analysis_status' => MediaItem::ANALYSIS_READY,
            'analyzed_at' => now(),
            'analysis_error' => null,
        ];

        if (array_key_exists('transcript', $fields)) {
            $update['transcript'] = $fields['transcript'];
        }

        if (array_key_exists('transcript_segments', $fields)) {
            $update['transcript_segments'] = $fields['transcript_segments'];
        }

        if (array_key_exists('analysis_payload', $fields)) {
            $update['analysis_payload'] = $fields['analysis_payload'];
        }

        if (array_key_exists('difficulty', $fields)) {
            $update['difficulty'] = $fields['difficulty'];
        }

        $model->update($update);
    }

    public function markFailed(int $id, string $error): void
    {
        EloquentMediaItem::query()->whereKey($id)->update([
            'analysis_status' => MediaItem::ANALYSIS_FAILED,
            'analysis_error' => $error,
        ]);
    }

    public function appendTranscriptUnavailableNote(int $id): void
    {
        $model = EloquentMediaItem::query()->findOrFail($id);

        $note = 'Không lấy được phụ đề/transcript cho video YouTube. Bật caption trên YouTube hoặc dán transcript thủ công, rồi phân tích lại.';
        $existing = trim($model->notes ?? '');

        if (str_contains($existing, 'Không lấy được phụ đề/transcript')) {
            return;
        }

        $model->update([
            'notes' => $existing === '' ? $note : $existing."\n\n".$note,
        ]);
    }

    public function markQuestionBankGenerating(int $id): void
    {
        EloquentMediaItem::query()->whereKey($id)->update([
            'question_bank_status' => EloquentMediaItem::QUESTION_BANK_GENERATING,
            'question_bank_count' => 0,
        ]);
    }

    public function markQuestionBankReady(int $id, int $count): void
    {
        EloquentMediaItem::query()->whereKey($id)->update([
            'question_bank_status' => EloquentMediaItem::QUESTION_BANK_READY,
            'question_bank_count' => $count,
        ]);
    }

    public function markQuestionBankFailed(int $id): void
    {
        EloquentMediaItem::query()->whereKey($id)->update([
            'question_bank_status' => EloquentMediaItem::QUESTION_BANK_FAILED,
            'question_bank_count' => 0,
        ]);
    }

    public static function toDomain(EloquentMediaItem $model): MediaItem
    {
        return new MediaItem(
            id: $model->id,
            userId: $model->user_id,
            title: $model->title,
            url: $model->url,
            sourceId: $model->source_id,
            type: $model->type,
            language: $model->language ?? 'en',
            notes: $model->notes,
            transcript: $model->transcript,
            transcriptSegments: self::normalizeTranscriptSegments($model->transcript_segments),
            difficulty: $model->difficulty,
            analysisStatus: $model->analysis_status ?? MediaItem::ANALYSIS_PENDING,
            analysisPayload: is_array($model->analysis_payload) ? $model->analysis_payload : null,
            questionBankStatus: $model->question_bank_status,
            questionBankCount: (int) $model->question_bank_count,
        );
    }

    /**
     * @return list<array{start: float, end: float, text: string}>|null
     */
    private static function normalizeTranscriptSegments(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $segments = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = isset($item['text']) ? trim((string) $item['text']) : '';
            if ($text === '' || ! isset($item['start'], $item['end'])) {
                continue;
            }

            $segments[] = [
                'start' => (float) $item['start'],
                'end' => (float) $item['end'],
                'text' => $text,
            ];
        }

        return $segments === [] ? null : $segments;
    }
}
