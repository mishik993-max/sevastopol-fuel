<script setup>
import { TELEGRAM_CHAT_URL } from '../constants';

const emit = defineEmits(['open']);
</script>

<template>
    <nav class="legal-links" aria-label="Ссылки и правовая информация">
        <a
            :href="TELEGRAM_CHAT_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="legal-link legal-link--external"
        >
            Telegram
        </a>
        <span class="legal-links-sep" aria-hidden="true">·</span>
        <button type="button" class="legal-link" @click="emit('open', 'privacy')">
            Конфиденциальность
        </button>
        <span class="legal-links-sep" aria-hidden="true">·</span>
        <button type="button" class="legal-link" @click="emit('open', 'terms')">
            Соглашение
        </button>
    </nav>
</template>
