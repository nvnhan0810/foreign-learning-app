<script setup>
import { appPath } from '@/path';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import WordChat from '@/Components/WordChat.vue';

const props = defineProps({
    item: { type: Object, required: true },
    listeningOptions: { type: Array, default: () => [] },
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

const chatOpen = ref(false);
const transcriptOpen = ref(false);
const editing = ref(false);
const chatRef = ref(null);
const isDesktop = ref(false);

const transcriptForm = useForm({
    transcript: props.item.transcript || '',
});

const bankReady = computed(
    () => props.item.question_bank_status === 'ready' && Number(props.item.question_bank_count || 0) > 0,
);

const difficultyLabel = computed(() => {
    if (props.item.difficulty_label) return props.item.difficulty_label;
    if (props.item.difficulty === 'beginner') return 'Beginner';
    if (props.item.difficulty === 'advanced') return 'Advanced';
    return 'Intermediate';
});

function syncDesktop() {
    isDesktop.value = window.matchMedia('(min-width: 900px)').matches;
}

async function openChat() {
    chatOpen.value = true;
    document.body.classList.toggle('media-word-chat-open', !isDesktop.value);
    await nextTick();
    if (chatRef.value?.ensureAgent) {
        await chatRef.value.ensureAgent();
        await chatRef.value.loadHistory?.();
    }
}

function closeChat() {
    chatOpen.value = false;
    document.body.classList.remove('media-word-chat-open');
}

function startEdit() {
    editing.value = true;
    transcriptOpen.value = true;
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

function startSession(type) {
    useForm({ type }).post(`/media/${props.item.id}/listening/start`);
}

watch(chatOpen, (open) => {
    document.body.classList.toggle('media-word-chat-open', open && !isDesktop.value);
});

onMounted(() => {
    syncDesktop();
    window.addEventListener('resize', syncDesktop);
});

onUnmounted(() => {
    window.removeEventListener('resize', syncDesktop);
    document.body.classList.remove('media-word-chat-open');
});
</script>

<template>
    <Head :title="item.title" />
    <AppLayout :title="item.title" :heading="item.title" :back-href="appPath('/home/media')" :hide-nav="chatOpen && !isDesktop">
        <div class="media-show-page" :class="{ 'is-chat-open': chatOpen }">
            <div class="media-show-main">
                <div class="media-show-toolbar">
                    <span
                        class="difficulty-tag"
                        :class="`difficulty-tag--${item.difficulty || 'intermediate'}`"
                    >{{ difficultyLabel }}</span>
                    <button type="button" class="btn btn-sm btn-secondary media-word-chat-open-btn" @click="openChat">
                        Ask word
                    </button>
                </div>

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
                <p v-else-if="item.url" style="margin:16px 0">
                    <a :href="item.url" target="_blank" rel="noopener" class="btn">Open media</a>
                </p>

                <details class="transcript-collapse" :open="transcriptOpen || editing" @toggle="transcriptOpen = $event.target.open">
                    <summary class="transcript-summary">
                        <span class="transcript-summary-label">Transcript</span>
                        <span class="transcript-summary-actions">
                            <span class="transcript-toolbar-view" :hidden="editing">
                                <button type="button" class="btn btn-sm btn-secondary" @click.prevent="startEdit">
                                    {{ item.transcript ? 'Edit transcript' : 'Add transcript' }}
                                </button>
                            </span>
                            <span class="transcript-toolbar-edit" :hidden="!editing">
                                <button type="button" class="btn btn-sm" :disabled="transcriptForm.processing" @click.prevent="saveTranscript">
                                    Save
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" @click.prevent="cancelEdit">Cancel</button>
                            </span>
                            <span class="transcript-collapse-icon" aria-hidden="true">▾</span>
                        </span>
                    </summary>
                    <div class="transcript-collapse-body">
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
                    </div>
                </details>

                <h2 style="font-size:16px;margin:24px 0 12px">Listening quiz</h2>

                <template v-if="!bankReady">
                    <p class="muted">
                        Question bank is not ready yet
                        ({{ item.question_bank_status || 'pending' }}).
                        Wait for an admin to finish analyzing the media.
                    </p>
                </template>
                <template v-else>
                    <p class="muted" style="margin-bottom:12px">
                        {{ item.question_bank_count }} questions in the bank — each attempt picks a new random set.
                    </p>

                    <div
                        v-for="option in listeningOptions"
                        :key="option.type"
                        class="session-row"
                    >
                        <div class="list-item-body">
                            <p class="title">{{ option.title }}</p>
                            <p class="subtitle">
                                {{ String(option.type || '').toUpperCase() }}
                                · {{ option.question_count }} questions
                                <template v-if="option.time_limit_minutes">
                                    · {{ option.time_limit_minutes }} min
                                </template>
                            </p>
                        </div>
                        <button
                            v-if="option.available"
                            type="button"
                            class="btn btn-sm"
                            @click="startSession(option.type)"
                        >Start</button>
                        <span v-else class="muted">Not enough questions</span>
                    </div>
                </template>
            </div>

            <div
                class="media-word-chat-backdrop"
                :hidden="!chatOpen"
                @click="closeChat"
            />
            <aside
                class="media-word-chat-sidebar"
                :hidden="!chatOpen"
                :aria-hidden="chatOpen ? 'false' : 'true'"
                aria-label="Word chat while listening"
            >
                <div class="media-word-chat-sidebar-head">
                    <h2 class="media-word-chat-sidebar-title">Learn</h2>
                    <button type="button" class="media-word-chat-close" aria-label="Close chat" @click="closeChat">×</button>
                </div>
                <WordChat
                    ref="chatRef"
                    lazy
                    variant="sidebar"
                    empty-title="Ask while you listen"
                    empty-hint="Try: “What does outlet mean in this video?” Select text in the transcript, open chat, and it will be prefilled."
                />
            </aside>
        </div>
    </AppLayout>
</template>
