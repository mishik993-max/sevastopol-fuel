<script setup>
import { cookieConsentDays } from '../composables/useCookieConsent';

const emit = defineEmits(['accept', 'open-legal']);
</script>

<template>
    <div class="cookie-banner" role="dialog" aria-label="Уведомление о cookie">
        <p class="cookie-banner-text">
            Мы сохраняем на вашем устройстве небольшие cookie и данные браузера (до {{ cookieConsentDays }} дней):
            чтобы не спрашивать геозону при каждом визите, запомнить настройки и при согласии — push-напоминания.
            Подробности — в
            <button type="button" class="cookie-banner-link" @click="emit('open-legal', 'privacy')">
                политике конфиденциальности
            </button>.
        </p>
        <button type="button" class="btn btn-primary btn-sm cookie-banner-btn" @click="emit('accept')">
            Понятно
        </button>
    </div>
</template>
