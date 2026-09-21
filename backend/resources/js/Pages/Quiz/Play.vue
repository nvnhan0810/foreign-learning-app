<script setup>
import { appPath } from '@/path';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GameRecordCelebrate from '@/Components/GameRecordCelebrate.vue';
import PuzzleExitModal from '@/Components/PuzzleExitModal.vue';

const exitOpen = ref(false);

const props = defineProps({
    question: { type: Object, default: null },
    feedback: { type: String, default: null },
    wasCorrect: { type: Boolean, default: null },
    sessionCorrect: { type: Number, default: 0 },
    bestCorrect: { type: Number, default: 0 },
    celebrateRecord: { type: [Number, String], default: null },
    autostart: { type: Boolean, default: false },
});

const answered = computed(() => props.feedback != null);
const promptLabel = computed(() => {
    const type = props.question?.question_type || '';
    if (type === 'insight_to_word') return 'From your Learn chat';
    if (type === 'word_to_definition') return 'Choose the meaning';
    return 'Choose the word';
});

const screenClass = computed(() => ({
    'puzzle-screen': true,
    'is-resolved': answered.value,
    'is-playing': !answered.value && !!props.question,
    'is-win': props.wasCorrect === true,
    'is-lose': props.wasCorrect === false,
}));

function start() {
    useForm({}).post(appPath('/home/quiz/next'));
}

function answer(choice) {
    if (!props.question || answered.value) return;
    useForm({
        vocabulary_id: props.question.vocabulary_id,
        question_type: props.question.question_type,
        prompt: props.question.prompt,
        correct_answer: props.question.correct_answer,
        choice,
        insight_id: props.question.insight_id || null,
    }).post(appPath('/home/quiz/answer'));
}

function isCorrectOption(option) {
    return String(option).toLowerCase().trim() === String(props.question?.correct_answer || '').toLowerCase().trim();
}

function confirmExit() {
    exitOpen.value = false;
    router.visit(appPath('/home/quiz'));
}

onMounted(() => {
    if (props.autostart && !props.question) start();
});
</script>

<template>
    <Head title="Quiz" />
    <AppLayout title="Quiz" hide-nav hide-header game-screen>
        <PuzzleExitModal
            :open="exitOpen"
            title="Leave the quiz?"
            @close="exitOpen = false"
            @confirm="confirmExit"
        />
        <div v-if="!question" class="puzzle-screen puzzle-screen-idle">
            <button type="button" class="puzzle-close puzzle-exit-floating" aria-label="Back to games" @click="exitOpen = true">✕</button>
            <div class="puzzle-screen-body">
                <div class="puzzle-game-idle-art" aria-hidden="true">
                    <span>?</span><span>A</span><span>B</span><span>C</span><span>?</span>
                </div>
                <h2 class="puzzle-game-idle-title">Vocabulary Quiz</h2>
                <p class="puzzle-game-idle-sub">Pick the right answer. Review your saved words.</p>
                <button type="button" class="btn puzzle-btn-play" @click="start">▶ Play</button>
            </div>
        </div>

        <template v-else>
            <GameRecordCelebrate :celebrate-record="celebrateRecord" />
            <div :class="screenClass">
                <div class="puzzle-topbar puzzle-topbar-score">
                    <button type="button" class="puzzle-close" aria-label="Back to games" @click="exitOpen = true">✕</button>
                    <span class="puzzle-topbar-spacer" aria-hidden="true" />
                    <div class="game-score-pill" title="Correct this run / personal best">
                        <span class="game-score-current">✓ {{ sessionCorrect }}</span>
                        <span class="game-score-sep">·</span>
                        <span class="game-score-best">Best {{ bestCorrect }}</span>
                    </div>
                </div>

                <div class="puzzle-screen-body">
                    <div class="puzzle-arena">
                        <p class="puzzle-arena-prompt">{{ promptLabel }}</p>
                        <p class="quiz-prompt quiz-game-prompt">{{ question.prompt }}</p>
                    </div>

                    <div class="quiz-game-options">
                        <template v-if="!answered">
                            <button
                                v-for="option in question.options || []"
                                :key="option"
                                type="button"
                                class="qz-option"
                                @click="answer(option)"
                            >
                                {{ option }}
                            </button>
                        </template>
                        <template v-else>
                            <div
                                v-for="option in question.options || []"
                                :key="option"
                                class="qz-option is-static"
                                :class="{ 'is-correct': isCorrectOption(option) }"
                            >
                                {{ option }}
                            </div>
                        </template>
                    </div>

                    <div v-if="answered" class="puzzle-result" :class="wasCorrect ? 'is-win' : 'is-lose'">
                        <div class="puzzle-result-banner">
                            <span class="puzzle-result-title">{{ wasCorrect ? 'Correct!' : 'Wrong' }}</span>
                            <span class="puzzle-result-time">✓ {{ sessionCorrect }}</span>
                        </div>
                        <p class="puzzle-result-msg">{{ feedback }}</p>
                    </div>

                    <button v-if="answered" type="button" class="btn btn-block puzzle-btn-play" @click="start">
                        Next question →
                    </button>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
