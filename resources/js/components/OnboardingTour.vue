<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ONBOARDING_STEPS } from '../data/guide';

const emit = defineEmits(['finish', 'open-guide', 'prepare-step']);

const stepIndex = ref(0);
const rect = ref(null);
const cardPlacement = ref('bottom');

const step = computed(() => ONBOARDING_STEPS[stepIndex.value]);
const isLast = computed(() => stepIndex.value >= ONBOARDING_STEPS.length - 1);

const CARD_ESTIMATE_PX = 220;

function resolveCardPlacement(box, stepConfig) {
    if (stepConfig?.cardPlacement === 'top' || stepConfig?.cardPlacement === 'bottom') {
        return stepConfig.cardPlacement;
    }

    const vh = window.innerHeight;
    const spaceBelow = vh - box.bottom;
    const spaceAbove = box.top;

    if (spaceBelow < CARD_ESTIMATE_PX + 32 && spaceAbove > spaceBelow) {
        return 'top';
    }

    if (box.top + box.height / 2 > vh * 0.52) {
        return 'top';
    }

    return 'bottom';
}

async function applyStep() {
    const current = step.value;

    if (!current) {
        return;
    }

    emit('prepare-step', current);
    await nextTick();
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

    const el = document.querySelector(current.target);

    if (!el) {
        rect.value = null;
        return;
    }

    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    await new Promise((resolve) => setTimeout(resolve, 280));

    const box = el.getBoundingClientRect();
    rect.value = {
        top: box.top,
        left: box.left,
        width: box.width,
        height: box.height,
    };
    cardPlacement.value = resolveCardPlacement(box, current);
}

function next() {
    if (isLast.value) {
        localStorage.setItem('onboarding_done', '1');
        emit('finish');
        return;
    }

    stepIndex.value += 1;
}

function skip() {
    localStorage.setItem('onboarding_done', '1');
    emit('finish');
}

function openGuide() {
    localStorage.setItem('onboarding_done', '1');
    emit('open-guide');
    emit('finish');
}

function onResize() {
    applyStep();
}

watch(stepIndex, applyStep);

onMounted(() => {
    applyStep();
    window.addEventListener('resize', onResize);
});

onUnmounted(() => {
    window.removeEventListener('resize', onResize);
});
</script>

<template>
    <div class="tour-overlay">
        <div
            v-if="rect"
            class="tour-spotlight"
            :style="{
                top: `${rect.top - 6}px`,
                left: `${rect.left - 6}px`,
                width: `${rect.width + 12}px`,
                height: `${rect.height + 12}px`,
            }"
        />

        <div
            class="tour-card tour-card--figma"
            :class="cardPlacement === 'top' ? 'tour-card--top' : 'tour-card--bottom'"
        >
            <p class="tour-step-num">{{ stepIndex + 1 }} / {{ ONBOARDING_STEPS.length }}</p>
            <h3 class="tour-title">{{ step.title }}</h3>
            <p class="tour-text">{{ step.text }}</p>
            <div class="tour-actions">
                <button type="button" class="btn btn-ghost btn-sm tour-btn-ghost" @click="skip">Пропустить</button>
                <button type="button" class="btn btn-ghost btn-sm tour-btn-ghost" @click="openGuide">Справочник</button>
                <button type="button" class="btn btn-accent btn-sm tour-btn-next" @click="next">
                    {{ isLast ? 'Готово' : 'Далее' }}
                </button>
            </div>
        </div>
    </div>
</template>
