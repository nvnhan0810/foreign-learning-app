<?php

namespace Flc\Listening\Application\Query;

use Flc\Shared\Application\Query;

final class GetListeningSessionOptions implements Query
{
    public function __construct(public readonly int $mediaItemId) {}
}
