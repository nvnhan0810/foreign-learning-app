<script setup lang="ts">
import { appPath } from '@/path';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import YouTubeSyncedPlayer from '@/Components/media/YouTubeSyncedPlayer.vue';
import {
    createEmptySegment,
    findActiveSegmentIndex,
    formatClockPrecise,
    parseClock,
    parseTranscriptSegments,
    type TranscriptSegment,
} from '@/lib/transcript-sync';

type EditRow = {
    id: string;
    startInput: string;
    endInput: string;
    text: string;
};

type MediaEditItem = {
    id: number;
    title: string;
    type: string;
    source_id: string | null;
    url: string | null;
    difficulty: string | null;
    difficulty_label: string | null;
    transcript: string | null;
    transcript_segments: TranscriptSegment[];
};

const props = defineProps<{
    item: MediaEditItem;
}>();

const page = usePage();
const isFlcApp = computed(() => Boolean((page.props as { isFlcApp?: boolean }).isFlcApp));

let rowSeq = 0;

function nextRowId(): string {
    rowSeq += 1;
    return `row-${rowSeq}`;
}

function toEditRow(segment: TranscriptSegment): EditRow {
    return {
        id: nextRowId(),
        startInput: formatClockPrecise(segment.start),
        endInput: formatClockPrecise(segment.end),
        text: segment.text,
    };
}

const initialSegments = parseTranscriptSegments(props.item.transcript_segments);
const rows = ref<EditRow[]>(
    initialSegments.length > 0
        ? initialSegments.map(toEditRow)
        : [toEditRow(createEmptySegment(0))],
);

const saving = ref(false);
const formError = ref<string | null>(null);
const currentTime = ref(0);
const playerRef = ref<InstanceType<typeof YouTubeSyncedPlayer> | null>(null);

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

const canEmbedYoutube = computed(
    (): boolean =>
        props.item.type === 'youtube' && Boolean(props.item.source_id) && !isFlcApp.value,
);

const previewSegments = computed((): TranscriptSegment[] => {
    const segments: TranscriptSegment[] = [];

    for (const row of rows.value) {
        const start = parseClock(row.startInput);
        const end = parseClock(row.endInput);
        const text = row.text.trim();
        if (start === null || end === null || text === '') {
            continue;
        }
        segments.push({
            start,
            end: end > start ? end : start + 0.01,
            text,
        });
    }

    return segments;
});

const activeSegmentIndex = computed((): number =>
    findActiveSegmentIndex(previewSegments.value, currentTime.value),
);

const fieldErrors = reactive<Record<string, string>>({});

function clearRowError(rowId: string): void {
    delete fieldErrors[rowId];
}

function validateRows(): TranscriptSegment[] | null {
    formError.value = null;
    Object.keys(fieldErrors).forEach((key) => {
        delete fieldErrors[key];
    });

    const segments: TranscriptSegment[] = [];
    let hasInvalid = false;

    for (const row of rows.value) {
        const text = row.text.trim();
        const start = parseClock(row.startInput);
        const end = parseClock(row.endInput);

        if (text === '' && row.startInput.trim() === '' && row.endInput.trim() === '') {
            continue;
        }

        if (start === null || end === null) {
            fieldErrors[row.id] = 'Use time like 0:12.500';
            hasInvalid = true;
            continue;
        }

        if (text === '') {
            fieldErrors[row.id] = 'Subtitle text is required';
            hasInvalid = true;
            continue;
        }

        segments.push({
            start,
            end: end > start ? end : start + 0.01,
            text,
        });
    }

    if (hasInvalid) {
        formError.value = 'Fix highlighted rows before saving.';
        return null;
    }

    return segments;
}

function addRowAfter(index: number): void {
    const current = rows.value[index];
    const after = current ? (parseClock(current.endInput) ?? 0) : 0;
    const next = toEditRow(createEmptySegment(after));
    rows.value.splice(index + 1, 0, next);
}

function addRow(): void {
    const last = rows.value[rows.value.length - 1];
    const after = last ? (parseClock(last.endInput) ?? 0) : 0;
    rows.value.push(toEditRow(createEmptySegment(after)));
}

function removeRow(index: number): void {
    const row = rows.value[index];
    if (row) {
        clearRowError(row.id);
    }

    if (rows.value.length <= 1) {
        rows.value = [toEditRow(createEmptySegment(0))];
        return;
    }

    rows.value.splice(index, 1);
}

