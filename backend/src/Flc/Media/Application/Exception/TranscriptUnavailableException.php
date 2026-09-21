<?php

namespace Flc\Media\Application\Exception;

use RuntimeException;

class TranscriptUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $mediaItemId,
        ?string $videoId = null,
    ) {
        $suffix = $videoId ? " (video: {$videoId})" : '';

        parent::__construct(
            "Không lấy được phụ đề/transcript cho video YouTube{$suffix}. Bật caption trên YouTube hoặc dán transcript thủ công, rồi phân tích lại."
        );
    }
}
