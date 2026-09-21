<script setup>
import { appPath } from '@/path';
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DictionaryEntry from '@/Components/DictionaryEntry.vue';
import { fetchDictionaryPronounceUrl, playPronunciation } from '@/lib/pronunciation';

const props = defineProps({
    vocab: { type: Object, required: true },
});

const menuOpen = ref(false);

async function pronounce() {
    const word = props.vocab.word;
    await playPronunciation({
        word,
        fetchAudioUrl: () => fetchDictionaryPronounceUrl(word),
    });
}

function destroyWord() {
    if (!window.confirm(`Remove "${props.vocab.word}" from your list?`)) return;
    useForm({}).delete(`/home/vocab/${props.vocab.id}`);
}

function onDocClick(event) {
    if (!(event.target instanceof Element) || !event.target.closest('.action-menu')) {
        menuOpen.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <Head :title="vocab.word" />
    <AppLayout :title="vocab.word" :heading="vocab.word" :back-href="appPath('/home/vocab')">
        <div class="card">
            <div class="vocab-detail-header">
                <div class="vocab-detail-title">
                    <p class="card-title" style="margin:0">{{ vocab.word }}</p>
                    <p
                        v-if="vocab.phonetic"
                        class="card-subtitle"
                        style="font-style:italic;margin-top:6px"
                    >{{ vocab.phonetic }}</p>
                </div>
                <div class="vocab-card-actions">
                    <button
                        type="button"
                        class="btn-icon"
                        title="Pronounce"
                        aria-label="Pronounce"
                        @click="pronounce"
                    >🔊</button>
                    <div class="action-menu">
                        <button
                            type="button"
                            class="btn-icon action-menu-trigger"
                            aria-label="Options"
                            aria-haspopup="true"
                            @click.stop="menuOpen = !menuOpen"
                        >⋮</button>
                        <div class="action-menu-panel" :hidden="!menuOpen">
                            <button type="button" class="action-menu-danger" @click="destroyWord">Delete</button>
                        </div>
                    </div>
                </div>
            </div>

            <DictionaryEntry
                :meanings="vocab.meanings || []"
                :extra-examples="vocab.examples || []"
                prefer-detail
            />
        </div>
    </AppLayout>
</template>
