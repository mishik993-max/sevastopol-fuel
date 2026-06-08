<script setup>
const emit = defineEmits(['open']);
</script>

<template>
    <nav class="legal-links" aria-label="Правовая информация">
        <button type="button" class="legal-link" @click="emit('open', 'privacy')">
            Конфиденциальность
        </button>
        <span class="legal-links-sep" aria-hidden="true">·</span>
        <button type="button" class="legal-link" @click="emit('open', 'terms')">
            Соглашение
        </button>
    </nav>
</template>
