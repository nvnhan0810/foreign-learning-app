<?php

namespace Flc\Media\Application;

interface ContentAnalyzer
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(
        string $content,
        string $title,
        string $language,
        string $contentSource,
    ): array;
}
