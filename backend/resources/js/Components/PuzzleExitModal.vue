<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Leave?' },
    text: { type: String, default: "Your current round won't be saved." },
});

const emit = defineEmits(['close', 'confirm']);

const root = ref(null);

async function syncOpen(isOpen) {
    const el = root.value;
    if (!el) return;

    if (isOpen) {
        el.hidden = false;
        await nextTick();
        requestAnimationFrame(() => el.classList.add('is-open'));
        return;
    }

    el.classList.remove('is-open');
    window.setTimeout(() => {
        if (root.value) root.value.hidden = true;
    }, 180);
}

watch(
    () => props.open,
    (isOpen) => {
        syncOpen(isOpen);
    },
);

function onKeydown(event) {
    if (event.key === 'Escape' && props.open) {
        emit('close');
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    if (props.open) syncOpen(true);
});

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div ref="root" class="puzzle-modal" hidden>
            <div class="puzzle-modal-backdrop" @click="emit('close')" />
            <div class="puzzle-modal-card" role="dialog" aria-modal="true" aria-labelledby="puzzle-modal-title">
                <div class="puzzle-modal-icon" aria-hidden="true">🚪</div>
                <h3 class="puzzle-modal-title" id="puzzle-modal-title">{{ title }}</h3>
                <p class="puzzle-modal-text">{{ text }}</p>
                <div class="puzzle-modal-actions">
                    <button type="button" class="btn btn-secondary puzzle-modal-stay" @click="emit('close')">Stay</button>
                    <button type="button" class="btn puzzle-modal-leave" @click="emit('confirm')">Leave</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
