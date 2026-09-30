<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import type { TranscriptSegment } from '@/lib/transcript-sync';

const props = defineProps<{
    segments: readonly TranscriptSegment[];
    activeIndex: number;
}>();

const emit = defineEmits<{
    seek: [seconds: number];
}>();

const listRef = ref<HTMLElement | null>(null);

watch(
    () => props.activeIndex,
    async (index) => {
        if (index < 0) {
            return;
        }

        await nextTick();
        const list = listRef.value;
        const active = list?.querySelector<HTMLElement>(`[data-segment-index="${index}"]`);
        active?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    },
);

function onSegmentClick(segment: TranscriptSegment): void {
    emit('seek', segment.start);
}

function formatTime(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const mins = Math.floor(total / 60);
    const secs = total % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}
</script>

<template>
    <div ref="listRef" class="transcript-timed" role="list">
        <button
            v-for="(segment, index) in segments"
            :key="`${segment.start}-${index}`"
            type="button"
            class="transcript-timed-line"
            :class="{ 'is-active': index === activeIndex }"
            :data-segment-index="index"
            role="listitem"
            @click="onSegmentClick(segment)"
        >
            <span class="transcript-timed-time">{{ formatTime(segment.start) }}</span>
            <span class="transcript-timed-text">{{ segment.text }}</span>
        </button>
    </div>
</template>
