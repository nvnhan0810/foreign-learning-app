<script setup>
import { appPath } from '@/path';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GameRecordCelebrate from '@/Components/GameRecordCelebrate.vue';
import PuzzleExitModal from '@/Components/PuzzleExitModal.vue';

const props = defineProps({
    puzzle: { type: Object, default: null },
    guessedLetters: { type: Array, default: () => [] },
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
const mask = computed(() => (Array.isArray(props.puzzle?.mask) ? props.puzzle.mask : []));
const maxWrong = computed(() => Number(props.puzzle?.max_wrong || 6));
const wrongCount = computed(() => Number(props.puzzle?.wrong_count || 0));
const guessed = computed(() => new Set((props.guessedLetters || []).map((l) => String(l).toLowerCase())));
const revealed = computed(() => new Set(mask.value.filter(Boolean).map((l) => String(l).toLowerCase())));
const timerText = ref('00:00');
let clockTimer = null;

const rows = [
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
    ['z', 'x', 'c', 'v', 'b', 'n', 'm'],
];

function formatTime(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

function keyClass(letter) {
    if (!guessed.value.has(letter)) return '';
    return revealed.value.has(letter) ? 'is-hit' : 'is-miss';
}

function guess(letter) {
    if (answered.value || guessed.value.has(letter)) return;
    useForm({ letter }).post(appPath('/home/puzzle/hangman/guess'));
}

function next() {
    useForm({}).post(appPath('/home/puzzle/hangman/next'));
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
    const letter = String(event.key || '').toLowerCase();
    if (/^[a-z]$/.test(letter)) guess(letter);
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
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
});

const screenClass = computed(() => ({
    'puzzle-screen': true,
    'hangman-screen': true,
    'is-resolved': answered.value,
    'is-playing': !answered.value,
    'is-win': props.wasCorrect === true,
    'is-lose': props.wasCorrect === false,
}));
</script>

<template>
    <Head title="Hangman" />
    <AppLayout title="Hangman" hide-nav hide-header game-screen>
        <PuzzleExitModal
            :open="exitOpen"
            title="Leave this round?"
            @close="exitOpen = false"
            @confirm="confirmExit"
        />
        <div v-if="!puzzle" class="puzzle-screen puzzle-screen-idle">
            <button type="button" class="puzzle-close puzzle-exit-floating" aria-label="Back to modes" @click="exitOpen = true">✕</button>
            <div class="puzzle-screen-body">
                <div class="puzzle-game-idle-art hangman-idle-art" aria-hidden="true">
                    <span>H</span><span>A</span><span>N</span><span>G</span><span>?</span>
                </div>
                <h2 class="puzzle-game-idle-title">Hangman</h2>
                <p class="puzzle-game-idle-sub">Read the clue, then guess letters. Six wrong guesses and you’re out.</p>
                <button type="button" class="btn puzzle-btn-play" @click="next">▶ Play</button>
            </div>
        </div>

        <template v-else>
            <GameRecordCelebrate :celebrate-record="celebrateRecord" />
            <div :class="screenClass">
                <div class="puzzle-topbar puzzle-topbar-score hangman-topbar">
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
                    <div class="hangman-figure" :data-hangman-wrong="wrongCount" :aria-label="`${wrongCount} of ${maxWrong} wrong guesses`">
                        <svg class="hangman-svg" viewBox="0 0 120 160" role="img" aria-hidden="true">
                            <line class="hangman-part is-base" x1="10" y1="150" x2="90" y2="150" />
                            <line class="hangman-part is-pole" x1="30" y1="150" x2="30" y2="20" />
                            <line class="hangman-part is-beam" x1="30" y1="20" x2="85" y2="20" />
                            <line class="hangman-part is-rope" :class="{ 'is-on': wrongCount >= 1 }" x1="85" y1="20" x2="85" y2="40" />
                            <circle class="hangman-part is-head" :class="{ 'is-on': wrongCount >= 2 }" cx="85" cy="52" r="12" />
                            <line class="hangman-part is-body" :class="{ 'is-on': wrongCount >= 3 }" x1="85" y1="64" x2="85" y2="100" />
                            <line class="hangman-part is-arm-l" :class="{ 'is-on': wrongCount >= 4 }" x1="85" y1="74" x2="68" y2="92" />
                            <line class="hangman-part is-arm-r" :class="{ 'is-on': wrongCount >= 5 }" x1="85" y1="74" x2="102" y2="92" />
                            <line class="hangman-part is-leg-l" :class="{ 'is-on': wrongCount >= 6 }" x1="85" y1="100" x2="70" y2="124" />
                            <line class="hangman-part is-leg-r" :class="{ 'is-on': wrongCount >= 6 }" x1="85" y1="100" x2="100" y2="124" />
                        </svg>
                        <p class="hangman-lives">{{ Math.max(0, maxWrong - wrongCount) }} left</p>
                    </div>

                    <div class="hangman-clue-card" aria-label="Clue">
                        <div class="puzzle-hint-label">Clue</div>
                        <p v-if="puzzle.clue_part_of_speech" class="puzzle-hint-pos">{{ puzzle.clue_part_of_speech }}</p>
                        <p class="puzzle-hint-text">{{ puzzle.clue_definition }}</p>
                    </div>

                    <div class="hangman-word" aria-label="Hidden word">
                        <span
                            v-for="(slot, i) in mask"
                            :key="i"
                            class="hangman-slot"
                            :class="{ 'is-revealed': slot !== null }"
                        >
                            {{ slot ? String(slot).toUpperCase() : '' }}
                        </span>
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
                        </div>
                    </template>
                </div>

                <div class="puzzle-screen-footer hangman-footer">
                    <div v-if="!answered" class="hangman-keyboard" aria-label="Letter keyboard">
                        <div v-for="(row, ri) in rows" :key="ri" class="hangman-keyboard-row">
                            <button
                                v-for="letter in row"
                                :key="letter"
                                type="button"
                                class="hangman-key"
                                :class="keyClass(letter)"
                                :disabled="guessed.has(letter)"
                                @click="guess(letter)"
                            >
                                {{ letter.toUpperCase() }}
                            </button>
                        </div>
                    </div>
                    <button v-else type="button" class="btn btn-block puzzle-btn-play" @click="next">Next round →</button>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
