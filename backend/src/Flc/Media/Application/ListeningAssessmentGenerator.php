<?php

namespace Flc\Media\Application;

interface ListeningAssessmentGenerator
{
    public function generateQuestionBank(int $mediaItemId): void;
}
