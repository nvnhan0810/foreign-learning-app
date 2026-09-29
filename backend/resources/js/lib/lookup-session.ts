import type { DictionaryLookupResult, Meaning } from '@/lib/meanings-json';

export const LOOKUP_SESSION_KEY = 'flc-lookup-session';

export type LookupViewMode = 'ui' | 'json';

export type WebLookupSession = {
    word: string;
    result: DictionaryLookupResult;
    draft: boolean;
    saved: boolean;
    savedVocabularyId: number | string | null;
    meaningsViewMode: LookupViewMode;
    jsonText: string;
    meanings: Meaning[];
    synonyms: string[];
    antonyms: string[];
    updatedAt: string;
};

export function loadLookupSession(): WebLookupSession | null {
    try {
        const raw = localStorage.getItem(LOOKUP_SESSION_KEY);
        if (!raw) {
            return null;
        }
        const parsed: unknown = JSON.parse(raw);
        if (!isLookupSession(parsed)) {
            return null;
        }
        return parsed;
    } catch {
        return null;
    }
}

export function saveLookupSession(session: WebLookupSession): void {
    try {
        localStorage.setItem(LOOKUP_SESSION_KEY, JSON.stringify(session));
    } catch {
        // Quota / private mode — ignore.
    }
}

export function clearLookupSession(): void {
    try {
        localStorage.removeItem(LOOKUP_SESSION_KEY);
    } catch {
        // ignore
    }
}

function isLookupSession(value: unknown): value is WebLookupSession {
    if (value === null || typeof value !== 'object') {
        return false;
    }
    const row = value as Record<string, unknown>;
    if (typeof row.word !== 'string' || row.word.trim() === '') {
        return false;
    }
    if (row.result === null || typeof row.result !== 'object') {
        return false;
    }
    const result = row.result as Record<string, unknown>;
    if (typeof result.word !== 'string' || result.word.trim() === '') {
        return false;
    }
    if (row.meaningsViewMode !== 'ui' && row.meaningsViewMode !== 'json') {
        return false;
    }
    if (typeof row.jsonText !== 'string') {
        return false;
    }
    if (!Array.isArray(row.meanings)) {
        return false;
    }
    return true;
}
