<script setup>
import { appPath } from '@/path';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
});

const filter = ref('all');
const url = ref('');
const fetching = ref(false);
const status = ref('');
const statusError = ref(false);
const preview = ref(null);

const form = useForm({
    url: '',
    title: '',
    frequency: 'weekly',
    difficulty: 'intermediate',
});

const filteredItems = computed(() => {
    if (filter.value === 'all') return props.items;
    return props.items.filter((item) => item.difficulty === filter.value);
});

function difficultyLabel(value) {
    if (value === 'beginner') return 'Beginner';
    if (value === 'advanced') return 'Advanced';
    return 'Intermediate';
}

async function fetchPreview() {
    const value = url.value.trim();
    if (!value || fetching.value) return;

    fetching.value = true;
    statusError.value = false;
    status.value = 'Fetching…';
    preview.value = null;

    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await fetch(appPath('/home/media/youtube/preview'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ url: value }),
        });
        const payload = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(payload.message || 'Could not fetch preview.');
        }
        const data = payload.data || {};
        preview.value = data;
        form.url = data.url || value;
        form.title = data.title || '';
        status.value = 'Preview ready — edit title if needed, then Save.';
    } catch (error) {
        statusError.value = true;
        status.value = error instanceof Error ? error.message : 'Could not fetch preview.';
    } finally {
        fetching.value = false;
    }
}

function submit() {
    form.post(appPath('/home/media/youtube'), {
        onSuccess: () => {
            preview.value = null;
            url.value = '';
            form.reset();
            form.frequency = 'weekly';
            form.difficulty = 'intermediate';
            status.value = '';
        },
    });
}
</script>

<template>
    <Head title="Listen" />
    <AppLayout title="Listen" heading="Listen">
        <section class="youtube-add card">
            <p class="card-title" style="margin-bottom:12px">Add from YouTube</p>
            <p class="muted" style="margin-top:0;margin-bottom:14px;font-size:13px">
                Paste a YouTube link (watch, youtu.be, or Shorts), fetch the title, then save.
            </p>

            <div class="form-group">
                <label for="youtube-url">YouTube URL</label>
                <div class="youtube-add-row">
                    <input
                        id="youtube-url"
                        v-model="url"
                        type="url"
                        class="form-control"
                        placeholder="https://www.youtube.com/watch?v=..."
                        autocomplete="off"
                    >
                    <button type="button" class="btn btn-secondary" :disabled="fetching" @click="fetchPreview">
                        {{ fetching ? '…' : 'Fetch' }}
                    </button>
                </div>
                <p
                    v-if="status"
                    class="youtube-add-status muted"
                    :style="statusError ? 'color:var(--danger, #c1121f)' : undefined"
                >{{ status }}</p>
            </div>

            <div v-if="preview" class="youtube-preview">
                <form class="flc-form-submit" @submit.prevent="submit">
                    <div class="youtube-preview-body">
                        <img
                            v-if="preview.thumbnail_url"
                            :src="preview.thumbnail_url"
                            :alt="preview.title || ''"
                            class="youtube-preview-thumb"
                        >
                        <div class="youtube-preview-fields">
                            <div class="form-group" style="margin-bottom:12px">
                                <label for="youtube-title">Title</label>
                                <input
                                    id="youtube-title"
                                    v-model="form.title"
                                    type="text"
                                    class="form-control"
                                    required
                                    maxlength="255"
                                >
                            </div>
                            <div class="youtube-preview-meta">
                                <div class="form-group" style="margin-bottom:0">
                                    <label for="youtube-difficulty">Difficulty</label>
                                    <select id="youtube-difficulty" v-model="form.difficulty" class="form-control">
                                        <option value="beginner">Beginner</option>
                                        <option value="intermediate">Intermediate</option>
                                        <option value="advanced">Advanced</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label for="youtube-frequency">Remind</label>
                                    <select id="youtube-frequency" v-model="form.frequency" class="form-control">
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-block" style="margin-top:16px" :disabled="form.processing">
                        Save
                    </button>
                </form>
            </div>
        </section>

        <div v-if="items.length === 0" class="empty-state">
            No media yet. Add a YouTube video above to get started.
        </div>
        <template v-else>
            <div class="media-filters" role="group" aria-label="Filter by difficulty">
                <button
                    v-for="choice in ['all', 'beginner', 'intermediate', 'advanced']"
                    :key="choice"
                    type="button"
                    class="media-filter"
                    :class="{ active: filter === choice }"
                    @click="filter = choice"
                >
                    {{ choice === 'all' ? 'All' : difficultyLabel(choice) }}
                </button>
            </div>

            <p v-show="filteredItems.length === 0" class="media-filter-empty muted">No media at this difficulty.</p>

            <div class="media-list">
                <Link
                    v-for="item in filteredItems"
                    :key="item.id"
                    :href="`/home/media/${item.id}`"
                    class="list-item"
                >
                    <div class="list-item-icon">
                        {{ item.type === 'youtube' ? '▶️' : '🎵' }}
                    </div>
                    <div class="list-item-body">
                        <p class="title">{{ item.title }}</p>
                        <p class="subtitle">
                            <span
                                class="difficulty-tag"
                                :class="`difficulty-tag--${item.difficulty || 'intermediate'}`"
                            >{{ item.difficulty_label || difficultyLabel(item.difficulty) }}</span>
                            · {{ String(item.type || '').toUpperCase() }}
                        </p>
                    </div>
                    <span class="chevron">›</span>
                </Link>
            </div>
        </template>
    </AppLayout>
</template>
