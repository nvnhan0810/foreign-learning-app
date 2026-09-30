<script setup lang="ts">
import { appPath } from '@/path';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import YouTubeSyncedPlayer from '@/Components/media/YouTubeSyncedPlayer.vue';
import TimedTranscriptList from '@/Components/media/TimedTranscriptList.vue';
import {
    findActiveSegmentIndex,
    parseTranscriptSegments,
    type TranscriptSegment,
} from '@/lib/transcript-sync';

type MediaShowItem = {
    id: number;
    title: string;
    type: string;
    source_id: string | null;
    url: string | null;
    frequency: string | null;
    difficulty: string | null;
    difficulty_label: string | null;
    transcript: string | null;
    transcript_segments: TranscriptSegment[] | null;
    analysis_status: string | null;
};

const props = defineProps<{
    item: MediaShowItem;
}>();

const page = usePage();
const isFlcApp = computed(() => Boolean((page.props as { isFlcApp?: boolean }).isFlcApp));

const youtubeWatchUrl = computed((): string | null => {
    if (props.item.url) {
        return props.item.url;
    }
    if (props.item.source_id) {
        return `https://www.youtube.com/watch?v=${props.item.source_id}`;
    }
    return null;
});

const youtubeThumbUrl = computed((): string | null => {
    if (!props.item.source_id) {
        return null;
    }
    return `https://i.ytimg.com/vi/${props.item.source_id}/hqdefault.jpg`;
});

const currentTime = ref(0);
const playerRef = ref<InstanceType<typeof YouTubeSyncedPlayer> | null>(null);

const segments = computed((): TranscriptSegment[] =>
    parseTranscriptSegments(props.item.transcript_segments),
);

const hasTimedTranscript = computed((): boolean => segments.value.length > 0);

const activeSegmentIndex = computed((): number =>
    findActiveSegmentIndex(segments.value, currentTime.value),
);

const difficultyLabel = computed((): string => {
    if (props.item.difficulty_label) {
        return props.item.difficulty_label;
    }
    if (props.item.difficulty === 'beginner') {
        return 'Beginner';
    }
    if (props.item.difficulty === 'advanced') {
        return 'Advanced';
    }
    return 'Intermediate';
});

const canEmbedYoutube = computed(
    (): boolean =>
        props.item.type === 'youtube' && Boolean(props.item.source_id) && !isFlcApp.value,
);

function onPlayerTimeUpdate(seconds: number): void {
    currentTime.value = seconds;
}

function onTranscriptSeek(seconds: number): void {
    playerRef.value?.seekTo(seconds, true);
}
</script>

<template>
    <Head :title="item.title" />
    <AppLayout :title="item.title">
        <template #header>
            <header class="user-header media-show-header">
                <Link
                    :href="appPath('/home/media')"
                    class="user-header-back user-header-back--round"
                    aria-label="Back"
                >←</Link>
                <div class="media-show-header-main">
                    <span
                        class="difficulty-tag"
                        :class="`difficulty-tag--${item.difficulty || 'intermediate'}`"
                    >{{ difficultyLabel }}</span>
                    <h1>{{ item.title }}</h1>
                </div>
                <span class="user-header-spacer" aria-hidden="true" />
            </header>
        </template>

        <div class="media-show-page">
            <div class="media-show-main">
                <a
                    v-if="item.type === 'youtube' && item.source_id && isFlcApp && youtubeWatchUrl"
                    :href="youtubeWatchUrl"
                    class="video-embed video-embed--external"
                    :aria-label="`Open ${item.title} in YouTube`"
                >
                    <img
                        v-if="youtubeThumbUrl"
                        :src="youtubeThumbUrl"
                        :alt="item.title"
                        class="video-embed-thumb"
                    />
                    <span class="video-embed-play" aria-hidden="true">▶</span>
                    <span class="video-embed-label">Open in YouTube</span>
                </a>
                <YouTubeSyncedPlayer
                    v-else-if="canEmbedYoutube && item.source_id"
                    ref="playerRef"
                    :video-id="item.source_id"
                    :title="item.title"
                    @timeupdate="onPlayerTimeUpdate"
                />
                <p v-else-if="item.url" class="media-show-open-link">
                    <a :href="item.url" target="_blank" rel="noopener" class="btn">Open media</a>
                </p>

                <section class="transcript-panel" aria-label="Transcript">
                    <div class="transcript-panel-head">
                        <h2 class="transcript-panel-title">Transcript</h2>
                        <div class="transcript-panel-actions">
                            <Link
                                :href="appPath(`/home/media/${item.id}/transcript/edit`)"
                                class="btn btn-sm btn-secondary"
                            >
                                {{ item.transcript || hasTimedTranscript ? 'Edit' : 'Add' }}
                            </Link>
                        </div>
                    </div>

                    <div class="transcript-scroll-panel">
                        <div class="transcript-view">
                            <TimedTranscriptList
                                v-if="hasTimedTranscript"
                                :segments="segments"
                                :active-index="activeSegmentIndex"
                                @seek="onTranscriptSeek"
                            />
                            <div v-else-if="item.transcript" class="transcript-text">{{ item.transcript }}</div>
                            <p v-else class="muted transcript-empty">No transcript yet.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
