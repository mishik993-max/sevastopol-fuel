<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { ONBOARDING_STEPS } from '../data/guide';

const emit = defineEmits(['finish', 'open-guide']);

const stepIndex = ref(0);
const rect = ref(null);

const step = computed(() => ONBOARDING_STEPS[stepIndex.value]);
const isLast = computed(() => stepIndex.value >= ONBOARDING_STEPS.length - 1);

async function updateRect() {
    await nextTick();
    const el = document.querySelector(step.value?.target);

    if (!el) {
        rect.value = null;
        return;
    }

    const box = el.getBoundingClientRect();
    rect.value = {
        top: box.top,
        left: box.left,
        width: box.width,
        height: box.height,
    };
}

function next() {
    if (isLast.value) {
        localStorage.setItem('onboarding_done', '1');
        emit('finish');
        return;
    }

    stepIndex.value += 1;
    updateRect();
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

onMounted(updateRect);
window.addEventListener('resize', updateRect);
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

        <div class="tour-card">
            <p class="tour-step-num">{{ stepIndex + 1 }} / {{ ONBOARDING_STEPS.length }}</p>
            <h3 class="tour-title">{{ step.title }}</h3>
            <p class="tour-text">{{ step.text }}</p>
            <div class="tour-actions">
                <button type="button" class="btn btn-ghost btn-sm" @click="skip">Пропустить</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="openGuide">Справочник</button>
                <button type="button" class="btn btn-primary btn-sm" @click="next">
                    {{ isLast ? 'Готово' : 'Далее' }}
                </button>
            </div>
        </div>
    </div>
</template>
