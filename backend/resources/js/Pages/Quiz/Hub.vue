<script setup>
import { appPath } from '@/path';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <Head title="Games" />
    <AppLayout title="Games" heading="Games" game-bg>
        <div class="puzzle-hub-body">
            <p class="puzzle-hub-kicker">Play &amp; learn</p>

            <div class="puzzle-mode-grid">
                <Link :href="appPath('/home/quiz/play?autostart=1')" class="puzzle-mode-card puzzle-mode-live">
                    <span class="puzzle-mode-thumb">
                        <img :src="appPath('/images/puzzle/quiz.svg')" alt="Quiz" loading="lazy">
                        <span class="puzzle-mode-tag puzzle-mode-tag-play">Play</span>
                    </span>
                    <span class="puzzle-mode-info">
                        <span class="puzzle-mode-title">Quiz</span>
                        <span class="puzzle-mode-desc">Multiple-choice review of saved words</span>
                    </span>
                </Link>

                <Link :href="appPath('/home/puzzle')" class="puzzle-mode-card puzzle-mode-live">
                    <span class="puzzle-mode-thumb">
                        <img :src="appPath('/images/puzzle/puzzle.svg')" alt="Word Puzzle" loading="lazy">
                        <span class="puzzle-mode-tag puzzle-mode-tag-play">Play</span>
                    </span>
                    <span class="puzzle-mode-info">
                        <span class="puzzle-mode-title">Word Puzzle</span>
                        <span class="puzzle-mode-desc">Scramble &amp; more letter games</span>
                    </span>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
