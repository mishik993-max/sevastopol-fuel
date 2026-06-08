<script setup>
import { onMounted, ref } from 'vue';
import { useAppSettings } from '../composables/useAppSettings';
import { usePushNotifications } from '../composables/usePushNotifications';
import { swRegistrationReady } from '../swRegister';

const { supported, permissionState, subscribe } = usePushNotifications();
const { qrReminderLabel } = useAppSettings();
const visible = ref(!localStorage.getItem('push_dismissed') && !localStorage.getItem('push_subscribed'));
const blocked = ref(permissionState.value === 'denied');
const loading = ref(false);
const swReady = ref(false);
const error = ref(null);

onMounted(() => {
    if (!supported.value) return;

    swRegistrationReady
        .then((registration) => {
            swReady.value = !!registration;
        })
        .catch(() => {
            swReady.value = false;
        });
});

async function enable() {
    loading.value = true;
    error.value = null;
    try {
        await subscribe();
        visible.value = false;
    } catch (e) {
        error.value = e.message;
        blocked.value = permissionState.value === 'denied';
    } finally {
        loading.value = false;
    }
}

function dismiss() {
    localStorage.setItem('push_dismissed', '1');
    visible.value = false;
}
</script>

<template>
    <div v-if="supported && visible" class="push-prompt">
        <div class="push-text">
            <strong>Уведомления о QR</strong>
            <span>{{ qrReminderLabel }}</span>
            <span v-if="error" class="push-error">{{ error }}</span>
            <span v-else-if="!swReady && !loading" class="push-hint">
                Загрузка Service Worker… подождите пару секунд.
            </span>
            <span v-else-if="blocked" class="push-hint">
                Уведомления заблокированы в браузере. Замок в адресной строке → Уведомления → Разрешить.
            </span>
        </div>
        <div class="push-actions">
            <button
                v-if="!blocked"
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="loading || !swReady"
                @click="enable"
            >
                {{ loading ? '…' : (swReady ? 'Включить' : 'Загрузка…') }}
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="dismiss">Позже</button>
        </div>
    </div>
</template>
