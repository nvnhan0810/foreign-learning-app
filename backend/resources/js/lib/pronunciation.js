import { appPath } from '@/path';
/**
 * Pronunciation playback with audio URL → optional fetch → speechSynthesis fallback.
 * Browser APIs are injectable for unit tests.
 */

export const PronounceSource = Object.freeze({
    AUDIO: 'audio',
    FETCHED_AUDIO: 'fetched_audio',
    TTS: 'tts',
    NONE: 'none',
});

/**
 * @param {{
 *   word?: string,
 *   audioUrl?: string | null,
 *   fetchAudioUrl?: () => Promise<string | null | undefined>,
 *   playAudio?: (url: string) => Promise<void>,
 *   speak?: (word: string) => void,
 * }} options
 * @returns {Promise<'audio' | 'fetched_audio' | 'tts' | 'none'>}
 */
export async function playPronunciation(options = {}) {
    const word = String(options.word || '').trim();
    const playAudio = options.playAudio ?? defaultPlayAudio;
    const speak = options.speak ?? defaultSpeak;
    const audioUrl = String(options.audioUrl || '').trim();

    if (audioUrl) {
        try {
            await playAudio(audioUrl);
            return PronounceSource.AUDIO;
        } catch {
            // Broken CDN / autoplay / network — try fetch then TTS.
        }
    }

    if (typeof options.fetchAudioUrl === 'function') {
        try {
            const fetched = String((await options.fetchAudioUrl()) || '').trim();
            if (fetched) {
                await playAudio(fetched);
                return PronounceSource.FETCHED_AUDIO;
            }
        } catch {
            // Fall through to TTS.
        }
    }

    if (word) {
        speak(word);
        return PronounceSource.TTS;
    }

    return PronounceSource.NONE;
}

/**
 * @param {string} word
 * @returns {Promise<string | null>}
 */
export async function fetchDictionaryPronounceUrl(word) {
    const normalized = String(word || '').trim();
    if (!normalized) {
        return null;
    }

    const res = await fetch(appPath(`/home/dictionary/${encodeURIComponent(normalized)}/pronounce`), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!res.ok) {
        return null;
    }

    const payload = await res.json();
    const url = payload?.audio_url;

    return typeof url === 'string' && url.trim() !== '' ? url.trim() : null;
}

/**
 * @param {string} url
 * @returns {Promise<void>}
 */
function defaultPlayAudio(url) {
    return new Audio(url).play();
}

/**
 * @param {string} word
 */
function defaultSpeak(word) {
    if (!word || typeof window === 'undefined' || !('speechSynthesis' in window)) {
        return;
    }

    const utterance = new SpeechSynthesisUtterance(word);
    utterance.lang = 'en-US';
    speechSynthesis.speak(utterance);
}
