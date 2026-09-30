<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    createYoutubePlayer,
    YoutubePlayerState,
    type YoutubePlayer,
} from '@/lib/youtube-iframe-api';

const TIME_POLL_MS = 250;

const props = defineProps<{
    videoId: string;
    title: string;
}>();

const emit = defineEmits<{
    timeupdate: [seconds: number];
    ready: [];
    playingchange: [isPlaying: boolean];
}>();

const hostRef = ref<HTMLElement | null>(null);
const playerRef = ref<YoutubePlayer | null>(null);
const pollTimer = ref<number | null>(null);
const isPlaying = ref(false);

function clearPoll(): void {
    if (pollTimer.value !== null) {
        window.clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

function emitCurrentTime(player: YoutubePlayer): void {
    if (typeof player.getCurrentTime !== 'function') {
        return;
    }

    emit('timeupdate', player.getCurrentTime());
}

function setPlaying(next: boolean): void {
    if (isPlaying.value === next) {
        return;
    }
    isPlaying.value = next;
    emit('playingchange', next);
}

function startPoll(player: YoutubePlayer): void {
    clearPoll();
    emitCurrentTime(player);
    pollTimer.value = window.setInterval(() => {
        emitCurrentTime(player);
    }, TIME_POLL_MS);
}

function handleStateChange(state: number, player: YoutubePlayer): void {
    if (state === YoutubePlayerState.Playing) {
        setPlaying(true);
        startPoll(player);
        return;
    }

    setPlaying(false);
    clearPoll();
    emitCurrentTime(player);
}

async function mountPlayer(): Promise<void> {
    const host = hostRef.value;
    if (!host || !props.videoId) {
        return;
    }

    host.replaceChildren();
    const mount = document.createElement('div');
    host.appendChild(mount);

    const player = await createYoutubePlayer({
        element: mount,
        videoId: props.videoId,
        onReady: (readyPlayer): void => {
            playerRef.value = readyPlayer;
            emit('ready');
            emitCurrentTime(readyPlayer);
        },
        onStateChange: handleStateChange,
    });

    playerRef.value = player;
}

function destroyPlayer(): void {
    clearPoll();
    setPlaying(false);
    const player = playerRef.value;
    playerRef.value = null;

    if (player && typeof player.destroy === 'function') {
        player.destroy();
    }

    hostRef.value?.replaceChildren();
}

function seekTo(seconds: number, andPlay = true): void {
    const player = playerRef.value;
    if (!player || typeof player.seekTo !== 'function') {
        return;
    }

    player.seekTo(seconds, true);
    emit('timeupdate', seconds);

    if (andPlay && typeof player.playVideo === 'function') {
        player.playVideo();
    }
}

function play(): void {
    const player = playerRef.value;
    if (player && typeof player.playVideo === 'function') {
        setPlaying(true);
        player.playVideo();
    }
}

function pause(): void {
    const player = playerRef.value;
    if (player && typeof player.pauseVideo === 'function') {
        setPlaying(false);
        player.pauseVideo();
        emitCurrentTime(player);
    }
}

function togglePlayback(): boolean {
    if (isPlaying.value) {
        pause();
        return false;
    }

    play();
    return true;
}

watch(
    () => props.videoId,
    async () => {
        destroyPlayer();
        await mountPlayer();
    },
);

onMounted(() => {
    void mountPlayer();
});

onBeforeUnmount(() => {
    destroyPlayer();
});

defineExpose({
    seekTo,
    play,
    pause,
    togglePlayback,
    isPlaying,
});
</script>

<template>
    <div class="video-embed video-embed--youtube-api" :aria-label="title">
        <div ref="hostRef" class="youtube-player-host" />
    </div>
</template>
