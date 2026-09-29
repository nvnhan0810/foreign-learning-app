<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Illuminate\Support\Facades\Cache;

/**
 * Short-circuits upstreams that recently timed out, 5xx'd, or hit rate limits.
 */
final class DictionarySourceLock
{
    private const KEY_PREFIX = 'flc:dictionary_source_lock:';

    public function isLocked(string $sourceId): bool
    {
        return Cache::has($this->key($sourceId));
    }

    public function lock(string $sourceId, int $seconds, string $reason): void
    {
        if ($seconds < 1) {
            return;
        }

        Cache::put($this->key($sourceId), [
            'reason' => $reason,
            'locked_at' => now()->toIso8601String(),
        ], now()->addSeconds($seconds));
    }

    private function key(string $sourceId): string
    {
        return self::KEY_PREFIX.$sourceId;
    }
}
