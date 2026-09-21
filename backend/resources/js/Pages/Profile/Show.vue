<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    user: { type: Object, required: true },
    stats: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});

const logoutForm = useForm({});
const theme = ref(localStorage.getItem('flc-theme') || 'system');

const initial = computed(() =>
    String(props.user.name || props.user.email || 'U').charAt(0).toUpperCase(),
);

const average = computed(() =>
    props.stats.average_score_percent == null ? '—' : `${props.stats.average_score_percent}%`,
);

function setTheme(choice) {
    theme.value = choice;
    localStorage.setItem('flc-theme', choice);
    const dark =
        choice === 'dark' ||
        (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#1a2332' : '#4361ee');
}

function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const dd = String(date.getDate()).padStart(2, '0');
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    return `${dd}/${mm}/${date.getFullYear()}`;
}

function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head title="Profile" />
    <AppLayout title="Profile">
        <template #header>
            <div class="profile-header">
                <div class="profile-avatar">{{ initial }}</div>
                <h2 style="margin:0;font-size:20px">{{ user.name || 'User' }}</h2>
                <p style="margin:4px 0 0;opacity:0.8;font-size:14px">{{ user.email }}</p>
                <form style="margin-top:16px" @submit.prevent="logout">
                    <button type="submit" class="btn btn-secondary btn-sm" :disabled="logoutForm.processing">
                        Sign out
                    </button>
                </form>
            </div>
        </template>

        <div class="theme-settings">
            <h2>Appearance</h2>
            <div class="theme-toggle" role="group" aria-label="Choose theme">
                <button type="button" :class="{ active: theme === 'light' }" @click="setTheme('light')">☀️ Light</button>
                <button type="button" :class="{ active: theme === 'dark' }" @click="setTheme('dark')">🌙 Dark</button>
                <button type="button" :class="{ active: theme === 'system' }" @click="setTheme('system')">💻 System</button>
            </div>
        </div>

        <h2 style="font-size:18px;margin:0 0 16px">Stats</h2>
        <div class="stats-row">
            <div class="stat-card">
                <div class="value">{{ stats.vocabulary_count }}</div>
                <div class="label">Vocabulary</div>
            </div>
            <div class="stat-card">
                <div class="value">{{ stats.media_count }}</div>
                <div class="label">Listening</div>
            </div>
            <div class="stat-card">
                <div class="value">{{ average }}</div>
                <div class="label">Avg score</div>
            </div>
        </div>

        <h2 style="font-size:18px;margin:0 0 16px">Attempt history</h2>

        <div v-if="history.length === 0" class="empty-state" style="padding:24px">
            No attempts yet.<br>Try a vocabulary quiz or a listening exercise!
        </div>
        <div
            v-for="item in history"
            :key="item.id"
            class="card"
            style="display:flex;align-items:center;gap:12px"
        >
            <div class="list-item-icon" style="margin:0">
                {{ item.kind === 'listening' ? '🎧' : '❓' }}
            </div>
            <div style="flex:1;min-width:0">
                <p class="card-title" style="font-size:15px">{{ item.title }}</p>
                <p v-if="item.completed_at" class="card-subtitle">{{ formatDate(item.completed_at) }}</p>
            </div>
            <span class="score-badge">{{ item.score }}/{{ item.total }}</span>
        </div>
    </AppLayout>
</template>
