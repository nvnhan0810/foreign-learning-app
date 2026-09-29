export type Meaning = {
    part_of_speech: string | null;
    definition: string;
    examples: string[];
    synonyms: string[];
    antonyms: string[];
};

export type DictionaryLookupResult = {
    word: string;
    phonetic?: string | null;
    audio_url?: string | null;
    meanings?: MeaningLike[];
    synonyms?: string[];
    antonyms?: string[];
    source?: string | null;
    curated?: boolean;
};

/** Loose meaning shape from API / Inertia props before normalization. */
export type MeaningLike = {
    part_of_speech?: string | null;
    definition?: string | null;
    example?: string | null;
    examples?: unknown;
    synonyms?: unknown;
    antonyms?: unknown;
};

/**
 * Normalize AI/clipboard JSON into meanings for save.
 * Accepts a raw array, { meanings: [...] }, or markdown-fenced JSON.
 */
export function parseMeaningsJson(raw: string): Meaning[] {
    const trimmed = stripMarkdownFences(raw.trim());
    if (trimmed === '') {
        throw new Error('JSON is empty.');
    }

    let decoded: unknown;
    try {
        decoded = JSON.parse(trimmed) as unknown;
    } catch {
        throw new Error('Invalid JSON.');
    }

    let items: unknown[];
    if (Array.isArray(decoded)) {
        items = decoded;
    } else if (
        decoded !== null &&
        typeof decoded === 'object' &&
        Array.isArray((decoded as { meanings?: unknown }).meanings)
    ) {
        items = (decoded as { meanings: unknown[] }).meanings;
    } else {
        throw new Error('JSON must be a meanings array or { "meanings": [...] }.');
    }

    const meanings: Meaning[] = [];
    for (const item of items) {
        if (item === null || typeof item !== 'object') {
            continue;
        }
        const row = item as Record<string, unknown>;
        const definition = typeof row.definition === 'string' ? row.definition.trim() : '';
        if (definition === '') {
            continue;
        }

        const examples = stringList(row.examples);
        if (
            examples.length === 0 &&
            typeof row.example === 'string' &&
            row.example.trim() !== ''
        ) {
            examples.push(row.example.trim());
        }

        const pos =
            typeof row.part_of_speech === 'string' && row.part_of_speech.trim() !== ''
                ? row.part_of_speech.trim()
                : null;

        meanings.push({
            part_of_speech: pos,
            definition,
            examples,
            synonyms: stringList(row.synonyms),
            antonyms: stringList(row.antonyms),
        });
    }

    if (meanings.length === 0) {
        throw new Error('Need at least one meaning with a definition.');
    }

    return meanings;
}

export function meaningsToPrettyJson(meanings: readonly MeaningLike[]): string {
    const payload = meanings.map((m) => {
        const fromExamples = stringList(m.examples);
        const examples =
            fromExamples.length > 0
                ? fromExamples
                : typeof m.example === 'string' && m.example.trim() !== ''
                  ? [m.example.trim()]
                  : [];

        return {
            part_of_speech:
                typeof m.part_of_speech === 'string' && m.part_of_speech.trim() !== ''
                    ? m.part_of_speech.trim()
                    : null,
            definition: typeof m.definition === 'string' ? m.definition : '',
            examples,
            synonyms: stringList(m.synonyms),
            antonyms: stringList(m.antonyms),
        };
    });

    return JSON.stringify(payload, null, 2);
}

/** Local AI prompt for meanings JSON — same template as the backend. */
export function buildMeaningsAiPrompt(
    word: string,
    currentMeanings: readonly MeaningLike[] = [],
): string {
    const label = word.trim() !== '' ? word.trim() : '{WORD}';
    const hasCurrent = currentMeanings.some(
        (m) => typeof m.definition === 'string' && m.definition.trim() !== '',
    );
    const currentBlock = hasCurrent
        ? `

Current meanings JSON (starting point — refine, do not discard useful senses without reason):
${meaningsToPrettyJson(currentMeanings)}
`
        : '';

    return `You are helping curate an English dictionary entry for FLC.

Word / phrase: ${label}
${currentBlock}
Return ONLY a valid JSON array (no markdown fences, no commentary) of meanings in this exact schema:

[
  {
    "part_of_speech": "adjective",
    "definition": "Feeling or showing pleasure",
    "examples": ["She looks happy today."],
    "synonyms": ["joyful", "glad"],
    "antonyms": ["sad", "unhappy"]
  }
]

Rules:
- Top-level MUST be a JSON array (or optionally {"meanings":[...]}).
- Each item MUST have a non-empty string "definition".
- "part_of_speech" is optional (noun, verb, adjective, adverb, phrase, idiom, ...). Use null or omit if unknown.
- "examples", "synonyms", "antonyms" MUST be arrays of strings. Use [] when empty.
- Prefer clear learner-friendly English definitions.
- ALL string values in the JSON MUST be English only. Do NOT put Vietnamese (or any non-English language) in definition, examples, synonyms, antonyms, or part_of_speech.
- Include multiple meanings when the word has distinct senses.
- Do not include extra keys (no "example" singular — use "examples").
- Prefer one clear entry per distinct meaning; deduplicate overlapping senses.
- If Current meanings JSON is present, improve that JSON: fix gaps, add missing senses/examples/synonyms/antonyms, keep valid existing senses.`;
}

export async function copyTextToClipboard(text: string): Promise<void> {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.left = '-9999px';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    document.body.removeChild(area);
}

function stripMarkdownFences(text: string): string {
    const fenced = text.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/i);
    const body = fenced?.[1];
    if (typeof body === 'string') {
        return body.trim();
    }
    return text;
}

function stringList(value: unknown): string[] {
    if (!Array.isArray(value)) {
        return [];
    }
    const out: string[] = [];
    for (const item of value) {
        if (typeof item !== 'string') {
            continue;
        }
        const trimmed = item.trim();
        if (trimmed !== '') {
            out.push(trimmed);
        }
    }
    return out;
}
