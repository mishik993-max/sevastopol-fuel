<script setup>
import { onMounted, ref } from 'vue';
import { usePushNotifications } from '../composables/usePushNotifications';
import { swRegistrationReady } from '../swRegister';
import UiIcon from './UiIcon.vue';

const { supported, permissionState, subscribe } = usePushNotifications();
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
    <div v-if="supported && visible" class="push-prompt push-prompt--gold">
        <span class="push-prompt-icon" aria-hidden="true">
            <UiIcon name="bell" :size="16" color="#C8A840" />
        </span>
        <div class="push-text">
            <span class="push-text-main">Уведомления на телефон</span>
            <span class="push-text-sub">Напомним про QR и сообщим, когда на вашей заправке (со звёздочкой) появится топливо.</span>
            <span v-if="error" class="push-error">{{ error }}</span>
            <span v-else-if="!swReady && !loading" class="push-hint">Ещё секунду, идёт подготовка…</span>
            <span v-else-if="blocked" class="push-hint">Уведомления отключены в настройках браузера</span>
        </div>
        <div class="push-actions">
            <button
                v-if="!blocked"
                type="button"
                class="push-enable-btn"
                :disabled="loading || !swReady"
                @click="enable"
            >
                {{ loading ? '…' : 'Включить' }}
            </button>
            <button type="button" class="push-dismiss-btn" aria-label="Закрыть" @click="dismiss">
                <UiIcon name="x" :size="13" color="#7A7570" />
            </button>
        </div>
    </div>
</template>
