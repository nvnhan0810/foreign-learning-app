<script setup>
import { appPath } from '@/path';
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PuzzleExitModal from '@/Components/PuzzleExitModal.vue';

const exitOpen = ref(false);

function confirmExit() {
    exitOpen.value = false;
    useForm({}).post(appPath('/home/puzzle/exit'));
}
</script>

<template>
    <Head title="Word Puzzle" />
    <AppLayout title="Word Puzzle" hide-nav hide-header game-screen>
        <PuzzleExitModal
            :open="exitOpen"
            title="Exit Word Puzzle?"
            @close="exitOpen = false"
            @confirm="confirmExit"
        />
        <div class="puzzle-screen puzzle-hub-screen">
            <div class="puzzle-topbar">
                <button type="button" class="puzzle-close" aria-label="Exit" @click="exitOpen = true">✕</button>
                <div class="puzzle-topbar-title">Word Puzzle</div>
                <span class="puzzle-topbar-spacer" aria-hidden="true" />
            </div>

            <div class="puzzle-screen-body puzzle-hub-body">
                <p class="puzzle-hub-kicker">Choose a mode</p>

                <div class="puzzle-mode-grid">
                    <Link :href="appPath('/home/puzzle/scramble?autostart=1')" class="puzzle-mode-card puzzle-mode-live">
                        <span class="puzzle-mode-thumb">
                            <img :src="appPath('/images/puzzle/scramble.svg')" alt="Scramble" loading="lazy">
                            <span class="puzzle-mode-tag puzzle-mode-tag-play">Play</span>
                        </span>
                        <span class="puzzle-mode-info">
                            <span class="puzzle-mode-title">Scramble</span>
                            <span class="puzzle-mode-desc">Tap letters to build the word</span>
                        </span>
                    </Link>

                    <Link :href="appPath('/home/puzzle/wordle?autostart=1')" class="puzzle-mode-card puzzle-mode-live">
                        <span class="puzzle-mode-thumb">
                            <img :src="appPath('/images/puzzle/wordle.svg')" alt="Wordle" loading="lazy">
                            <span class="puzzle-mode-tag puzzle-mode-tag-play">Play</span>
                        </span>
                        <span class="puzzle-mode-info">
                            <span class="puzzle-mode-title">Wordle</span>
                            <span class="puzzle-mode-desc">Guess with a letter bank + color feedback</span>
                        </span>
                    </Link>

                    <Link :href="appPath('/home/puzzle/hangman?autostart=1')" class="puzzle-mode-card puzzle-mode-live">
                        <span class="puzzle-mode-thumb">
                            <img :src="appPath('/images/puzzle/hangman.svg')" alt="Hangman" loading="lazy">
                            <span class="puzzle-mode-tag puzzle-mode-tag-play">Play</span>
                        </span>
                        <span class="puzzle-mode-info">
                            <span class="puzzle-mode-title">Hangman</span>
                            <span class="puzzle-mode-desc">Guess letters from a clue</span>
                        </span>
                    </Link>

                    <Link :href="appPath('/home/puzzle/word-search?autostart=1')" class="puzzle-mode-card puzzle-mode-live">
                        <span class="puzzle-mode-thumb">
                            <img :src="appPath('/images/puzzle/word-search.svg')" alt="Word Search" loading="lazy">
                            <span class="puzzle-mode-tag puzzle-mode-tag-play">Play</span>
                        </span>
                        <span class="puzzle-mode-info">
                            <span class="puzzle-mode-title">Word Search</span>
                            <span class="puzzle-mode-desc">Find words from meanings in a grid</span>
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
