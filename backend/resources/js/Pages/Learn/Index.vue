<script setup lang="ts">
import { appPath } from '@/path';
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DictionaryEntry from '@/Components/DictionaryEntry.vue';
import { fetchDictionaryPronounceUrl, playPronunciation } from '@/lib/pronunciation';
import {
    clearLookupSession,
    loadLookupSession,
    saveLookupSession,
    type WebLookupSession,
} from '@/lib/lookup-session';
import {
    buildMeaningsAiPrompt,
    copyTextToClipboard,
    meaningsToPrettyJson,
    parseMeaningsJson,
    type DictionaryLookupResult,
    type Meaning,
} from '@/lib/meanings-json';

const MeaningsViewMode = {
    Ui: 'ui',
    Json: 'json',
} as const;

type MeaningsViewMode = (typeof MeaningsViewMode)[keyof typeof MeaningsViewMode];

type SaveMeaningPayload = {
    part_of_speech: string;
    definition: string;
    example: string;
    examples: string[];
    synonyms: string[];
    antonyms: string[];
};

const props = withDefaults(
    defineProps<{
        word?: string;
        result?: DictionaryLookupResult | null;
        saved?: boolean;
        savedVocabularyId?: number | string | null;
        draft?: boolean;
    }>(),
    {
        word: '',
        result: null,
        saved: false,
        savedVocabularyId: null,
        draft: false,
    },
);

const searchForm = useForm<{ word: string }>({
    word: props.word || '',
});

const activeResult = ref<DictionaryLookupResult | null>(props.result);
const isDraft = ref<boolean>(props.draft);
const isSaved = ref<boolean>(props.saved);
const savedVocabularyId = ref<number | string | null>(props.savedVocabularyId);

const meaningsViewMode = ref<MeaningsViewMode>(MeaningsViewMode.Ui);
const jsonText = ref<string>('[]');
const localMeanings = ref<Meaning[]>([]);
const localSynonyms = ref<string[]>([]);
const localAntonyms = ref<string[]>([]);
const jsonError = ref<string>('');
const copyPromptLabel = ref<string>('Copy prompt');
const copyPromptBusy = ref<boolean>(false);
let persistTimer: ReturnType<typeof setTimeout> | null = null;
let skipNextPersist = false;

const saveForm = useForm<{
    word: string;
    phonetic: string;
    meanings: SaveMeaningPayload[];
}>({
    word: '',
    phonetic: '',
    meanings: [],
});

onMounted((): void => {
    if (props.result) {
        return;
    }
    restoreFromSession();
});

watch(
    () => props.word,
    (value: string | undefined): void => {
        if (value) {
            searchForm.word = value;
        }
    },
);

watch(
    () =>
        [
            props.result,
            props.draft,
            props.saved,
            props.savedVocabularyId,
        ] as const,
    ([result, draft, saved, vocabId]): void => {
        if (!result) {
            return;
        }
        isDraft.value = draft;
        isSaved.value = saved;
        savedVocabularyId.value = vocabId;
        syncFromResult(result, draft);
        schedulePersist();
    },
    { immediate: true },
);

watch(
    [meaningsViewMode, jsonText, localMeanings, localSynonyms, localAntonyms, isDraft, isSaved, savedVocabularyId],
    (): void => {
        schedulePersist();
    },
    { deep: true },
);

watch(
    () => searchForm.word,
    (): void => {
        schedulePersist();
    },
);

function cleanWords(list: unknown): string[] {
    if (!Array.isArray(list)) {
        return [];
    }

    return [...new Set(
        list
            .filter((item): item is string => typeof item === 'string' && item.trim() !== '')
            .map((item) => item.trim()),
    )];
}

function normalizeMeanings(result: DictionaryLookupResult): Meaning[] {
    const entrySynonyms = cleanWords(result.synonyms);
    const entryAntonyms = cleanWords(result.antonyms);

    return (result.meanings ?? []).map((meaning, index): Meaning => {
        const examples = cleanWords(meaning.examples);
        if (
            examples.length === 0 &&
            typeof meaning.example === 'string' &&
            meaning.example.trim() !== ''
        ) {
            examples.push(meaning.example.trim());
        }

        let synonyms = cleanWords(meaning.synonyms);
        let antonyms = cleanWords(meaning.antonyms);
        if (index === 0) {
            synonyms = [...new Set([...synonyms, ...entrySynonyms])];
            antonyms = [...new Set([...antonyms, ...entryAntonyms])];
        }

        return {
            part_of_speech:
                typeof meaning.part_of_speech === 'string' && meaning.part_of_speech.trim() !== ''
                    ? meaning.part_of_speech.trim()
                    : null,
            definition: typeof meaning.definition === 'string' ? meaning.definition : '',
            examples,
            synonyms,
            antonyms,
        };
    });
}

