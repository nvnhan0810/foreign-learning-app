export const YoutubePlayerState = {
    Unstarted: -1,
    Ended: 0,
    Playing: 1,
    Paused: 2,
    Buffering: 3,
    Cued: 5,
} as const;

export type YoutubePlayerState =
    (typeof YoutubePlayerState)[keyof typeof YoutubePlayerState];

export type YoutubePlayer = {
    destroy: () => void;
    getCurrentTime: () => number;
    getPlayerState: () => number;
    seekTo: (seconds: number, allowSeekAhead: boolean) => void;
    playVideo: () => void;
    pauseVideo: () => void;
};

type YoutubePlayerOptions = {
    videoId: string;
    width?: string | number;
    height?: string | number;
    playerVars?: Record<string, string | number>;
    events?: {
        onReady?: (event: { target: YoutubePlayer }) => void;
        onStateChange?: (event: { data: number; target: YoutubePlayer }) => void;
    };
};

type YoutubeNamespace = {
    Player: new (
        elementId: string | HTMLElement,
        options: YoutubePlayerOptions,
    ) => YoutubePlayer;
    PlayerState: {
        UNSTARTED: number;
        ENDED: number;
        PLAYING: number;
        PAUSED: number;
        BUFFERING: number;
        CUED: number;
    };
};

declare global {
    interface Window {
        YT?: YoutubeNamespace;
        onYouTubeIframeAPIReady?: () => void;
    }
}

const IFRAME_API_SRC = 'https://www.youtube.com/iframe_api';

let loadPromise: Promise<YoutubeNamespace> | null = null;

export function loadYoutubeIframeApi(): Promise<YoutubeNamespace> {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('YouTube IFrame API requires a browser.'));
    }

    if (window.YT?.Player) {
        return Promise.resolve(window.YT);
    }

    if (loadPromise) {
        return loadPromise;
    }

    loadPromise = new Promise<YoutubeNamespace>((resolve, reject) => {
        const previous = window.onYouTubeIframeAPIReady;

        window.onYouTubeIframeAPIReady = (): void => {
            if (typeof previous === 'function') {
                previous();
            }

            if (!window.YT?.Player) {
                reject(new Error('YouTube IFrame API loaded without YT.Player.'));
                return;
            }

            resolve(window.YT);
        };

        const existing = document.querySelector<HTMLScriptElement>(
            `script[src="${IFRAME_API_SRC}"]`,
        );

        if (!existing) {
            const tag = document.createElement('script');
            tag.src = IFRAME_API_SRC;
            tag.async = true;
            tag.onerror = (): void => {
                loadPromise = null;
                reject(new Error('Failed to load YouTube IFrame API.'));
            };
            document.head.appendChild(tag);
        }
    });

    return loadPromise;
}

export type CreateYoutubePlayerParams = {
    element: HTMLElement;
    videoId: string;
    onReady?: (player: YoutubePlayer) => void;
    onStateChange?: (state: number, player: YoutubePlayer) => void;
};

export async function createYoutubePlayer(
    params: CreateYoutubePlayerParams,
): Promise<YoutubePlayer> {
    const YT = await loadYoutubeIframeApi();
    const origin = window.location.origin;

    return new YT.Player(params.element, {
        videoId: params.videoId,
        width: '100%',
        height: '100%',
        playerVars: {
            playsinline: 1,
            rel: 0,
            enablejsapi: 1,
            origin,
        },
        events: {
            onReady: (event): void => {
                params.onReady?.(event.target);
            },
            onStateChange: (event): void => {
                params.onStateChange?.(event.data, event.target);
            },
        },
    });
}
