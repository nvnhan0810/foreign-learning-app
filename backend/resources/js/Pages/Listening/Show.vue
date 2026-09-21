<script setup>
import { appPath } from '@/path';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    assessment: { type: Object, required: true },
    mediaItem: { type: Object, default: null },
    questions: { type: Array, default: () => [] },
    result: { type: Object, default: null },
});

const backHref = props.mediaItem ? `/home/media/${props.mediaItem.id}` : appPath('/home/media');

const form = useForm({
    answers: Object.fromEntries((props.questions || []).map((q) => [q.id, ''])),
});

function submit() {
    form.post(`/listening/${props.assessment.id}/submit`);
}
</script>

<template>
    <Head :title="assessment.title || 'Listening'" />
    <AppLayout
        :title="assessment.title || 'Listening'"
        :heading="assessment.title || 'Listening'"
        :back-href="backHref"
        hide-nav
    >
        <template v-if="!result && mediaItem" #below-header>
            <div class="listening-media-sticky" aria-label="Listen to media">
                <div v-if="mediaItem.type === 'youtube' && mediaItem.source_id" class="video-embed video-embed--compact">
                    <iframe
                        :src="`https://www.youtube.com/embed/${mediaItem.source_id}?playsinline=1&rel=0`"
                        :title="mediaItem.title"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen
                        playsinline
                    />
                </div>
                <div v-else-if="mediaItem.has_audio" class="audio-player-bar">
                    <p class="audio-player-label">{{ mediaItem.title }}</p>
                    <audio controls preload="metadata" :src="`/home/media/${mediaItem.id}/audio`" />
                </div>
                <div v-else-if="mediaItem.url && mediaItem.type !== 'youtube'" class="audio-player-bar">
                    <p class="audio-player-label">{{ mediaItem.title }}</p>
                    <audio controls preload="metadata" :src="mediaItem.url" />
                </div>
            </div>
        </template>

        <div v-if="result" class="card result-card">
            <p class="muted" style="margin:0 0 8px">Result</p>
            <p class="result-score">{{ result.score }}/{{ result.total }}</p>
            <p style="margin:8px 0 0;font-weight:600">{{ result.percentage }}%</p>
        </div>
        <Link v-if="result" :href="backHref" class="btn btn-block" style="margin-top:16px">Back to media</Link>

        <form v-else @submit.prevent="submit">
            <div
                v-for="(question, index) in questions"
                :key="question.id"
                class="card question-card"
            >
                <p class="question-label">Question {{ index + 1 }}</p>
                <p class="question-prompt">{{ question.prompt }}</p>

                <div v-if="(question.options || []).length" class="choice-group">
                    <label
                        v-for="option in question.options"
                        :key="option"
                        class="choice-card"
                    >
                        <input v-model="form.answers[question.id]" type="radio" :value="option" required>
                        <span>{{ option }}</span>
                    </label>
                </div>
                <input
                    v-else
                    v-model="form.answers[question.id]"
                    type="text"
                    class="form-control"
                    placeholder="Enter your answer..."
                    required
                    autocomplete="off"
                >
            </div>

            <button type="submit" class="btn btn-block btn-submit-sticky" :disabled="form.processing">
                Submit
            </button>
        </form>
    </AppLayout>
</template>
