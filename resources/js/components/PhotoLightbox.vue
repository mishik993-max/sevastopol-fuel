<script setup>
defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: 'Фото' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <div class="photo-lightbox" role="dialog" aria-modal="true" @click.self="emit('close')">
            <button type="button" class="photo-lightbox-close" aria-label="Закрыть" @click="emit('close')">
                ✕
            </button>
            <img :src="src" :alt="alt" class="photo-lightbox-img" @click.stop />
        </div>
    </Teleport>
</template>
