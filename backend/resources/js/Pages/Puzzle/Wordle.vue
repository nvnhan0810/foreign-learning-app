<script setup>
import { appPath } from '@/path';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GameRecordCelebrate from '@/Components/GameRecordCelebrate.vue';
import PuzzleExitModal from '@/Components/PuzzleExitModal.vue';

const HINT_VISIBLE_MS = 10_000;
const HINT_COOLDOWN_MS = 20_000;

const props = defineProps({
    puzzle: { type: Object, default: null },
    guesses: { type: Array, default: () => [] },
    hint: { type: Object, default: null },
    hintAt: { type: Number, default: null },
    feedback: { type: String, default: null },
    wasCorrect: { type: Boolean, default: null },
    reveal: { type: Object, default: null },
    startedAt: { type: Number, default: null },
    elapsedSeconds: { type: Number, default: null },
    sessionCorrect: { type: Number, default: 0 },
    bestCorrect: { type: Number, default: 0 },
    celebrateRecord: { type: [Number, String], default: null },
});

const answered = computed(() => props.feedback != null);
const wordLength = computed(() => Number(props.puzzle?.word_length || 5));
const maxGuesses = computed(() => Number(props.puzzle?.max_guesses || 6));
const keyboardLetters = computed(() => props.puzzle?.keyboard_letters || {});
const letterKeys = computed(() => Object.keys(keyboardLetters.value).sort());
const draft = ref('');
const timerText = ref('00:00');
const nowMs = ref(Date.now());
const hintLeaving = ref(false);
let clockTimer = null;
let helpTimer = null;
let leaveTimer = null;

const elapsedSinceHintMs = computed(() => {
    if (!props.hintAt) return null;
    return Math.max(0, nowMs.value - Number(props.hintAt) * 1000);
});

const hintCardVisible = computed(() => {
    if (answered.value || !props.hint || elapsedSinceHintMs.value === null) return false;
    return elapsedSinceHintMs.value < HINT_VISIBLE_MS;
});

const canHelp = computed(() => {
    if (answered.value) return false;
    if (props.hintAt == null) return true;
    return (elapsedSinceHintMs.value ?? 0) >= HINT_COOLDOWN_MS;
});

const keyStates = computed(() => {
    const states = {};
    const rank = { absent: 1, present: 2, correct: 3 };
    for (const row of props.guesses || []) {
        for (const tile of row.tiles || []) {
            const letter = String(tile.letter || '').toLowerCase();
            const state = String(tile.state || '');
            if (!letter || !rank[state]) continue;
            if (!states[letter] || rank[state] > rank[states[letter]]) {
                states[letter] = state;
            }
        }
    }
    return states;
});

const keyUsage = computed(() => {
    const usage = {};
    for (const letter of draft.value) {
        usage[letter] = (usage[letter] || 0) + 1;
    }
    return usage;
});

function formatTime(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

function tileFor(row, col) {
    const filled = props.guesses?.[row];
    if (filled?.tiles?.[col]) return filled.tiles[col];
    if (!answered.value && row === (props.guesses?.length || 0)) {
        const letter = draft.value[col] || '';
        return letter ? { letter, state: '' } : null;
    }
    return null;
}

function press(key) {
    if (answered.value) return;
    if (key === 'enter') {
        if (draft.value.length !== wordLength.value) return;
        useForm({ guess: draft.value }).post(appPath('/home/puzzle/wordle/guess'), {
            onFinish: () => {
                draft.value = '';
            },
        });
        return;
    }
    if (key === 'backspace') {
        draft.value = draft.value.slice(0, -1);
        return;
    }
    if (!/^[a-z]$/.test(key)) return;
    if (draft.value.length >= wordLength.value) return;
    const maxUses = Number(keyboardLetters.value[key] || 0);
    if (maxUses > 0 && (keyUsage.value[key] || 0) >= maxUses) return;
    draft.value += key;
}

function keyDisabled(letter) {
    const maxUses = Number(keyboardLetters.value[letter] || 0);
    return maxUses > 0 && (keyUsage.value[letter] || 0) >= maxUses;
}

function next() {
    useForm({}).post(appPath('/home/puzzle/wordle/next'));
}

function askHint() {
    if (!canHelp.value) return;
    useForm({}).post(appPath('/home/puzzle/wordle/hint'));
}

const exitOpen = ref(false);

function confirmExit() {
    exitOpen.value = false;
    router.visit(appPath('/home/puzzle'));
}

function onKeydown(event) {
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    const target = event.target;
    if (target instanceof HTMLElement) {
        const tag = target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable) return;
    }
    if (event.key === 'Enter') return press('enter');
    if (event.key === 'Backspace') return press('backspace');
    press(String(event.key || '').toLowerCase());
}

