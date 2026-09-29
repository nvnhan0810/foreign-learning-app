<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Flc\Dictionary\Application\FreeDictionaryGateway;
use Illuminate\Support\Facades\Log;

/**
 * Tries dictionary upstreams in priority order; skips locked (dead / rate-limited) sources.
 *
 * Priority: dictionaryapi.dev → freedictionaryapi.com → api.suvankar.cc
 */
final class FailoverFreeDictionaryGateway implements FreeDictionaryGateway
{
    /**
     * @param  list<DictionaryHttpSource>  $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly DictionarySourceLock $lock,
    ) {}

    public function fetch(string $normalizedWord): ?array
    {
        foreach ($this->sources as $source) {
            $id = $source->id();

            if ($this->lock->isLocked($id)) {
                continue;
            }

            $result = $source->fetch($normalizedWord);

            if ($result->isFound()) {
                return $result->data();
            }

            if ($result->isUnavailable()) {
                $seconds = $result->isRateLimited()
                    ? $this->rateLimitLockSeconds()
                    : $this->lockSeconds();

                $this->lock->lock($id, $seconds, $result->reason() ?? 'unavailable');

                Log::warning('Dictionary upstream locked', [
                    'source' => $id,
                    'reason' => $result->reason(),
                    'lock_seconds' => $seconds,
                    'word' => $normalizedWord,
                ]);

                continue;
            }

            // Alive but word not found — try next source for coverage.
        }

        return null;
    }

    private function lockSeconds(): int
    {
        return max(60, (int) config('flc.dictionary_upstreams.lock_seconds', 900));
    }

    private function rateLimitLockSeconds(): int
    {
        return max(60, (int) config('flc.dictionary_upstreams.rate_limit_lock_seconds', 3600));
    }
}
