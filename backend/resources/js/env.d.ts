/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue';

    const component: DefineComponent<object, object, unknown>;
    export default component;
}

declare module '@/path' {
    export function appPath(path: string): string;
    export function apiPath(path: string): string;
}

declare module '@/lib/pronunciation' {
    export const PronounceSource: Readonly<{
        AUDIO: 'audio';
        FETCHED_AUDIO: 'fetched_audio';
        TTS: 'tts';
        NONE: 'none';
    }>;

    export function playPronunciation(options?: {
        word?: string;
        audioUrl?: string | null;
        fetchAudioUrl?: () => Promise<string | null | undefined>;
        playAudio?: (url: string) => Promise<void>;
        speak?: (word: string) => void;
    }): Promise<'audio' | 'fetched_audio' | 'tts' | 'none'>;

    export function fetchDictionaryPronounceUrl(word: string): Promise<string | null>;
}
