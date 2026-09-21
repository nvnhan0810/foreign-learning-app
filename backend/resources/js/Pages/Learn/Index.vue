<script setup>
import { appPath } from '@/path';
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DictionaryEntry from '@/Components/DictionaryEntry.vue';
import { fetchDictionaryPronounceUrl, playPronunciation } from '@/lib/pronunciation';

const props = defineProps({
    word: { type: String, default: '' },
    result: { type: Object, default: null },
    saved: { type: Boolean, default: false },
    savedVocabularyId: { type: [Number, String], default: null },
});

const searchForm = useForm({
    word: props.word || '',
});

watch(
    () => props.word,
    (value) => {
        searchForm.word = value || '';
    },
);

const saveMeanings = computed(() => {
    if (!props.result) return [];
    const entrySynonyms = cleanWords(props.result.synonyms);
    const entryAntonyms = cleanWords(props.result.antonyms);

    return (props.result.meanings || []).map((meaning, index) => {
        const examples = Array.isArray(meaning.examples) ? meaning.examples : [];
        const example = meaning.example || examples[0] || '';
        let synonyms = cleanWords(meaning.synonyms);
        let antonyms = cleanWords(meaning.antonyms);
        if (index === 0) {
            synonyms = [...new Set([...synonyms, ...entrySynonyms])];
            antonyms = [...new Set([...antonyms, ...entryAntonyms])];
        }
        return {
            part_of_speech: meaning.part_of_speech || '',
            definition: meaning.definition || '',
            example,
            synonyms,
            antonyms,
        };
    });
});

const saveForm = useForm({
    word: '',
    phonetic: '',
    meanings: [],
});

function cleanWords(list) {
    return [...new Set(
        (list || [])
            .filter((item) => typeof item === 'string' && item.trim() !== '')
            .map((item) => item.trim()),
    )];
}

function submitSearch() {
    searchForm.post(appPath('/home/lookup'));
}

function clearWord() {
    searchForm.word = '';
}

async function pronounce() {
    const word = props.result?.word || '';
    await playPronunciation({
        word,
        audioUrl: props.result?.audio_url,
        fetchAudioUrl: word ? () => fetchDictionaryPronounceUrl(word) : undefined,
    });
}

function submitSave() {
    if (!props.result) return;
    saveForm.word = props.result.word || '';
    saveForm.phonetic = props.result.phonetic || '';
    saveForm.meanings = saveMeanings.value;
    saveForm.post(appPath('/home/lookup/save'));
}
</script>

<template>
    <Head title="Lookup" />
    <AppLayout title="Lookup" heading="Lookup">

        <form class="flc-form-submit" @submit.prevent="submitSearch">
            <div class="form-group">
                <div class="input-with-clear">
                    <input
                        v-model="searchForm.word"
                        type="text"
                        name="word"
                        class="form-control"
                        placeholder="Type or paste a word..."
                        autocomplete="off"
                        enterkeyhint="search"
                        autofocus
                    >
                    <button
                        v-show="searchForm.word"
                        type="button"
                        class="input-clear"
                        aria-label="Clear"
                        @click="clearWord"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-block" :disabled="searchForm.processing">Look up</button>
        </form>

        <div v-if="result" class="card" style="margin-top:20px">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <p class="card-title" style="margin:0">{{ result.word }}</p>
                <button
                    v-if="result.audio_url"
                    type="button"
                    class="btn btn-secondary btn-sm"
                    title="Pronounce"
                    aria-label="Pronounce"
                    @click="pronounce"
                >🔊</button>
            </div>
            <p
                v-if="result.phonetic"
                class="card-subtitle"
                style="font-style:italic;margin-top:6px"
            >{{ result.phonetic }}</p>

            <DictionaryEntry
                :meanings="result.meanings || []"
                :synonyms="result.synonyms || []"
                :antonyms="result.antonyms || []"
                :prefer-detail="false"
            />
        </div>

        <form
            v-if="result && !saved"
            style="margin-top:12px"
            @submit.prevent="submitSave"
        >
            <button type="submit" class="btn btn-secondary btn-block" :disabled="saveForm.processing">
                Save word
            </button>
        </form>
        <p v-else-if="result && saved" class="muted" style="text-align:center;margin-top:12px">
            Saved
            <template v-if="savedVocabularyId">
                · <Link :href="`/home/vocab/${savedVocabularyId}`">View detail</Link>
            </template>
        </p>
    </AppLayout>
</template>