function useCurrentTime(index: number, field: 'start' | 'end'): void {
    const row = rows.value[index];
    if (!row) {
        return;
    }

    const stamp = formatClockPrecise(currentTime.value);
    if (field === 'start') {
        row.startInput = stamp;
    } else {
        row.endInput = stamp;
    }
    clearRowError(row.id);
}

function seekRow(index: number): void {
    const row = rows.value[index];
    if (!row) {
        return;
    }

    const start = parseClock(row.startInput);
    if (start === null) {
        return;
    }

    playerRef.value?.seekTo(start, true);
}

function onPlayerTimeUpdate(seconds: number): void {
    currentTime.value = seconds;
}

function save(): void {
    const segments = validateRows();
    if (segments === null) {
        return;
    }

    saving.value = true;
    router.put(
        appPath(`/home/media/${props.item.id}/transcript`),
        { segments },
        {
            onFinish: () => {
                saving.value = false;
            },
            onError: () => {
                formError.value = 'Could not save transcript.';
            },
        },
    );
}
</script>

<template>
    <Head :title="`Edit transcript — ${item.title}`" />
    <AppLayout :title="item.title" :hide-nav="true">
        <template #header>
            <header class="user-header media-transcript-edit-header">
                <Link
                    :href="appPath(`/home/media/${item.id}`)"
                    class="user-header-back user-header-back--round"
                    aria-label="Back"
                >←</Link>
                <div class="media-show-header-main">
                    <h1>Edit transcript</h1>
                    <p class="media-transcript-edit-subtitle">{{ item.title }}</p>
                </div>
                <button
                    type="button"
                    class="btn btn-sm"
                    :disabled="saving"
                    @click="save"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </header>
        </template>

        <div class="media-transcript-edit-page">
            <div class="media-transcript-edit-main">
                <aside class="media-transcript-edit-player">
                    <a
                        v-if="item.type === 'youtube' && item.source_id && isFlcApp && youtubeWatchUrl"
                        :href="youtubeWatchUrl"
                        class="video-embed video-embed--external video-embed--compact"
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
                    <p v-else class="muted media-transcript-edit-player-empty">
                        No embedded player for this media.
                    </p>
                    <p class="media-transcript-edit-clock muted">
                        Now: {{ formatClockPrecise(currentTime) }}
                    </p>
                </aside>

                <section class="media-transcript-edit-panel" aria-label="Edit transcript segments">
                    <p v-if="formError" class="media-transcript-edit-error" role="alert">{{ formError }}</p>

                    <div class="media-transcript-edit-list">
                        <article
                            v-for="(row, index) in rows"
                            :key="row.id"
                            class="media-transcript-edit-row"
                            :class="{
                                'is-active': index === activeSegmentIndex,
                                'is-invalid': Boolean(fieldErrors[row.id]),
                            }"
                        >
                            <div class="media-transcript-edit-times">
                                <label class="media-transcript-edit-field">
                                    <span>Start</span>
                                    <input
                                        v-model="row.startInput"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="0:00.000"
                                        @input="clearRowError(row.id)"
                                    >
                                </label>
                                <label class="media-transcript-edit-field">
                                    <span>End</span>
                                    <input
                                        v-model="row.endInput"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="0:02.000"
                                        @input="clearRowError(row.id)"
                                    >
                                </label>
                            </div>

                            <label class="media-transcript-edit-field media-transcript-edit-field--text">
                                <span>Subtitle</span>
                                <textarea
                                    v-model="row.text"
                                    rows="2"
                                    placeholder="Subtitle text"
                                    spellcheck="false"
                                    @input="clearRowError(row.id)"
                                />
                            </label>

                            <p v-if="fieldErrors[row.id]" class="media-transcript-edit-row-error">
                                {{ fieldErrors[row.id] }}
                            </p>

                            <div class="media-transcript-edit-row-actions">
                                <button type="button" class="btn btn-sm btn-secondary" @click="useCurrentTime(index, 'start')">
                                    Start = now
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" @click="useCurrentTime(index, 'end')">
                                    End = now
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-secondary"
                                    :disabled="!canEmbedYoutube"
                                    @click="seekRow(index)"
                                >
                                    Seek
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" @click="addRowAfter(index)">
                                    + Below
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" @click="removeRow(index)">
                                    Remove
                                </button>
                            </div>
                        </article>
                    </div>

                    <div class="media-transcript-edit-footer">
                        <button type="button" class="btn btn-sm btn-secondary" @click="addRow">
                            Add line
                        </button>
                        <button type="button" class="btn btn-sm" :disabled="saving" @click="save">
                            {{ saving ? 'Saving…' : 'Save transcript' }}
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