function startHelpTicker() {
    if (helpTimer) window.clearInterval(helpTimer);
    nowMs.value = Date.now();
    helpTimer = window.setInterval(() => {
        nowMs.value = Date.now();
        if (props.hintAt == null || (Date.now() - Number(props.hintAt) * 1000) >= HINT_COOLDOWN_MS) {
            if (helpTimer) window.clearInterval(helpTimer);
            helpTimer = null;
        }
    }, 250);
}

watch(hintCardVisible, (visible, wasVisible) => {
    if (wasVisible && !visible) {
        hintLeaving.value = true;
        if (leaveTimer) window.clearTimeout(leaveTimer);
        leaveTimer = window.setTimeout(() => {
            hintLeaving.value = false;
        }, 280);
    }
    if (visible) hintLeaving.value = false;
});

watch(
    () => [props.hintAt, props.puzzle?.vocabulary_id],
    () => startHelpTicker(),
);

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    startHelpTicker();
    if (props.startedAt) {
        const tick = () => {
            timerText.value = formatTime(Math.floor(Date.now() / 1000) - props.startedAt);
        };
        tick();
        clockTimer = window.setInterval(tick, 1000);
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    if (clockTimer) window.clearInterval(clockTimer);
    if (helpTimer) window.clearInterval(helpTimer);
    if (leaveTimer) window.clearTimeout(leaveTimer);
});

const screenClass = computed(() => ({
    'puzzle-screen': true,
    'wordle-screen': true,
    'is-resolved': answered.value,
    'is-playing': !answered.value,
    'is-win': props.wasCorrect === true,
    'is-lose': props.wasCorrect === false,
}));
</script>

