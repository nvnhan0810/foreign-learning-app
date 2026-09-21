<?php

namespace Flc\Media\Infrastructure\Content;

use Flc\Media\Application\Exception\TranscriptUnavailableException;
use Flc\Media\Application\MediaContentResolver;
use Flc\Media\Domain\MediaItem;
use Flc\Media\Infrastructure\External\YouTubeTranscriptService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class DefaultMediaContentResolver implements MediaContentResolver
{
    public function __construct(
        private readonly YouTubeTranscriptService $youtubeTranscript,
    ) {}

    public function resolve(MediaItem $mediaItem): array
    {
        if ($mediaItem->transcript) {
            return [
                'content' => $mediaItem->transcript,
                'source' => self::SOURCE_TRANSCRIPT,
            ];
        }

        if ($mediaItem->type === MediaItem::TYPE_YOUTUBE && $mediaItem->sourceId) {
            $transcript = $this->youtubeTranscript->fetch(
                $mediaItem->sourceId,
                $mediaItem->language
            );

            if ($transcript) {
                return [
                    'content' => $transcript,
                    'source' => self::SOURCE_TRANSCRIPT,
                ];
            }

            Log::warning('YouTube transcript unavailable, skipping analysis', [
                'media_item_id' => $mediaItem->id,
                'video_id' => $mediaItem->sourceId,
                'language' => $mediaItem->language,
            ]);

            throw new TranscriptUnavailableException(
                $mediaItem->id,
                $mediaItem->sourceId,
            );
        }

        if ($mediaItem->notes && trim($mediaItem->notes) !== '') {
            return [
                'content' => "Title: {$mediaItem->title}\n\nNotes: {$mediaItem->notes}\n\nURL: {$mediaItem->url}",
                'source' => self::SOURCE_NOTES,
            ];
        }

        if (trim($mediaItem->title) !== '') {
            return [
                'content' => "Title: {$mediaItem->title}\n\nURL: {$mediaItem->url}",
                'source' => self::SOURCE_TITLE,
            ];
        }

        throw new RuntimeException(
            'Could not obtain content for this media item. Add notes/transcript manually or try again later.'
        );
    }
}
