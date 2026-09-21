<script setup>
import { appPath } from '@/path';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GameRecordCelebrate from '@/Components/GameRecordCelebrate.vue';
import PuzzleExitModal from '@/Components/PuzzleExitModal.vue';

const props = defineProps({
    puzzle: { type: Object, default: null },
    hint: { type: Object, default: null },
    feedback: { type: String, default: null },
    wasCorrect: { type: Boolean, default: null },
    reveal: { type: Object, default: null },
    startedAt: { type: Number, default: null },
    wordStartedAt: { type: Number, default: null },
    elapsedSeconds: { type: Number, default: null },
    sessionCorrect: { type: Number, default: 0 },
    bestCorrect: { type: Number, default: 0 },
    celebrateRecord: { type: [Number, String], default: null },
});

const answered = computed(() => props.feedback != null);
const letters = computed(() => String(props.puzzle?.scrambled || '').split('').filter(Boolean));
const wordLength = computed(() => Number(props.puzzle?.word_length || letters.value.length));
const hintUsed = computed(() => Array.isArray(props.hint) || !!props.hint);
const hintDefinition = computed(
    () => props.hint?.definition || props.puzzle?.hint_definition || '',
);
const hintPos = computed(
    () => props.hint?.part_of_speech || props.puzzle?.hint_part_of_speech || null,
);
const placement = ref([]);
const hintReady = ref(hintUsed.value);
const countdown = ref(15);
const timerText = ref('00:00');
let countdownTimer = null;
let clockTimer = null;

