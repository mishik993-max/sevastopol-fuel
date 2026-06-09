<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
    subscriptionCount: { type: Number, default: null },
});

const emit = defineEmits(['error', 'saved', 'refresh']);

const PUSH_TEMPLATES = [
    {
        id: 'qr_now',
        label: 'QR доступен сейчас',
        title: 'QR доступен',
        body: 'Сейчас можно получить QR-код на топливо в чате',
    },
    {
        id: 'qr_at',
        label: 'QR будет в…',
        title: 'QR на топливо',
        body: 'Получение QR-кода откроется в {time}. Переходите в чат.',
        needsTime: true,
    },
    {
        id: 'qr_moved',
        label: 'QR перенесён',
        title: 'QR перенесён',
        body: 'Выдача QR перенесена на {time}. Следите за обновлениями в чате.',
        needsTime: true,
    },
    {
        id: 'custom',
        label: 'Свой текст',
        title: '',
        body: '',
    },
];

const pushSubscriptions = ref(null);
const pushSending = ref(false);
const pushSendNotice = ref(null);
const templateId = ref('qr_at');
const qrTime = ref('22:00');
const defaultBotUrl = ref('');
const manualPush = ref({ title: '', body: '', url: '' });

const activeTemplate = computed(() => PUSH_TEMPLATES.find((t) => t.id === templateId.value) ?? PUSH_TEMPLATES[0]);

const previewTitle = computed(() => manualPush.value.title.trim() || 'Заголовок');
const previewBody = computed(() => manualPush.value.body.trim() || 'Текст уведомления');

function formatTimeLabel(time) {
    return (time || '22:00').slice(0, 5);
}

function applyTemplate() {
    const template = activeTemplate.value;

    if (template.id === 'custom') {
        return;
    }

    const time = formatTimeLabel(qrTime.value);
    manualPush.value.title = template.title.replace('{time}', time);
    manualPush.value.body = template.body.replace('{time}', time);

    if (!manualPush.value.url && defaultBotUrl.value) {
        manualPush.value.url = defaultBotUrl.value;
    }
}

async function loadDefaults() {
    if (props.subscriptionCount !== null) {
        pushSubscriptions.value = props.subscriptionCount;
    }

    try {
        const settingsRes = await fetch(apiUrl('/api/admin/settings'), { headers: props.authHeaders() });
        const settingsJson = await parseApiResponse(settingsRes);

        if (!settingsRes.ok) throw new Error(settingsJson.message || 'Ошибка загрузки настроек');

        const reminders = settingsJson.data.qr_reminders || [];
        const withUrl = reminders.find((r) => r.url?.trim());
        defaultBotUrl.value = withUrl?.url?.trim() || '';

        applyTemplate();
        if (!manualPush.value.url && defaultBotUrl.value) {
            manualPush.value.url = defaultBotUrl.value;
        }
    } catch (e) {
        emit('error', e.message);
    }
}

async function refreshSubscriptionCount() {
    if (props.subscriptionCount !== null) {
        pushSubscriptions.value = props.subscriptionCount;
        return;
    }

    try {
        const statusRes = await fetch(apiUrl('/api/admin/push/status'), { headers: props.authHeaders() });
        const statusJson = await parseApiResponse(statusRes);

        if (!statusRes.ok) throw new Error(statusJson.message || 'Ошибка загрузки push');

        pushSubscriptions.value = statusJson.data.subscriptions;
    } catch (e) {
        pushSubscriptions.value = null;
        emit('error', e.message);
    }
}

async function sendPush() {
    if (!window.confirm(`Отправить push ${pushSubscriptions.value} подписчикам?\n\n«${previewTitle.value}»\n${previewBody.value}`)) {
        return;
    }

    pushSending.value = true;
    pushSendNotice.value = null;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/push/send'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({
                title: manualPush.value.title.trim(),
                body: manualPush.value.body.trim(),
                url: manualPush.value.url.trim(),
            }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка отправки');

        pushSendNotice.value = json.message;
        emit('saved', json.message);
        emit('refresh');
        await refreshSubscriptionCount();
    } catch (e) {
        emit('error', e.message);
    } finally {
        pushSending.value = false;
    }
}

watch(templateId, applyTemplate);
watch(qrTime, () => {
    if (activeTemplate.value.needsTime) {
        applyTemplate();
    }
});

watch(
    () => props.subscriptionCount,
    (count) => {
        if (count !== null) {
            pushSubscriptions.value = count;
        }
    },
);

onMounted(async () => {
    await loadDefaults();
    if (props.subscriptionCount === null) {
        await refreshSubscriptionCount();
    }
});
</script>

<template>
    <section class="admin-section admin-push-page">
        <header class="admin-push-page-head">
            <div>
                <h2>Срочный push</h2>
                <p class="admin-settings-desc">
                    Разовая рассылка всем, кто включил уведомления на сайте - для срочных новостей
                    (время QR, перенос, изменение расписания).
                    <template v-if="pushSubscriptions !== null">
                        Подписок: <strong>{{ pushSubscriptions }}</strong>.
                    </template>
                </p>
            </div>
        </header>

        <div class="admin-push-layout">
            <div class="admin-push-editor">
                <label class="field">Шаблон
                    <select v-model="templateId" class="field-input">
                        <option v-for="t in PUSH_TEMPLATES" :key="t.id" :value="t.id">
                            {{ t.label }}
                        </option>
                    </select>
                </label>

                <label v-if="activeTemplate.needsTime" class="field">Время QR
                    <input v-model="qrTime" class="field-input" type="time" />
                </label>

                <label class="field">Заголовок
                    <input v-model="manualPush.title" class="field-input" type="text" maxlength="120" />
                </label>

                <label class="field">Текст
                    <textarea v-model="manualPush.body" class="field-textarea" rows="3" maxlength="300" />
                </label>

                <label class="field">Ссылка при клике (Telegram-бот)
                    <input
                        v-model="manualPush.url"
                        class="field-input"
                        type="url"
                        inputmode="url"
                        placeholder="https://t.me/your_bot"
                        maxlength="500"
                    />
                </label>

                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="pushSending || !manualPush.title.trim() || !manualPush.body.trim() || pushSubscriptions === 0"
                    @click="sendPush"
                >
                    {{ pushSending ? 'Отправка…' : 'Отправить срочный push' }}
                </button>

                <p v-if="pushSubscriptions === 0" class="hint admin-push-hint">
                    Пока никто не подписан. Пользователи включают push на главной странице sevazs.ru.
                </p>
                <p v-if="pushSendNotice" class="admin-notice">{{ pushSendNotice }}</p>
            </div>

            <aside class="admin-push-preview" aria-label="Предпросмотр уведомления">
                <p class="admin-push-preview-label">Как увидит пользователь</p>
                <div class="admin-push-preview-card">
                    <div class="admin-push-preview-app">
                        <span class="admin-push-preview-icon" aria-hidden="true" />
                        <div>
                            <p class="admin-push-preview-title">{{ previewTitle }}</p>
                            <p class="admin-push-preview-body">{{ previewBody }}</p>
                            <p v-if="manualPush.url.trim()" class="admin-push-preview-link">Откроется ссылка при нажатии</p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</template>
