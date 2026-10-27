<script setup lang="ts">
import type { PropType } from 'vue';
import type { Meaning } from '@/domain/models';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  meanings: { type: Array as PropType<Meaning[]>, default: () => [] },
  synonyms: { type: Array as PropType<string[]>, default: () => [] },
  antonyms: { type: Array as PropType<string[]>, default: () => [] },
  extraExamples: { type: Array as PropType<(string | { example: string })[]>, default: () => [] },
  preferDetail: { type: Boolean as PropType<boolean>, default: true },
  /** When false, related words render as plain chips (embed WebView). */
  linkRelated: { type: Boolean as PropType<boolean>, default: true },
});

function cleanWords(list: string[]) {
  return [
    ...new Set(
      (list || []).filter((w) => typeof w === 'string' && w.trim() !== '').map((w) => w.trim()),
    ),
  ];
}

function relatedHref(word: string) {
  const detail = props.preferDetail ? '1' : '0';
  return `/home/word/${encodeURIComponent(word)}?detail=${detail}`;
}

function exampleText(example: string | { example: string }) {
  if (example && typeof example === 'object') {
    return String((typeof example === 'object' ? example.example : '') || '').trim();
  }
  return typeof example === 'string' ? example.trim() : '';
}

const entrySynonyms = computed(() => cleanWords(props.synonyms));
const entryAntonyms = computed(() => cleanWords(props.antonyms));

const normalizedMeanings = computed(() =>
  (props.meanings || []).map((meaning, index) => {
    let synonyms = cleanWords(meaning.synonyms || []);
    let antonyms = cleanWords(meaning.antonyms || []);
    if (index === 0) {
      synonyms = [...new Set([...synonyms, ...entrySynonyms.value])];
      antonyms = [...new Set([...antonyms, ...entryAntonyms.value])];
    }
    let examples = (meaning.examples || [])
      .filter((text) => typeof text === 'string' && text.trim() !== '')
      .map((text) => text.trim());
    if (examples.length === 0 && typeof meaning.example === 'string' && meaning.example.trim()) {
      examples = [meaning.example.trim()];
    }
    return { ...meaning, synonyms, antonyms, examples };
  }),
);

const moreExamples = computed(() => {
  const collected = [];
  for (const example of props.extraExamples || []) {
    const text = exampleText(example);
    if (text) collected.push(text);
  }
  for (const meaning of normalizedMeanings.value) {
    for (const text of meaning.examples.slice(1)) {
      collected.push(text);
    }
  }
  return [...new Set(collected)];
});
</script>

<template>
  <div class="dict-entry">
    <template v-if="normalizedMeanings.length > 0">
      <div v-for="(meaning, index) in normalizedMeanings" :key="index" class="meaning-block">
        <span v-if="meaning.part_of_speech" class="pos-tag">{{ meaning.part_of_speech }}</span>
        <p style="margin: 4px 0">{{ meaning.definition || '' }}</p>
        <p v-if="meaning.example" class="muted" style="font-style: italic; margin: 4px 0">
          "{{ meaning.example }}"
        </p>
        <p
          v-else-if="meaning.examples?.[0]"
          class="muted"
          style="font-style: italic; margin: 4px 0"
        >
          "{{ meaning.examples[0] }}"
        </p>

        <div v-if="meaning.synonyms.length" class="related-group">
          <div class="related-label">Synonyms</div>
          <div class="related-words">
            <component
              :is="linkRelated ? Link : 'span'"
              v-for="related in meaning.synonyms"
              :key="`syn-${index}-${related}`"
              v-bind="linkRelated ? { href: relatedHref(related) } : {}"
              class="related-word"
              >{{ related }}</component
            >
          </div>
        </div>

        <div v-if="meaning.antonyms.length" class="related-group">
          <div class="related-label">Antonyms</div>
          <div class="related-words">
            <component
              :is="linkRelated ? Link : 'span'"
              v-for="related in meaning.antonyms"
              :key="`ant-${index}-${related}`"
              v-bind="linkRelated ? { href: relatedHref(related) } : {}"
              class="related-word"
              >{{ related }}</component
            >
          </div>
        </div>
      </div>
    </template>
    <template v-else>
      <p class="muted">No detailed definitions yet.</p>
      <div v-if="entrySynonyms.length" class="related-group">
        <div class="related-label">Synonyms</div>
        <div class="related-words">
          <component
            :is="linkRelated ? Link : 'span'"
            v-for="related in entrySynonyms"
            :key="`syn-${related}`"
            v-bind="linkRelated ? { href: relatedHref(related) } : {}"
            class="related-word"
            >{{ related }}</component
          >
        </div>
      </div>
      <div v-if="entryAntonyms.length" class="related-group">
        <div class="related-label">Antonyms</div>
        <div class="related-words">
          <component
            :is="linkRelated ? Link : 'span'"
            v-for="related in entryAntonyms"
            :key="`ant-${related}`"
            v-bind="linkRelated ? { href: relatedHref(related) } : {}"
            class="related-word"
            >{{ related }}</component
          >
        </div>
      </div>
    </template>

    <template v-if="moreExamples.length">
      <h3 style="font-size: 15px; margin: 20px 0 10px">More examples</h3>
      <p
        v-for="(example, i) in moreExamples"
        :key="i"
        class="muted"
        style="font-style: italic; margin: 0 0 8px"
      >
        "{{ example }}"
      </p>
    </template>
  </div>
</template>