function syncFromResult(result: DictionaryLookupResult | null, draftFlag: boolean): void {
    jsonError.value = '';
    activeResult.value = result;
    if (!result) {
        localMeanings.value = [];
        localSynonyms.value = [];
        localAntonyms.value = [];
        jsonText.value = '[]';
        meaningsViewMode.value = MeaningsViewMode.Ui;
        return;
    }

    localMeanings.value = normalizeMeanings(result);
    localSynonyms.value = cleanWords(result.synonyms);
    localAntonyms.value = cleanWords(result.antonyms);
    jsonText.value = meaningsToPrettyJson(localMeanings.value);
    meaningsViewMode.value =
        draftFlag || localMeanings.value.length === 0
            ? MeaningsViewMode.Json
            : MeaningsViewMode.Ui;
}

function restoreFromSession(): void {
    const session = loadLookupSession();
    if (!session) {
        return;
    }

    skipNextPersist = true;
    searchForm.word = session.word;
    activeResult.value = session.result;
    isDraft.value = session.draft;
    isSaved.value = session.saved;
    savedVocabularyId.value = session.savedVocabularyId;
    localMeanings.value = session.meanings;
    localSynonyms.value = session.synonyms;
    localAntonyms.value = session.antonyms;
    jsonText.value = session.jsonText || meaningsToPrettyJson(session.meanings);
    meaningsViewMode.value =
        session.meaningsViewMode === MeaningsViewMode.Json
            ? MeaningsViewMode.Json
            : MeaningsViewMode.Ui;
    queueMicrotask((): void => {
        skipNextPersist = false;
    });
}

function schedulePersist(): void {
    if (skipNextPersist) {
        return;
    }
    if (persistTimer !== null) {
        clearTimeout(persistTimer);
    }
    persistTimer = setTimeout((): void => {
        persistTimer = null;
        persistSession();
    }, 200);
}

function persistSession(): void {
    if (!activeResult.value) {
        return;
    }

    let meanings = localMeanings.value;
    if (meaningsViewMode.value === MeaningsViewMode.Json) {
        try {
            const trimmed = jsonText.value.trim();
            if (trimmed !== '' && trimmed !== '[]') {
                meanings = parseMeaningsJson(jsonText.value);
            }
        } catch {
            // Keep last known meanings while JSON is mid-edit.
        }
    }

    const session: WebLookupSession = {
        word: searchForm.word || activeResult.value.word,
        result: {
            ...activeResult.value,
            meanings,
        },
        draft: isDraft.value,
        saved: isSaved.value,
        savedVocabularyId: savedVocabularyId.value,
        meaningsViewMode: meaningsViewMode.value,
        jsonText: jsonText.value,
        meanings,
        synonyms: localSynonyms.value,
        antonyms: localAntonyms.value,
        updatedAt: new Date().toISOString(),
    };
    saveLookupSession(session);
}

function submitSearch(): void {
    searchForm.post(appPath('/home/lookup'));
}

function clearWord(): void {
    searchForm.word = '';
    activeResult.value = null;
    isDraft.value = false;
    isSaved.value = false;
    savedVocabularyId.value = null;
    localMeanings.value = [];
    localSynonyms.value = [];
    localAntonyms.value = [];
    jsonText.value = '[]';
    meaningsViewMode.value = MeaningsViewMode.Ui;
    jsonError.value = '';
    clearLookupSession();
}

async function pronounce(): Promise<void> {
    const word = activeResult.value?.word || '';
    await playPronunciation({
        word,
        audioUrl: activeResult.value?.audio_url,
        fetchAudioUrl: word !== '' ? () => fetchDictionaryPronounceUrl(word) : undefined,
    });
}

function setMeaningsViewMode(mode: MeaningsViewMode): void {
    if (mode === meaningsViewMode.value) {
        return;
    }
    jsonError.value = '';

    try {
        if (mode === MeaningsViewMode.Json) {
            jsonText.value = meaningsToPrettyJson(localMeanings.value);
        } else {
            applyJsonToLocal(false);
        }
    } catch (error: unknown) {
        jsonError.value = error instanceof Error ? error.message : 'Could not switch view.';
        return;
    }

    meaningsViewMode.value = mode;
    schedulePersist();
}

function applyJsonToLocal(requireNonEmpty: boolean): void {
    const trimmed = jsonText.value.trim();
    if (trimmed === '' || trimmed === '[]') {
        if (requireNonEmpty) {
            throw new Error('Need at least one meaning with a definition.');
        }
        localMeanings.value = [];
        return;
    }

    localMeanings.value = parseMeaningsJson(jsonText.value);
}

function readCurrentMeanings(): Meaning[] {
    if (meaningsViewMode.value === MeaningsViewMode.Json) {
        return parseMeaningsJson(jsonText.value);
    }
    return localMeanings.value;
}

