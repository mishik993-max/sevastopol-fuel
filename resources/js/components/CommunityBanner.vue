<script setup>
import { ref } from 'vue';
import { TELEGRAM_CHAT_URL } from '../constants';
import UiIcon from './UiIcon.vue';

const visible = ref(!localStorage.getItem('community_banner_dismissed'));

function dismiss() {
    localStorage.setItem('community_banner_dismissed', '1');
    visible.value = false;
}
</script>

<template>
    <div v-if="visible" class="community-banner">
        <span class="community-banner-icon" aria-hidden="true">
            <UiIcon name="thumbs-up" :size="16" color="#C8A840" />
        </span>
        <div class="community-banner-text">
            <span class="community-banner-title">Чем больше людей пользуются картой — тем актуальнее данные</span>
            <span class="community-banner-sub">
                Расскажите знакомым водителям: каждая отметка и рекомендация делают карту полезнее для всех.
            </span>
        </div>
        <div class="community-banner-actions">
            <a
                :href="TELEGRAM_CHAT_URL"
                target="_blank"
                rel="noopener noreferrer"
                class="community-banner-btn"
            >
                Telegram
            </a>
            <button type="button" class="community-banner-dismiss" aria-label="Закрыть" @click="dismiss">
                <UiIcon name="x" :size="13" color="#7A7570" />
            </button>
        </div>
    </div>
</template>