<template>
    <Head title="Wordle" />
    <AppLayout title="Wordle" hide-nav hide-header game-screen>
        <PuzzleExitModal
            :open="exitOpen"
            title="Leave this round?"
            @close="exitOpen = false"
            @confirm="confirmExit"
        />
        <div v-if="!puzzle" class="puzzle-screen puzzle-screen-idle">
            <button type="button" class="puzzle-close puzzle-exit-floating" aria-label="Back to modes" @click="exitOpen = true">✕</button>
            <div class="puzzle-screen-body">
                <div class="puzzle-game-idle-art wordle-idle-art" aria-hidden="true">
                    <span>W</span><span>O</span><span>R</span><span>D</span><span>!</span>
                </div>
                <h2 class="puzzle-game-idle-title">Wordle</h2>
                <p class="puzzle-game-idle-sub">Use the letter bank — green = right spot, gold = wrong spot.</p>
                <button type="button" class="btn puzzle-btn-play" @click="next">▶ Play</button>
            </div>
        </div>

        <template v-else>
            <GameRecordCelebrate :celebrate-record="celebrateRecord" />
            <div :class="screenClass">
                <div class="puzzle-topbar puzzle-topbar-score wordle-topbar">
                    <button type="button" class="puzzle-close" aria-label="Back to modes" @click="exitOpen = true">✕</button>
                    <div class="puzzle-timer-pill">
                        <span class="puzzle-timer-label">TIME</span>
                        <span class="puzzle-timer-value">{{ answered ? formatTime(elapsedSeconds) : timerText }}</span>
                    </div>
                    <div class="game-score-pill" title="Wins this run / personal best">
                        <span class="game-score-current">✓ {{ sessionCorrect }}</span>
                        <span class="game-score-sep">·</span>
                        <span class="game-score-best">Best {{ bestCorrect }}</span>
                    </div>
                </div>

                <div class="puzzle-screen-scroll">
                    <div class="wordle-board" aria-label="Wordle board">
                        <div
                            v-for="row in maxGuesses"
                            :key="row"
                            class="wordle-row"
                            :class="{ 'is-active': !answered && row - 1 === (guesses?.length || 0) }"
                        >
                            <div
                                v-for="col in wordLength"
                                :key="col"
                                class="wordle-tile"
                                :class="{
                                    'is-revealed': !!tileFor(row - 1, col - 1)?.state,
                                    [`is-${tileFor(row - 1, col - 1)?.state || ''}`]: !!tileFor(row - 1, col - 1)?.state,
                                    'is-editable': !answered && row - 1 === (guesses?.length || 0),
                                }"
                            >
                                {{ String(tileFor(row - 1, col - 1)?.letter || '').toUpperCase() }}
                            </div>
                        </div>
                    </div>

                    <div v-if="!answered" class="wordle-help-zone">
                        <button
                            type="button"
                            class="wordle-help-btn"
                            :class="{ 'is-cooldown': !canHelp }"
                            :disabled="!canHelp"
                            :aria-label="canHelp ? 'Show meaning' : 'Help on cooldown'"
                            @click="askHint"
                        >
                            <span aria-hidden="true">?</span>
                        </button>
                    </div>

                    <div
                        v-if="!answered && hint && (hintCardVisible || hintLeaving)"
                        class="puzzle-hint-card wordle-hint-card"
                        :class="{ 'is-leaving': hintLeaving && !hintCardVisible }"
                    >
                        <div class="puzzle-hint-label">Meaning</div>
                        <p v-if="hint.part_of_speech" class="puzzle-hint-pos">{{ hint.part_of_speech }}</p>
                        <p class="puzzle-hint-text">{{ hint.definition }}</p>
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
                            <p class="card-title" style="margin:0">{{ reveal.word }}</p>
                            <p v-if="reveal.phonetic" class="card-subtitle" style="font-style:italic;margin-top:6px">{{ reveal.phonetic }}</p>
                        </div>
                    </template>
                </div>

                <div class="puzzle-screen-footer wordle-footer">
                    <template v-if="!answered">
                        <div class="wordle-keyboard wordle-keyboard-compact" aria-label="Letter bank">
                            <p class="wordle-keyboard-hint">Pick from these letters</p>
                            <div class="wordle-keyboard-letters">
                                <button
                                    v-for="key in letterKeys"
                                    :key="key"
                                    type="button"
                                    class="wordle-key"
                                    :class="{
                                        [`is-${keyStates[key] || ''}`]: !!keyStates[key],
                                        'is-exhausted': keyDisabled(key),
                                    }"
                                    :disabled="keyDisabled(key)"
                                    @click="press(key)"
                                >
                                    <span class="wordle-key-label">{{ key.toUpperCase() }}</span>
                                    <span v-if="Number(keyboardLetters[key] || 0) > 1" class="wordle-key-count">
                                        {{ Number(keyboardLetters[key] || 0) - (keyUsage[key] || 0) }}
                                    </span>
                                </button>
                            </div>
                            <div class="wordle-keyboard-actions">
                                <button type="button" class="wordle-key is-wide" @click="press('enter')">Enter</button>
                                <button type="button" class="wordle-key is-wide" @click="press('backspace')">⌫</button>
                            </div>
                        </div>
                    </template>
                    <button v-else type="button" class="btn btn-block puzzle-btn-play" @click="next">Next round →</button>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
