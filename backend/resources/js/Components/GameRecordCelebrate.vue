<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    celebrateRecord: { type: [Number, String], default: null },
});

let timer = null;

function runCelebration() {
    if (!props.celebrateRecord) return;
    const overlay = document.querySelector('[data-game-record-celebrate]');
    if (!overlay) return;
    overlay.hidden = false;
    overlay.classList.add('is-open');
    const burst = overlay.querySelector('.game-record-burst');
    if (burst) {
        burst.innerHTML = '';
        for (let i = 0; i < 24; i += 1) {
            const piece = document.createElement('span');
            piece.className = 'game-record-piece';
            piece.style.setProperty('--x', `${(Math.random() * 160 - 80).toFixed(1)}vw`);
            piece.style.setProperty('--r', `${(Math.random() * 720 - 360).toFixed(0)}deg`);
            piece.style.setProperty('--d', `${(0.7 + Math.random() * 0.9).toFixed(2)}s`);
            burst.appendChild(piece);
        }
    }
    timer = window.setTimeout(() => {
        overlay.classList.add('is-leaving');
        window.setTimeout(() => {
            overlay.hidden = true;
            overlay.setAttribute('hidden', '');
            overlay.classList.remove('is-open', 'is-leaving');
        }, 420);
    }, 2200);
}

onMounted(runCelebration);
watch(() => props.celebrateRecord, runCelebration);
onUnmounted(() => {
    if (timer) window.clearTimeout(timer);
});
</script>

<template>
    <div v-if="celebrateRecord" class="game-record-overlay" data-game-record-celebrate hidden>
        <div class="game-record-burst" aria-hidden="true" />
        <div class="game-record-card">
            <p class="game-record-eyebrow">New record</p>
            <p class="game-record-title">Kỷ lục mới!</p>
            <p class="game-record-score">{{ Number(celebrateRecord) }} <span>correct</span></p>
            <p class="game-record-sub">Best run this session so far.</p>
        </div>
    </div>
</template>
