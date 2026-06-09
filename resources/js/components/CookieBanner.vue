<script setup>
import { cookieConsentDays } from '../composables/useCookieConsent';
import UiIcon from './UiIcon.vue';

const emit = defineEmits(['accept', 'decline', 'open-legal']);
</script>

<template>
    <div class="cookie-banner" role="dialog" aria-label="Уведомление о cookie">
        <span class="cookie-banner-icon" aria-hidden="true">
            <UiIcon name="cookie" :size="18" color="#7A7570" />
        </span>
        <div class="cookie-banner-body">
            <strong class="cookie-banner-title">Мы используем cookies</strong>
            <p class="cookie-banner-text">
                Для работы карты и сохранения фильтров (до {{ cookieConsentDays }} дней).
                <button type="button" class="cookie-banner-link" @click="emit('open-legal', 'privacy')">
                    Подробнее
                </button>
            </p>
            <div class="cookie-banner-actions">
                <button type="button" class="cookie-banner-btn cookie-banner-btn--accept" @click="emit('accept')">
                    Принять
                </button>
                <button type="button" class="cookie-banner-btn cookie-banner-btn--decline" @click="emit('decline')">
                    Отклонить
                </button>
            </div>
        </div>
    </div>
</template>
