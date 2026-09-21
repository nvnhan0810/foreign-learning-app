import type { DictionaryResult, Meaning } from './types';

export function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export function playPronunciation(audioUrl: string | null | undefined, word: string): void {
  if (audioUrl) {
    const audio = new Audio(audioUrl);
    void audio.play().catch(() => speakWord(word));
    return;
  }

  speakWord(word);
}

export function speakWord(word: string): void {
  if (!word || !('speechSynthesis' in window)) return;
  const utterance = new SpeechSynthesisUtterance(word);
  utterance.lang = 'en-US';
  speechSynthesis.speak(utterance);
}

export function pronunciationButtonHtml(
  word: string,
  audioUrl?: string | null,
  className = 'flc-speak'
): string {
  return `<button type="button" class="${className}" data-audio="${audioUrl ? escapeHtml(audioUrl) : ''}" data-word="${escapeHtml(word)}" title="Pronounce" aria-label="Pronounce">🔊</button>`;
}

export function bindPronunciationButtons(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('.flc-speak').forEach((btn) => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
      playPronunciation(btn.dataset.audio || null, btn.dataset.word || '');
    });
  });
}

function uniqueWords(words: string[]): string[] {
  const set = new Set<string>();
  for (const w of words) {
    const t = w.trim();
    if (t) set.add(t);
  }
  return Array.from(set);
}

function relatedHtml(words: string[], label: string): string {
  if (words.length === 0) return '';
  return `
    <div class="flc-related-group">
      <div class="flc-related-label">${escapeHtml(label)}</div>
      <div class="flc-related-words">${words
        .map(
          (w) =>
            `<button type="button" class="flc-related-word" data-flc-related-word="${escapeHtml(w)}">${escapeHtml(w)}</button>`
        )
        .join('')}</div>
    </div>`;
}

function meaningsHtml(
  meanings: Meaning[],
  entrySynonyms: string[],
  entryAntonyms: string[],
  maxMeanings: number
): string {
  if (meanings.length === 0) {
    const related =
      relatedHtml(entrySynonyms, 'Synonyms') + relatedHtml(entryAntonyms, 'Antonyms');
    return `<p class="flc-empty muted">No detailed definitions yet.</p>${related}`;
  }

  return meanings
    .slice(0, maxMeanings)
    .map((m, index) => {
      const synonyms = uniqueWords([
        ...(m.synonyms ?? []),
        ...(index === 0 ? entrySynonyms : []),
      ]);
      const antonyms = uniqueWords([
        ...(m.antonyms ?? []),
        ...(index === 0 ? entryAntonyms : []),
      ]);

      return `
      <div class="flc-meaning">
        ${m.part_of_speech ? `<div class="flc-pos">${escapeHtml(m.part_of_speech)}</div>` : ''}
        <div class="flc-def">${escapeHtml(m.definition)}</div>
        ${m.example ? `<div class="flc-example">"${escapeHtml(m.example)}"</div>` : ''}
        ${relatedHtml(synonyms, 'Synonyms')}
        ${relatedHtml(antonyms, 'Antonyms')}
      </div>`;
    })
    .join('');
}

export function renderDictionaryHtml(data: DictionaryResult, maxMeanings = 5): string {
  return `
    <div class="flc-dict-entry" data-flc-dict>
      <div class="flc-word-head">
        <strong>${escapeHtml(data.word)}</strong>
        ${data.phonetic ? `<span class="flc-phonetic">${escapeHtml(data.phonetic)}</span>` : ''}
        ${pronunciationButtonHtml(data.word, data.audio_url)}
      </div>
      <div class="flc-meanings">
        ${meaningsHtml(data.meanings, data.synonyms ?? [], data.antonyms ?? [], maxMeanings)}
      </div>
    </div>
  `;
}

export function bindRelatedWordClicks(
  root: ParentNode,
  onWord: (word: string) => void
): void {
  root.querySelectorAll<HTMLElement>('[data-flc-dict]').forEach((entry) => {
    if (entry.dataset.relatedBound === '1') return;
    entry.dataset.relatedBound = '1';
    entry.addEventListener('click', (e) => {
      const target = (e.target as HTMLElement | null)?.closest<HTMLElement>(
        '[data-flc-related-word]'
      );
      if (!target || !entry.contains(target)) return;
      const word = target.dataset.flcRelatedWord?.trim();
      if (!word) return;
      e.preventDefault();
      onWord(word);
    });
  });
}

/** Pick API lookup term: single word as-is, otherwise first word of the phrase. */
export function lookupTermFromSelection(text: string): string {
  const trimmed = normalizeSelection(text);
  const first = trimmed.split(/\s+/)[0] ?? trimmed;
  const word = first.replace(/^[^a-zA-Z]+|[^a-zA-Z'’-]+$/g, '');
  return word.toLowerCase();
}

export function normalizeSelection(text: string): string {
  return text
    .replace(/[\u200B-\u200D\uFEFF]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

export function isTranslatableSelection(text: string): boolean {
  const t = normalizeSelection(text);
  if (t.length < 1 || t.length > 400) return false;
  if (!/[a-zA-Z]/.test(t)) return false;
  const word = lookupTermFromSelection(t);
  return word.length >= 1 && word.length <= 48;
}

export function truncateText(text: string, max: number): string {
  if (text.length <= max) return text;
  return `${text.slice(0, max - 1)}…`;
}

export function buildResolveHeader(
  selected: string,
  resolved: string,
  originalSelection?: string
): string {
  const displaySelected = originalSelection?.trim() || selected;

  if (selected !== resolved) {
    if (displaySelected.includes(' ') && displaySelected.toLowerCase() !== selected) {
      return `Selected: <em>${escapeHtml(truncateText(displaySelected, 80))}</em><br>Looking up: <em>${escapeHtml(resolved)}</em>`;
    }

    return `Selected: <em>${escapeHtml(selected)}</em><br>Looking up: <em>${escapeHtml(resolved)}</em>`;
  }

  if (displaySelected.includes(' ')) {
    return `Selected: <em>${escapeHtml(truncateText(displaySelected, 80))}</em><br>Looking up: <em>${escapeHtml(resolved)}</em>`;
  }

  return `Word: <em>${escapeHtml(resolved)}</em>`;
}
