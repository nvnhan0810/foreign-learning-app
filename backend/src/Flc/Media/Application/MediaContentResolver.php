<?php

namespace Flc\Media\Application;

use Flc\Media\Domain\MediaItem;

interface MediaContentResolver
{
    public const SOURCE_TRANSCRIPT = 'transcript';

    public const SOURCE_METADATA = 'metadata';

    public const SOURCE_NOTES = 'notes';

    public const SOURCE_TITLE = 'title_only';

    /**
     * @return array{content: string, source: string}
     */
    public function resolve(MediaItem $mediaItem): array;
}
