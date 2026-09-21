<?php

namespace Flc\Dictionary\Application\Handler;

use Flc\Dictionary\Application\FreeDictionaryGateway;
use Flc\Dictionary\Application\LookupLemmaGenerator;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\Query\ResolveLookupWord;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Application\SpellSuggestionGateway;
use Flc\Shared\Application\Config;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryBus;
use Flc\Shared\Application\QueryHandler;
use Flc\Shared\Support\Text;

final class ResolveLookupWordHandler implements QueryHandler
{
    public function __construct(
        private readonly DictionaryEntryRepository $entries,
        private readonly FreeDictionaryGateway $gateway,
        private readonly LookupLemmaGenerator $lemmas,
        private readonly SpellSuggestionGateway $spellSuggestions,
        private readonly QueryBus $queries,
        private readonly Config $config,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof ResolveLookupWord);

        $selected = $this->normalizeSelected($query->word);
        if ($selected === '') {
            return null;
        }

        $resolved = $this->resolveTargetWord($selected);

        if ($resolved === null) {
            return null;
        }

        ['word' => $resolved, 'method' => $method] = $resolved;

        /** @var array<string, mixed>|null $dictionary */
        $dictionary = $this->queries->ask(new LookupWord($resolved));

        if ($dictionary === null && $resolved !== $selected) {
            $dictionary = $this->queries->ask(new LookupWord($selected));
            if ($dictionary !== null) {
                $resolved = $selected;
                $method = 'exact';
            }
        }

        if ($dictionary === null) {
            return null;
        }

        return [
            'selected' => $selected,
            'resolved' => (string) ($dictionary['word'] ?? $resolved),
            'method' => $method,
            'dictionary' => $dictionary,
        ];
    }

    /**
     * @return array{word: string, method: string}|null
     */
    private function resolveTargetWord(string $selected): ?array
    {
        if ($this->wordExists($selected)) {
            $lemmaUpgrade = $this->findLemmaUpgrade($selected);

            if ($lemmaUpgrade !== null) {
                return ['word' => $lemmaUpgrade, 'method' => 'lemma_rules'];
            }

            return ['word' => $selected, 'method' => 'exact'];
        }

        foreach ($this->lemmas->candidates($selected) as $candidate) {
            if ($this->wordExists($candidate)) {
                return ['word' => $candidate, 'method' => 'lemma_rules'];
            }
        }

        if ($this->datamuseEnabled()) {
            $suggested = $this->spellSuggestions->suggest($selected);

            if ($suggested !== null && $this->wordExists($suggested)) {
                return ['word' => $suggested, 'method' => 'datamuse_spell'];
            }
        }

        return ['word' => $selected, 'method' => 'exact'];
    }

    private function findLemmaUpgrade(string $selected): ?string
    {
        foreach ($this->lemmas->candidates($selected) as $lemma) {
            if (! $this->shouldPreferLemmaForm($selected, $lemma)) {
                continue;
            }

            if ($this->wordExists($lemma)) {
                return $lemma;
            }
        }

        return null;
    }

    private function shouldPreferLemmaForm(string $selected, string $lemma): bool
    {
        if ($selected === $lemma || strlen($lemma) < 4) {
            return false;
        }

        if (! in_array($lemma, $this->lemmas->candidates($selected), true)) {
            return false;
        }

        /** @var array<string, mixed>|null $selectedPayload */
        $selectedPayload = $this->queries->ask(new LookupWord($selected));
        /** @var array<string, mixed>|null $lemmaPayload */
        $lemmaPayload = $this->queries->ask(new LookupWord($lemma));

        if ($lemmaPayload === null) {
            return false;
        }

        if ($selectedPayload === null) {
            return true;
        }

        if ($this->hasPronunciation($selectedPayload)) {
            return false;
        }

        return $this->hasPronunciation($lemmaPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasPronunciation(array $payload): bool
    {
        $phonetic = trim((string) ($payload['phonetic'] ?? ''));
        $audio = trim((string) ($payload['audio_url'] ?? ''));

        return $phonetic !== '' || $audio !== '';
    }

    private function normalizeSelected(string $word): string
    {
        $text = trim($word);
        $first = preg_split('/\s+/', $text)[0] ?? $text;
        $token = preg_replace('/^[^a-zA-Z]+|[^a-zA-Z\'’-]+$/u', '', $first) ?? $first;

        return Text::lower(trim($token));
    }

    private function wordExists(string $word): bool
    {
        if ($this->entries->findByWord($word) !== null) {
            return true;
        }

        return is_array($this->gateway->fetch($word));
    }

    private function datamuseEnabled(): bool
    {
        return (bool) $this->config->get('flc.lookup_resolve_enable_datamuse', true);
    }
}
