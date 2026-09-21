<script setup>
import { appPath } from '@/path';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fetchDictionaryPronounceUrl, playPronunciation } from '@/lib/pronunciation';

const props = defineProps({
    items: { type: Array, default: () => [] },
});

const query = ref('');
const openMenuId = ref(null);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.items;
    return props.items.filter((item) => {
        const firstDef = item.meanings?.[0]?.definition || '';
        const hay = `${item.word || ''} ${firstDef} ${item.phonetic || ''}`.toLowerCase();
        return hay.includes(q);
    });
});

function preview(item) {
    const def = item.meanings?.[0]?.definition || '';
    return def.length > 100 ? `${def.slice(0, 100)}…` : def;
}

async function pronounce(word) {
    await playPronunciation({
        word,
        fetchAudioUrl: () => fetchDictionaryPronounceUrl(word),
    });
}

function destroyWord(item) {
    if (!window.confirm(`Remove "${item.word}" from your list?`)) return;
    useForm({}).delete(`/home/vocab/${item.id}`);
}

function onDocClick(event) {
    if (!(event.target instanceof Element) || !event.target.closest('.action-menu')) {
        openMenuId.value = null;
    }
}

onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <Head title="Vocabulary" />
    <AppLayout title="Vocabulary" heading="Vocabulary">
        <div v-if="items.length === 0" class="empty-state">
            No words yet. Look up a word and tap Save.
        </div>
        <template v-else>
            <div class="form-group" style="margin-top:0">
                <div class="input-with-clear">
                    <input
                        v-model="query"
                        type="search"
                        id="vocab-search"
                        class="form-control"
                        placeholder="Search saved words..."
                        autocomplete="off"
                        enterkeyhint="search"
                    >
                    <button
                        v-show="query"
                        type="button"
                        class="input-clear"
                        aria-label="Clear"
                        @click="query = ''"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div data-vocab-list>
                <div
                    v-for="item in filtered"
                    :key="item.id"
                    class="card vocab-card"
                >
                    <div class="vocab-card-row">
                        <Link :href="`/home/vocab/${item.id}`" class="vocab-card-link">
                            <p class="card-title">{{ item.word }}</p>
                            <p v-if="item.phonetic" class="card-subtitle vocab-card-phonetic">{{ item.phonetic }}</p>
                            <p v-if="preview(item)" class="vocab-card-preview">{{ preview(item) }}</p>
                            <span class="vocab-card-hint">View details ›</span>
                        </Link>
                        <div class="vocab-card-actions">
                            <button
                                type="button"
                                class="btn-icon"
                                title="Play pronunciation"
                                aria-label="Play pronunciation"
                                @click="pronounce(item.word)"
                            >🔊</button>
                            <div class="action-menu">
                                <button
                                    type="button"
                                    class="btn-icon action-menu-trigger"
                                    aria-label="Options"
                                    aria-haspopup="true"
                                    @click.stop="openMenuId = openMenuId === item.id ? null : item.id"
                                >⋮</button>
                                <div class="action-menu-panel" :hidden="openMenuId !== item.id">
                                    <button type="button" class="action-menu-danger" @click="destroyWord(item)">Delete</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-show="filtered.length === 0" class="empty-state vocab-search-empty">
                No matching words found.
            </div>
        </template>
    </AppLayout>
</template>
