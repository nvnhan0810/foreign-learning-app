<script setup>
import { appPath } from '@/path';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GameRecordCelebrate from '@/Components/GameRecordCelebrate.vue';
import PuzzleExitModal from '@/Components/PuzzleExitModal.vue';

const HINT_VISIBLE_MS = 10_000;
const HINT_COOLDOWN_MS = 10_000;

const props = defineProps({
    puzzle: { type: Object, default: null },
    foundIds: { type: Array, default: () => [] },
    foundCells: { type: Object, default: () => ({}) },
    hintCell: { type: Object, default: null },
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
const grid = computed(() => (Array.isArray(props.puzzle?.grid) ? props.puzzle.grid : []));
const words = computed(() => (Array.isArray(props.puzzle?.words) ? props.puzzle.words : []));
const gridSize = computed(() => Number(props.puzzle?.grid_size || grid.value.length || 8));
const foundSet = computed(() => new Set((props.foundIds || []).map((id) => Number(id))));

const timerText = ref('00:00');
const selecting = ref(false);
const draftPath = ref([]);
const exitOpen = ref(false);
const nowMs = ref(Date.now());
const gridRef = ref(null);
let clockTimer = null;
let helpTimer = null;
let gridMetrics = null;
let moveFrame = null;
let pendingMove = null;

const elapsedSinceHintMs = computed(() => {
    if (!props.hintAt) return null;
    return Math.max(0, nowMs.value - Number(props.hintAt) * 1000);
});

const hintCellVisible = computed(() => {
    if (answered.value || !props.hintCell || elapsedSinceHintMs.value === null) return false;
    return elapsedSinceHintMs.value < HINT_VISIBLE_MS;
});

const canHelp = computed(() => {
    if (answered.value) return false;
    if (props.hintAt == null) return true;
    return (elapsedSinceHintMs.value ?? 0) >= HINT_COOLDOWN_MS;
});

const hintCellKey = computed(() => {
    if (!hintCellVisible.value || !props.hintCell) return null;
    return `${props.hintCell.r},${props.hintCell.c}`;
});

const foundCellKeys = computed(() => {
    const keys = new Set();
    const map = props.foundCells || {};
    for (const cells of Object.values(map)) {
        if (!Array.isArray(cells)) continue;
        for (const cell of cells) {
            keys.add(`${cell.r},${cell.c}`);
        }
    }
    return keys;
});

const draftIndexMap = computed(() => {
    const map = new Map();
    draftPath.value.forEach((cell, index) => {
        map.set(`${cell.r},${cell.c}`, index);
    });
    return map;
});

const draftWord = computed(() => {
    if (!draftPath.value.length || !grid.value.length) return '';
    return draftPath.value
        .map((cell) => String(grid.value[cell.r]?.[cell.c] || ''))
        .join('')
        .toUpperCase();
});

function formatTime(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

function cellClass(r, c) {
    const key = `${r},${c}`;
    const selectIndex = draftIndexMap.value.get(key);
    const selecting = selectIndex !== undefined;
    return {
        'is-found': foundCellKeys.value.has(key),
        'is-hint': hintCellKey.value === key,
        'is-selecting': selecting,
        'is-select-start': selecting && selectIndex === 0,
        'is-select-end': selecting && selectIndex === draftPath.value.length - 1,
        'is-select-mid': selecting && selectIndex > 0 && selectIndex < draftPath.value.length - 1,
    };
}

function cellStyle(r, c) {
    const selectIndex = draftIndexMap.value.get(`${r},${c}`);
    if (selectIndex === undefined) return undefined;
    return {
        '--ws-i': String(selectIndex),
        '--ws-n': String(Math.max(1, draftPath.value.length - 1)),
    };
}

function isFoundWord(word) {
    return foundSet.value.has(Number(word.vocabulary_id));
}

function next() {
    useForm({}).post(appPath('/home/puzzle/word-search/next'));
}

function askHint() {
    if (!canHelp.value) return;
    useForm({}).post(appPath('/home/puzzle/word-search/hint'));
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

watch(
    () => [props.hintAt, props.puzzle?.grid_size],
    () => startHelpTicker(),
);

function confirmExit() {
    exitOpen.value = false;
    router.visit(appPath('/home/puzzle'));
}

function measureGrid() {
    const gridEl = gridRef.value;
    if (!(gridEl instanceof HTMLElement)) return null;

    const rows = gridEl.querySelectorAll('.word-search-row');
    if (!rows.length) return null;

    const firstRow = rows[0];
    if (!(firstRow instanceof HTMLElement)) return null;

    const firstCell = firstRow.querySelector('[data-ws-c]');
    if (!(firstCell instanceof HTMLElement)) return null;

    const gridRect = gridEl.getBoundingClientRect();
    const cellRect = firstCell.getBoundingClientRect();
    const rowRect = firstRow.getBoundingClientRect();
    const gap = parseFloat(getComputedStyle(gridEl).gap || '4') || 4;
    const size = gridSize.value;

    return {
        left: gridRect.left,
        top: gridRect.top,
        stepX: cellRect.width + gap,
        stepY: rowRect.height + gap,
        rows: size,
        cols: size,
    };
}

function cellFromPoint(clientX, clientY) {
    const metrics = gridMetrics;
    if (metrics) {
        const c = Math.floor((clientX - metrics.left) / metrics.stepX);
        const r = Math.floor((clientY - metrics.top) / metrics.stepY);
        if (r >= 0 && r < metrics.rows && c >= 0 && c < metrics.cols) {
            return { r, c };
        }

        return null;
    }

    const gridEl = gridRef.value;
    if (gridEl instanceof HTMLElement) {
        const rows = gridEl.querySelectorAll('.word-search-row');
        for (let r = 0; r < rows.length; r++) {
            const rowEl = rows[r];
            if (!(rowEl instanceof HTMLElement)) continue;
            const rowRect = rowEl.getBoundingClientRect();
            if (clientY < rowRect.top || clientY > rowRect.bottom) continue;

            const cells = rowEl.querySelectorAll('[data-ws-r][data-ws-c]');
            for (const cellEl of cells) {
                if (!(cellEl instanceof HTMLElement)) continue;
                const cellRect = cellEl.getBoundingClientRect();
                if (
                    clientX >= cellRect.left
                    && clientX <= cellRect.right
                    && clientY >= cellRect.top
                    && clientY <= cellRect.bottom
                ) {
                    const row = Number(cellEl.dataset.wsR);
                    const col = Number(cellEl.dataset.wsC);
                    if (Number.isInteger(row) && Number.isInteger(col)) {
                        return { r: row, c: col };
                    }
                }
            }
        }
    }

    return null;
}

function sameCell(a, b) {
    return a && b && a.r === b.r && a.c === b.c;
}

function appendCell(cell) {
    if (!cell || answered.value) return;
    const path = draftPath.value;
    if (path.length === 0) {
        draftPath.value = [cell];
        return;
    }
    const last = path[path.length - 1];
    if (sameCell(last, cell)) return;
    if (path.some((item) => sameCell(item, cell))) return;

    if (path.length === 1) {
        const dr = cell.r - last.r;
        const dc = cell.c - last.c;
        if (Math.abs(dr) > 1 || Math.abs(dc) > 1 || (dr === 0 && dc === 0)) return;
        draftPath.value = [...path, cell];
        return;
    }

    const stepR = path[1].r - path[0].r;
    const stepC = path[1].c - path[0].c;
    if (cell.r - last.r !== stepR || cell.c - last.c !== stepC) return;
    draftPath.value = [...path, cell];
}

function releasePointerCapture(event) {
    const gridEl = gridRef.value;
    if (!(gridEl instanceof HTMLElement)) return;
    try {
        if (gridEl.hasPointerCapture(event.pointerId)) {
            gridEl.releasePointerCapture(event.pointerId);
        }
    } catch {
        // WebView may reject release after the pointer is already gone.
    }
}

function scheduleMove(clientX, clientY) {
    pendingMove = { clientX, clientY };
    if (moveFrame) return;
    moveFrame = requestAnimationFrame(() => {
        moveFrame = null;
        if (!pendingMove || !selecting.value) return;
        appendCell(cellFromPoint(pendingMove.clientX, pendingMove.clientY));
        pendingMove = null;
    });
}

function onGridPointerDown(event) {
    if (answered.value || !props.puzzle || selecting.value) return;
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    const gridEl = gridRef.value;
    if (!(gridEl instanceof HTMLElement)) return;

    event.preventDefault();
    try {
        gridEl.setPointerCapture(event.pointerId);
    } catch {
        // Some WebViews reject capture; pointer events on the grid still work.
    }

    gridMetrics = measureGrid();
    selecting.value = true;
    draftPath.value = [];
    appendCell(cellFromPoint(event.clientX, event.clientY));
}

function onGridPointerMove(event) {
    if (!selecting.value) return;
    if (event.pointerType === 'mouse' && event.buttons === 0) {
        endSelect(event);
        return;
    }
    event.preventDefault();
    scheduleMove(event.clientX, event.clientY);
}

function cancelSelect(event) {
    if (!selecting.value) return;
    if (event) releasePointerCapture(event);
    selecting.value = false;
    draftPath.value = [];
    gridMetrics = null;
    if (moveFrame) {
        cancelAnimationFrame(moveFrame);
        moveFrame = null;
    }
    pendingMove = null;
}

function endSelect(event) {
    if (!selecting.value) return;
    if (event) releasePointerCapture(event);
    selecting.value = false;
    gridMetrics = null;
    if (moveFrame) {
        cancelAnimationFrame(moveFrame);
        moveFrame = null;
    }
    pendingMove = null;
    const path = draftPath.value;
    draftPath.value = [];
    if (path.length < 3 || answered.value) return;
    useForm({ cells: path }).post(appPath('/home/puzzle/word-search/find'), {
        preserveScroll: true,
    });
}

function onGridPointerUp(event) {
    if (!selecting.value) return;
    endSelect(event);
}

function onGridPointerCancel(event) {
    if (!selecting.value) return;
    cancelSelect(event);
}

function onGridLostPointerCapture() {
    if (selecting.value) {
        endSelect();
    }
}

onMounted(() => {
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
    if (selecting.value) {
        selecting.value = false;
        draftPath.value = [];
    }
    if (moveFrame) cancelAnimationFrame(moveFrame);
    if (clockTimer) window.clearInterval(clockTimer);
    if (helpTimer) window.clearInterval(helpTimer);
});

const screenClass = computed(() => ({
    'puzzle-screen': true,
    'word-search-screen': true,
    'is-resolved': answered.value,
    'is-playing': !answered.value && !!props.puzzle,
    'is-win': props.wasCorrect === true,
}));
</script>

<template>
    <Head title="Word Search" />
    <AppLayout title="Word Search" hide-nav hide-header game-screen>
        <PuzzleExitModal
            :open="exitOpen"
            title="Leave this round?"
            @close="exitOpen = false"
            @confirm="confirmExit"
        />

        <div v-if="!puzzle" class="puzzle-screen puzzle-screen-idle">
            <button type="button" class="puzzle-close puzzle-exit-floating" aria-label="Back to modes" @click="exitOpen = true">✕</button>
            <div class="puzzle-screen-body">
                <div class="puzzle-game-idle-art word-search-idle-art" aria-hidden="true">
                    <span>F</span><span>I</span><span>N</span><span>D</span><span>!</span>
                </div>
                <h2 class="puzzle-game-idle-title">Word Search</h2>
                <p class="puzzle-game-idle-sub">Read the meanings, then drag letters to find each word in the grid.</p>
                <button type="button" class="btn puzzle-btn-play" @click="next">▶ Play</button>
            </div>
        </div>

        <template v-else>
            <GameRecordCelebrate :celebrate-record="celebrateRecord" />
            <div :class="screenClass">
                <div class="puzzle-topbar puzzle-topbar-score word-search-topbar">
                    <button type="button" class="puzzle-close" aria-label="Back to modes" @click="exitOpen = true">✕</button>
                    <div class="puzzle-timer-pill">
                        <span class="puzzle-timer-label">TIME</span>
                        <span class="puzzle-timer-value">{{ answered ? formatTime(elapsedSeconds) : timerText }}</span>
                    </div>
                    <div class="game-score-pill" title="Grids cleared this run / personal best">
                        <span class="game-score-current">✓ {{ sessionCorrect }}</span>
                        <span class="game-score-sep">·</span>
                        <span class="game-score-best">Best {{ bestCorrect }}</span>
                    </div>
                </div>

                <div class="puzzle-screen-scroll">
                    <div class="word-search-sticky">
                        <div
                            ref="gridRef"
                            class="word-search-grid"
                            :class="{ 'is-dragging': selecting }"
                            :style="{ '--ws-size': gridSize }"
                            @pointerdown="onGridPointerDown"
                            @pointermove="onGridPointerMove"
                            @pointerup="onGridPointerUp"
                            @pointercancel="onGridPointerCancel"
                            @lostpointercapture="onGridLostPointerCapture"
                        >
                            <div
                                v-for="(row, r) in grid"
                                :key="`row-${r}`"
                                class="word-search-row"
                            >
                                <div
                                    v-for="(letter, c) in row"
                                    :key="`cell-${r}-${c}`"
                                    class="word-search-cell"
                                    :class="cellClass(r, c)"
                                    :style="cellStyle(r, c)"
                                    :data-ws-r="r"
                                    :data-ws-c="c"
                                    aria-hidden="true"
                                >
                                    {{ String(letter || '').toUpperCase() }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="draftWord"
                            class="word-search-draft"
                            aria-live="polite"
                        >
                            <span class="word-search-draft-label">Selecting</span>
                            <span class="word-search-draft-word">{{ draftWord }}</span>
                        </div>

                        <div v-if="!answered" class="word-search-help-zone">
                            <button
                                type="button"
                                class="wordle-help-btn"
                                :class="{ 'is-cooldown': !canHelp }"
                                :disabled="!canHelp"
                                :aria-label="canHelp ? 'Show letter hint' : 'Help on cooldown'"
                                @click="askHint"
                            >
                                <span aria-hidden="true">?</span>
                            </button>
                        </div>

                        <div v-if="answered" class="puzzle-result is-win word-search-result">
                            <div class="puzzle-result-banner">
                                <span class="puzzle-result-title">Cleared!</span>
                                <span class="puzzle-result-time">✓ {{ sessionCorrect }} · {{ formatTime(elapsedSeconds) }}</span>
                            </div>
                            <p class="puzzle-result-msg">{{ feedback }}</p>
                        </div>
                    </div>

                    <div class="word-search-wordlist" aria-label="Clues to find">
                        <p class="word-search-wordlist-label">
                            Find {{ foundIds.length }}/{{ words.length }}
                        </p>
                        <ul>
                            <li
                                v-for="word in words"
                                :key="word.vocabulary_id"
                                :class="{ 'is-found': isFoundWord(word) }"
                            >
                                <template v-if="isFoundWord(word) && word.word">
                                    <span class="word-search-answer">{{ word.word }}</span>
                                    <span v-if="word.part_of_speech" class="word-search-pos">{{ word.part_of_speech }}</span>
                                    <span class="word-search-def">{{ word.definition }}</span>
                                </template>
                                <template v-else>
                                    <span v-if="word.part_of_speech" class="word-search-pos">{{ word.part_of_speech }}</span>
                                    <span class="word-search-def">{{ word.definition }}</span>
                                </template>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="puzzle-screen-footer word-search-footer">
                    <button v-if="answered" type="button" class="btn btn-block puzzle-btn-play" @click="next">
                        Next round →
                    </button>
                    <p v-else class="word-search-hint">Use the meanings as clues — drag a straight line to select</p>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
