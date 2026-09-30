<script setup>
import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    disabled: { type: Boolean, default: false },
});

const PULL_THRESHOLD = 72;
const PULL_MAX = 112;
const PULL_RESISTANCE = 0.42;

const pullDistance = ref(0);
const refreshing = ref(false);

let startY = 0;
let tracking = false;
let armed = false;

const indicatorVisible = computed(() => refreshing.value || pullDistance.value > 6);
const indicatorReady = computed(() => pullDistance.value >= PULL_THRESHOLD || refreshing.value);
const indicatorStyle = computed(() => ({
    transform: `translate(-50%, ${Math.max(0, pullDistance.value - 8)}px)`,
    opacity: refreshing.value ? 1 : Math.min(1, pullDistance.value / (PULL_THRESHOLD * 0.55)),
}));

function isRootScrollAtTop() {
    const windowTop = window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
    if (windowTop > 1) {
        return false;
    }

    const main = document.querySelector('.user-main');
    if (main instanceof HTMLElement && main.scrollTop > 1) {
        return false;
    }

    return true;
}

function findScrollableParent(target) {
    let node = target instanceof Element ? target : null;
    while (node && node !== document.body && node !== document.documentElement) {
        if (node instanceof HTMLElement) {
            const style = window.getComputedStyle(node);
            const overflowY = style.overflowY;
            const canScroll =
                (overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'overlay') &&
                node.scrollHeight > node.clientHeight + 1;
            if (canScroll) {
                return node;
            }
        }
        node = node.parentElement;
    }
    return null;
}

function resetPull() {
    tracking = false;
    armed = false;
    if (!refreshing.value) {
        pullDistance.value = 0;
    }
}

function onTouchStart(event) {
    if (props.disabled || refreshing.value || event.touches.length !== 1) {
        return;
    }

    const touch = event.touches[0];
    if (!touch) {
        return;
    }

    const scrollParent = findScrollableParent(event.target);
    if (scrollParent && scrollParent.scrollTop > 1) {
        return;
    }

    if (!isRootScrollAtTop()) {
        return;
    }

    startY = touch.clientY;
    tracking = true;
    armed = false;
    pullDistance.value = 0;
}

function onTouchMove(event) {
    if (!tracking || props.disabled || refreshing.value) {
        return;
    }

    const touch = event.touches[0];
    if (!touch) {
        return;
    }

    const dy = touch.clientY - startY;
    if (dy <= 0) {
        pullDistance.value = 0;
        armed = false;
        return;
    }

    if (!isRootScrollAtTop()) {
        resetPull();
        return;
    }

    const scrollParent = findScrollableParent(event.target);
    if (scrollParent && scrollParent.scrollTop > 1) {
        resetPull();
        return;
    }

    const distance = Math.min(PULL_MAX, dy * PULL_RESISTANCE);
    pullDistance.value = distance;
    armed = distance >= PULL_THRESHOLD;

    if (distance > 10 && event.cancelable) {
        event.preventDefault();
    }
}

function onTouchEnd() {
    if (!tracking) {
        return;
    }

    tracking = false;
    if (armed && !refreshing.value) {
        void runRefresh();
        return;
    }

    pullDistance.value = 0;
    armed = false;
}

function runRefresh() {
    refreshing.value = true;
    pullDistance.value = PULL_THRESHOLD;
    armed = false;

    return new Promise((resolve) => {
        router.reload({
            preserveScroll: true,
            onFinish: () => resolve(),
            onCancel: () => resolve(),
            onError: () => resolve(),
        });
    }).finally(() => {
        refreshing.value = false;
        pullDistance.value = 0;
    });
}

onMounted(() => {
    document.addEventListener('touchstart', onTouchStart, { passive: true });
    document.addEventListener('touchmove', onTouchMove, { passive: false });
    document.addEventListener('touchend', onTouchEnd, { passive: true });
    document.addEventListener('touchcancel', onTouchEnd, { passive: true });
});

onUnmounted(() => {
    document.removeEventListener('touchstart', onTouchStart);
    document.removeEventListener('touchmove', onTouchMove);
    document.removeEventListener('touchend', onTouchEnd);
    document.removeEventListener('touchcancel', onTouchEnd);
});
</script>

<template>
    <div
        class="ptr-indicator"
        :class="{ 'is-visible': indicatorVisible, 'is-ready': indicatorReady, 'is-refreshing': refreshing }"
        :style="indicatorStyle"
        aria-hidden="true"
    >
        <span class="ptr-indicator-spinner" />
    </div>
</template>