function formatTime(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

function resetPlacement() {
    placement.value = Array.from({ length: wordLength.value }, () => null);
}

function builtAnswer() {
    return placement.value
        .map((id) => (id === null ? '' : letters.value[id] || ''))
        .join('')
        .toLowerCase();
}

const complete = computed(() => placement.value.length > 0 && placement.value.every((id) => id !== null));

function placeKey(keyId) {
    if (answered.value) return;
    const emptyIndex = placement.value.indexOf(null);
    if (emptyIndex === -1) return;
    if (placement.value.includes(keyId)) return;
    const next = [...placement.value];
    next[emptyIndex] = keyId;
    placement.value = next;
}

function clearSlot(index) {
    if (answered.value || placement.value[index] === null) return;
    const next = [...placement.value];
    next[index] = null;
    placement.value = next;
}

function clearAll() {
    resetPlacement();
}

function submit() {
    if (!complete.value || answered.value) return;
    useForm({ answer: builtAnswer() }).post(appPath('/home/puzzle/scramble/answer'));
}

function next() {
    useForm({}).post(appPath('/home/puzzle/scramble/next'));
}

const exitOpen = ref(false);

function confirmExit() {
    exitOpen.value = false;
    router.visit(appPath('/home/puzzle'));
}

function startTimers() {
    if (countdownTimer) window.clearInterval(countdownTimer);
    if (clockTimer) window.clearInterval(clockTimer);

    if (props.startedAt) {
        const tickClock = () => {
            timerText.value = formatTime(Math.floor(Date.now() / 1000) - props.startedAt);
        };
        tickClock();
        clockTimer = window.setInterval(tickClock, 1000);
    }

    if (!answered.value && !hintReady.value && props.wordStartedAt) {
        const delayMs = 15000;
        const update = () => {
            const elapsed = Date.now() - props.wordStartedAt * 1000;
            const remaining = Math.max(0, Math.ceil((delayMs - elapsed) / 1000));
            countdown.value = remaining;
            if (elapsed >= delayMs) {
                hintReady.value = true;
                if (countdownTimer) window.clearInterval(countdownTimer);
            }
        };
        update();
        countdownTimer = window.setInterval(update, 250);
    }
}

watch(
    () => props.puzzle?.scrambled,
    () => {
        resetPlacement();
        hintReady.value = hintUsed.value;
        startTimers();
    },
    { immediate: true },
);

onMounted(() => {
    resetPlacement();
    startTimers();
});

onUnmounted(() => {
    if (countdownTimer) window.clearInterval(countdownTimer);
    if (clockTimer) window.clearInterval(clockTimer);
});

const screenClass = computed(() => ({
    'puzzle-screen': true,
    'scramble-screen': true,
    'is-resolved': answered.value,
    'is-playing': !answered.value,
    'is-win': props.wasCorrect === true,
    'is-lose': props.wasCorrect === false,
}));
</script>

<template>
    <Head title="Scramble" />
    <AppLayout title="Scramble" hide-nav hide-header game-screen>
        <PuzzleExitModal
            :open="exitOpen"
            title="Leave this round?"
            @close="exitOpen = false"
            @confirm="confirmExit"
        />
        <div v-if="!puzzle" class="puzzle-screen puzzle-screen-idle">
            <button type="button" class="puzzle-close puzzle-exit-floating" aria-label="Back to modes" @click="exitOpen = true">✕</button>
            <div class="puzzle-screen-body">
                <div class="puzzle-game-idle-art" aria-hidden="true">
                    <span>S</span><span>C</span><span>R</span><span>A</span><span>M</span>
                </div>
                <h2 class="puzzle-game-idle-title">Scramble</h2>
                <p class="puzzle-game-idle-sub">Tap letters to build the word. Beat the clock.</p>
                <button type="button" class="btn puzzle-btn-play" @click="next">▶ Play</button>
            </div>
        </div>

        <template v-else>
            <GameRecordCelebrate :celebrate-record="celebrateRecord" />
            <div :class="screenClass">
                <div class="puzzle-topbar puzzle-topbar-score">
                    <button type="button" class="puzzle-close" aria-label="Back to modes" @click="exitOpen = true">✕</button>
                    <div class="puzzle-timer-pill">
                        <span class="puzzle-timer-label">TIME</span>
                        <span class="puzzle-timer-value">{{ answered ? formatTime(elapsedSeconds) : timerText }}</span>
                    </div>
                    <div class="game-score-pill" title="Correct this run / personal best">
                        <span class="game-score-current">✓ {{ sessionCorrect }}</span>
                        <span class="game-score-sep">·</span>
                        <span class="game-score-best">Best {{ bestCorrect }}</span>
                    </div>
                </div>

                <div class="puzzle-screen-scroll">
                    <div v-if="!answered" class="scramble-build" :data-word-length="wordLength">
                        <p class="scramble-build-label">Your word</p>
                        <div class="scramble-answer-board" aria-label="Build your answer">
                            <button
                                v-for="(slot, col) in placement"
                                :key="col"
                                type="button"
                                class="scramble-slot"
                                :class="{ 'is-filled': slot !== null }"
                                :aria-label="`Letter slot ${col + 1}`"
                                @click="clearSlot(col)"
                            >
                                {{ slot !== null ? String(letters[slot] || '').toUpperCase() : '' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="!answered" class="scramble-hint-zone">
                        <div v-if="!hintReady" class="puzzle-hint-countdown" aria-live="polite">
                            <span class="puzzle-hint-countdown-label">Hint in</span>
                            <span class="puzzle-hint-countdown-value">{{ countdown }}</span>
                            <span class="puzzle-hint-countdown-unit">s</span>
                        </div>
                        <div v-show="hintReady" class="puzzle-hint-card scramble-hint-card">
                            <div class="puzzle-hint-label">Hint</div>
                            <p v-if="hintPos" class="puzzle-hint-pos">{{ hintPos }}</p>
                            <p class="puzzle-hint-text">{{ hintDefinition }}</p>
                        </div>
                    </div>

                    <template v-if="answered">
                        <div class="puzzle-result" :class="wasCorrect ? 'is-win' : 'is-lose'">
                            <div class="puzzle-result-banner">
                                <span class="puzzle-result-title">{{ wasCorrect ? 'Nice!' : 'Not quite' }}</span>
                                <span class="puzzle-result-time">✓ {{ sessionCorrect }} · {{ formatTime(elapsedSeconds) }}</span>
                            </div>
                            <p class="puzzle-result-msg">{{ feedback }}</p>
                        </div>

                        <div v-if="reveal" class="card puzzle-reveal-card">
                            <div class="vocab-detail-header">
                                <div class="vocab-detail-title">
                                    <p class="card-title" style="margin:0">{{ reveal.word }}</p>
                                    <p v-if="reveal.phonetic" class="card-subtitle" style="font-style:italic;margin-top:6px">
                                        {{ reveal.phonetic }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="puzzle-screen-footer scramble-footer">
                    <template v-if="!answered">
                        <div class="scramble-keyboard wordle-keyboard-compact" aria-label="Letter bank">
                            <p class="wordle-keyboard-hint">Tap letters to fill the word above</p>
                            <div class="scramble-keyboard-letters">
                                <button
                                    v-for="(letter, index) in letters"
                                    :key="index"
                                    type="button"
                                    class="wordle-key scramble-key"
                                    :class="{ 'is-hidden': placement.includes(index) }"
                                    :disabled="placement.includes(index)"
                                    @click="placeKey(index)"
                                >
                                    {{ String(letter).toUpperCase() }}
                                </button>
                            </div>
                            <div class="scramble-keyboard-actions">
                                <button type="button" class="wordle-key is-wide scramble-clear-btn" @click="clearAll">Clear</button>
                                <button
                                    type="button"
                                    class="wordle-key is-wide scramble-submit-btn"
                                    :disabled="!complete"
                                    @click="submit"
                                >
                                    Submit
                                </button>
                            </div>
                        </div>
                    </template>
                    <button v-else type="button" class="btn btn-block puzzle-btn-play" @click="next">Next round →</button>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
