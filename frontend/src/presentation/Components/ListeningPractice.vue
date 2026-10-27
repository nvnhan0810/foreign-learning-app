<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

export interface SessionOption {
  type: 'quiz' | 'test' | 'exam';
  title: string;
  question_count: number;
  time_limit_minutes: number;
  available: boolean;
  bank_count: number;
  bank_status: string;
}
const props = defineProps<{ mediaId: number; options: SessionOption[] }>();
const dialog = ref<HTMLDialogElement | null>(null);
const form = useForm({ type: 'quiz' });
function start(type: SessionOption['type']): void {
  form.type = type;
  form.post(`/media/${props.mediaId}/listening/start`, {
    onSuccess: () => dialog.value?.close(),
  });
}
function refresh(): void {
  router.reload({ only: ['item', 'sessionOptions'] });
}
</script>

<template>
  <button type="button" class="btn btn-sm" @click="dialog?.showModal()">Practice</button>
  <dialog ref="dialog" class="learning-session-dialog" @click.self="dialog?.close()">
    <div class="transcript-panel-head">
      <h2>Practice listening</h2>
      <button type="button" class="btn-icon" aria-label="Close practice" @click="dialog?.close()">
        ×
      </button>
    </div>
    <p v-if="!options.some((option) => option.available)" class="muted">
      {{
        options[0]?.bank_status === 'failed'
          ? 'Add or update the transcript to generate questions.'
          : 'Questions are being prepared. Add a longer transcript if there are not enough questions.'
      }}
      <button type="button" class="btn btn-sm btn-secondary" @click="refresh">Refresh</button>
    </p>
    <form v-for="option in options" :key="option.type" @submit.prevent="start(option.type)">
      <button type="submit" class="btn btn-block" :disabled="!option.available || form.processing">
        {{
          option.type === 'quiz'
            ? 'Quick Quiz'
            : option.type === 'test'
              ? 'Listening Test'
              : 'Listening Exam'
        }}
        · {{ option.question_count }} questions · {{ option.time_limit_minutes }} min
      </button>
      <p v-if="!option.available" class="muted">
        {{ option.bank_count }}/{{ option.question_count }} questions ready
      </p>
    </form>
  </dialog>
</template>

<style scoped>
.learning-session-dialog {
  width: min(480px, calc(100vw - 32px));
  max-height: calc(100dvh - 48px);
  margin: auto;
  padding: 20px;
  border: 1px solid var(--border);
  border-radius: 20px;
  background: var(--card);
  color: var(--text);
}
.learning-session-dialog::backdrop {
  background: rgb(0 0 0 / 45%);
}
h2 {
  margin: 0;
  font-size: 20px;
}
form {
  margin-top: 16px;
}
</style>