async function copyMeaningsPrompt(): Promise<void> {
    const word = activeResult.value?.word;
    if (!word) {
        return;
    }
    jsonError.value = '';
    copyPromptBusy.value = true;
    try {
        let meaningsForPrompt = localMeanings.value;
        if (meaningsViewMode.value === MeaningsViewMode.Json) {
            try {
                const trimmed = jsonText.value.trim();
                if (trimmed !== '' && trimmed !== '[]') {
                    meaningsForPrompt = parseMeaningsJson(jsonText.value);
                }
            } catch {
                // Keep UI meanings if JSON is mid-edit / invalid.
            }
        }
        await copyTextToClipboard(buildMeaningsAiPrompt(word, meaningsForPrompt));
        copyPromptLabel.value = 'Copied ✓';
        setTimeout((): void => {
            copyPromptLabel.value = 'Copy prompt';
        }, 1500);
    } catch (error: unknown) {
        jsonError.value = error instanceof Error ? error.message : 'Could not copy prompt.';
    } finally {
        copyPromptBusy.value = false;
    }
}

function submitSave(): void {
    if (!activeResult.value) {
        return;
    }
    jsonError.value = '';

    let meanings: Meaning[];
    try {
        meanings = readCurrentMeanings();
    } catch (error: unknown) {
        jsonError.value = error instanceof Error ? error.message : 'Invalid meanings JSON.';
        meaningsViewMode.value = MeaningsViewMode.Json;
        return;
    }

    localMeanings.value = meanings;
    jsonText.value = meaningsToPrettyJson(meanings);
    schedulePersist();

    saveForm.word = activeResult.value.word || '';
    saveForm.phonetic = activeResult.value.phonetic || '';
    saveForm.meanings = meanings.map((meaning): SaveMeaningPayload => ({
        part_of_speech: meaning.part_of_speech || '',
        definition: meaning.definition || '',
        example: meaning.examples[0] ?? '',
        examples: meaning.examples,
        synonyms: meaning.synonyms,
        antonyms: meaning.antonyms,
    }));
    saveForm.post(appPath('/home/lookup/save'));
}

const isJsonMode = computed((): boolean => meaningsViewMode.value === MeaningsViewMode.Json);
const canSave = computed((): boolean => activeResult.value !== null);
const saveButtonLabel = computed((): string => (isSaved.value ? 'Update JSON' : 'Save word'));
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

        <div v-if="activeResult" class="lookup-result-panel" style="margin-top:20px">
            <div class="card">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <p class="card-title" style="margin:0">{{ activeResult.word }}</p>
                    <button
                        v-if="activeResult.audio_url"
                        type="button"
                        class="btn btn-secondary btn-sm"
                        title="Pronounce"
                        aria-label="Pronounce"
                        @click="pronounce"
                    >🔊</button>
                </div>
                <p
                    v-if="activeResult.phonetic"
                    class="card-subtitle"
                    style="font-style:italic;margin-top:6px"
                >{{ activeResult.phonetic }}</p>

                <p v-if="isDraft" class="muted" style="margin-top:10px">
                    No dictionary entry yet. Add meanings with JSON or Copy prompt.
                </p>

                <div class="meanings-header">
                    <div class="meanings-mode-toggle" role="group" aria-label="Meanings view">
                        <button
                            type="button"
                            class="btn btn-sm"
                            :class="{ 'btn-secondary': isJsonMode }"
                            :aria-pressed="!isJsonMode"
                            @click="setMeaningsViewMode(MeaningsViewMode.Ui)"
                        >UI</button>
                        <button
                            type="button"
                            class="btn btn-sm"
                            :class="{ 'btn-secondary': !isJsonMode }"
                            :aria-pressed="isJsonMode"
                            @click="setMeaningsViewMode(MeaningsViewMode.Json)"
                        >JSON</button>
                    </div>
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm"
                        :disabled="copyPromptBusy"
                        @click="copyMeaningsPrompt"
                    >{{ copyPromptLabel }}</button>
                </div>

                <DictionaryEntry
                    v-show="!isJsonMode"
                    :meanings="localMeanings"
                    :synonyms="localSynonyms"
                    :antonyms="localAntonyms"
                    :prefer-detail="false"
                />

                <div v-show="isJsonMode" class="lookup-json-wrap">
                    <textarea
                        v-model="jsonText"
                        class="form-control lookup-json-input"
                        rows="12"
                        spellcheck="false"
                        placeholder='[{"part_of_speech":"noun","definition":"...","examples":[],"synonyms":[],"antonyms":[]}]'
                    />
                    <p class="muted" style="margin:0;font-size:12px">
                        Paste AI JSON here, then Update JSON / Save word.
                    </p>
                </div>
            </div>

            <p v-if="jsonError" class="alert alert-error" style="margin-top:12px">
                {{ jsonError }}
            </p>

            <form
                v-if="canSave"
                style="margin-top:12px"
                @submit.prevent="submitSave"
            >
                <button
                    type="submit"
                    class="btn btn-secondary btn-block"
                    :disabled="saveForm.processing"
                >
                    {{ saveButtonLabel }}
                </button>
            </form>

            <p v-if="isSaved" class="muted" style="text-align:center;margin-top:12px">
                Saved
                <template v-if="savedVocabularyId">
                    · <Link :href="`/home/vocab/${savedVocabularyId}`">View detail</Link>
                </template>
            </p>
        </div>
    </AppLayout>
</template>
