<script setup>
import { computed } from 'vue';
import { LEGAL_DOCS } from '../data/legal';

const props = defineProps({
    docId: { type: String, required: true },
});

const emit = defineEmits(['close']);

const doc = computed(() => LEGAL_DOCS[props.docId] ?? null);
</script>

<template>
    <div class="modal-overlay" @click.self="emit('close')">
        <div v-if="doc" class="modal legal-modal">
            <button class="close-btn" type="button" @click="emit('close')">✕</button>
            <h2>{{ doc.title }}</h2>
            <p class="legal-updated">Актуально на {{ doc.updated }} г.</p>

            <section v-for="(section, i) in doc.sections" :key="i" class="legal-section">
                <h3 class="legal-section-title">{{ section.title }}</h3>
                <p class="legal-section-text">{{ section.text }}</p>
            </section>

            <button type="button" class="btn btn-primary btn-block" @click="emit('close')">
                Понятно
            </button>
        </div>
    </div>
</template>
