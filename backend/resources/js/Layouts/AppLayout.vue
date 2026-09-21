<script setup>
import { appPath } from '@/path';
import { computed, onMounted, onUnmounted, useSlots, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    title: { type: String, default: 'FLC' },
    heading: { type: String, default: null },
    hideNav: { type: Boolean, default: false },
    hideHeader: { type: Boolean, default: false },
    gameScreen: { type: Boolean, default: false },
    gameBg: { type: Boolean, default: false },
    chatPage: { type: Boolean, default: false },
    backHref: { type: String, default: null },
});

const slots = useSlots();
const page = usePage();
const flash = computed(() => page.props.flash || {});
const errors = computed(() => Object.values(page.props.errors || {}));
const isFlcApp = computed(() => !!page.props.isFlcApp);
const url = computed(() => page.url.split('?')[0]);
const hasCustomHeader = computed(() => !!slots.header);

const nav = [
    { href: appPath('/home/lookup'), label: 'Lookup', icon: '📖', match: (u) => u.startsWith(appPath('/home/lookup')) },
    { href: appPath('/home/vocab'), label: 'Vocabulary', icon: '🔖', match: (u) => u.startsWith(appPath('/home/vocab')) },
    { href: appPath('/home/media'), label: 'Listen', icon: '🎧', match: (u) => u.startsWith(appPath('/home/media')) },
    { href: appPath('/home/quiz'), label: 'Games', icon: '🎮', match: (u) => u.startsWith(appPath('/home/quiz')) || u.startsWith(appPath('/home/puzzle')) },
    { href: appPath('/home/profile'), label: 'Profile', icon: '👤', match: (u) => u.startsWith(appPath('/home/profile')) },
];

function syncBodyClass() {
    const body = document.body;
    body.classList.add('user-body');
    body.classList.toggle('flc-app', isFlcApp.value);
    body.classList.toggle('user-no-nav', props.hideNav);
    body.classList.toggle('user-no-header', props.hideHeader);
    body.classList.toggle('user-game', props.gameScreen);
    body.classList.toggle('user-game-bg', props.gameBg);
    body.classList.toggle('user-chat-page', props.chatPage);
}

function applyTheme(choice) {
    const key = 'flc-theme';
    const stored = choice || localStorage.getItem(key) || 'system';
    localStorage.setItem(key, stored);
    const dark =
        stored === 'dark' ||
        (stored === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#1a2332' : '#4361ee');
}

onMounted(() => {
    applyTheme(page.props.flcTheme || null);
    syncBodyClass();
});

watch(
    () => [props.hideNav, props.hideHeader, props.gameScreen, props.gameBg, props.chatPage, isFlcApp.value],
    syncBodyClass,
);

watch(
    () => page.props.flcTheme,
    (value) => {
        if (value) applyTheme(value);
    },
);

onUnmounted(() => {
    document.body.classList.remove(
        'user-no-nav',
        'user-no-header',
        'user-game',
        'user-game-bg',
        'user-chat-page',
    );
});
</script>

<template>
    <div class="user-shell">
        <template v-if="!hideHeader">
            <slot v-if="hasCustomHeader" name="header" />
            <header v-else class="user-header">
                <Link v-if="backHref" :href="backHref" class="user-header-back" aria-label="Back">←</Link>
                <span v-else class="user-header-spacer" aria-hidden="true" />
                <h1>{{ heading ?? title }}</h1>
                <span class="user-header-spacer" aria-hidden="true" />
            </header>
        </template>

        <slot name="below-header" />

        <main class="user-main" :class="{ 'user-main-game': gameScreen }">
            <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-error">{{ flash.error }}</div>
            <div v-if="errors.length" class="alert alert-error">
                <ul style="margin:0;padding-left:18px">
                    <li v-for="(error, i) in errors" :key="i">{{ error }}</li>
                </ul>
            </div>

            <slot />
        </main>

        <nav v-if="!hideNav" class="user-nav" aria-label="Main navigation">
            <Link
                v-for="item in nav"
                :key="item.href"
                :href="item.href"
                :class="{ active: item.match(url) }"
            >
                <span class="icon">{{ item.icon }}</span>
                {{ item.label }}
            </Link>
        </nav>
    </div>
</template>
