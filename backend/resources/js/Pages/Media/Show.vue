<script setup>
import { appPath } from '@/path';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const page = usePage();
const isFlcApp = computed(() => !!page.props.isFlcApp);

const youtubeWatchUrl = computed(() => {
    if (props.item.url) return props.item.url;
    if (props.item.source_id) {
        return `https://www.youtube.com/watch?v=${props.item.source_id}`;
    }
    return null;
});

const youtubeThumbUrl = computed(() => {
    if (!props.item.source_id) return null;
    return `https://i.ytimg.com/vi/${props.item.source_id}/hqdefault.jpg`;
});

const editing = ref(false);

const transcriptForm = useForm({
    transcript: props.item.transcript || '',
});

const difficultyLabel = computed(() => {
    if (props.item.difficulty_label) return props.item.difficulty_label;
    if (props.item.difficulty === 'beginner') return 'Beginner';
    if (props.item.difficulty === 'advanced') return 'Advanced';
    return 'Intermediate';
});

function startEdit() {
    editing.value = true;
    transcriptForm.transcript = props.item.transcript || '';
}

function cancelEdit() {
    editing.value = false;
    transcriptForm.transcript = props.item.transcript || '';
}

function saveTranscript() {
    transcriptForm.put(`/home/media/${props.item.id}/transcript`, {
        onSuccess: () => {
            editing.value = false;
        },
    });
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
                <div v-else-if="item.type === 'youtube' && item.source_id" class="video-embed">
                    <iframe
                        :src="`https://www.youtube.com/embed/${item.source_id}?playsinline=1&rel=0`"
                        :title="item.title"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen
                        playsinline
                    />
                </div>
                <p v-else-if="item.url" class="media-show-open-link">
                    <a :href="item.url" target="_blank" rel="noopener" class="btn">Open media</a>
                </p>

                <section class="transcript-panel" aria-label="Transcript">
                    <div class="transcript-panel-head">
                        <h2 class="transcript-panel-title">Transcript</h2>
                        <div class="transcript-panel-actions">
                            <template v-if="!editing">
                                <button type="button" class="btn btn-sm btn-secondary" @click="startEdit">
                                    {{ item.transcript ? 'Edit' : 'Add' }}
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :disabled="transcriptForm.processing"
                                    @click="saveTranscript"
                                >
                                    Save
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" @click="cancelEdit">
                                    Cancel
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="transcript-scroll-panel">
                        <div class="transcript-view" :hidden="editing">
                            <div v-if="item.transcript" class="transcript-text">{{ item.transcript }}</div>
                            <p v-else class="muted transcript-empty">No transcript yet.</p>
                        </div>
                        <form v-show="editing" class="transcript-form" @submit.prevent="saveTranscript">
                            <textarea
                                v-model="transcriptForm.transcript"
                                class="transcript-textarea"
                                placeholder="Enter the video or audio transcript..."
                                spellcheck="false"
                            />
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
