<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

defineProps({
    googleUrl: { type: String, required: true },
});

const page = usePage();
const isFlcApp = computed(() => !!page.props.isFlcApp);
const flash = computed(() => page.props.flash || {});

function setTheme(choice) {
    localStorage.setItem('flc-theme', choice);
    const dark =
        choice === 'dark' ||
        (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#1a2332' : '#4361ee');
}

onMounted(() => {
    document.body.classList.add('user-body');
    setTheme(localStorage.getItem('flc-theme') || 'system');
});
</script>

<template>
    <Head title="Sign in" />
    <div class="user-login-page">
        <div class="user-login-card">
            <div class="user-login-icon">🌐</div>
            <h1>FLC</h1>
            <p class="subtitle">Foreign Language Companion</p>

            <div v-if="flash.error" class="alert alert-error">{{ flash.error }}</div>
            <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>

            <template v-if="isFlcApp">
                <p class="muted" style="margin-top:8px">
                    Session expired. Return to the app sign-in screen.
                </p>
                <p class="muted" style="margin-top:16px;font-size:12px">
                    Google sign-in is not available inside the in-app browser.
                </p>
            </template>
            <template v-else>
                <a :href="googleUrl" class="btn btn-block">
                    Sign in with Google
                </a>

                <div class="theme-toggle" role="group" aria-label="Choose theme" style="margin-top:24px">
                    <button type="button" @click="setTheme('light')">☀️</button>
                    <button type="button" @click="setTheme('dark')">🌙</button>
                    <button type="button" @click="setTheme('system')">💻</button>
                </div>

                <p class="muted" style="margin-top:16px;font-size:12px">
                    Your email must be on the allowlist. Contact an admin if you need access.
                </p>
            </template>
        </div>
    </div>
</template>
