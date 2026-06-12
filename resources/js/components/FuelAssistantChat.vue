<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { apiUrl, parseApiResponse } from '../api';
import { getPushClientId } from '../composables/usePushClientId';
import { FUEL_TYPES } from '../constants';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    contextStation: { type: Object, default: null },
    userPosition: { type: Object, default: null },
});

const emit = defineEmits(['close', 'published']);

const clientId = getPushClientId();
const messages = ref([]);
const preview = ref(null);
const selectedStationId = ref(null);
const input = ref('');
const loading = ref(false);
const confirming = ref(false);
const error = ref(null);
const messagesEl = ref(null);

const hasPreview = computed(() => Boolean(preview.value?.fuels?.length));
const canSend = computed(() => !loading.value && input.value.trim().length >= 2);
const needsStationPick = computed(() => Boolean(preview.value?.needs_station_pick));
const stationNotFound = computed(() => Boolean(preview.value?.station_not_found));
const canConfirm = computed(() => Boolean(selectedStationId.value) && !stationNotFound.value);

const stationOptions = computed(() => preview.value?.candidates ?? []);

function applyPreviewSelection(data) {
    preview.value = data?.preview ?? null;

    if (preview.value?.station_id) {
        selectedStationId.value = preview.value.station_id;
        return;
    }

    const options = preview.value?.candidates ?? [];
    selectedStationId.value = options.length === 1 ? options[0].station_id : null;
}

function fuelLabel(value) {
    return FUEL_TYPES.find((item) => item.value === value)?.label ?? value;
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

async function loadSession() {
    loading.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl(`/api/fuel-assistant/session?client_id=${clientId}`));
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка');

        if (json.data) {
            messages.value = json.data.messages ?? [];
            applyPreviewSelection(json.data);
        } else {
            messages.value = [{
                role: 'assistant',
                content: 'Привет! Расскажите, что на заправке: топливо, очередь, QR. Я уточню детали и предложу опубликовать на карте.',
                status: 'collecting',
            }];
        }
    } catch (e) {
        error.value = e.message;
        messages.value = [{
            role: 'assistant',
            content: 'Привет! Расскажите про АЗС — что с топливом и очередью.',
            status: 'collecting',
        }];
    } finally {
        loading.value = false;
        scrollToBottom();
    }
}

async function sendMessage() {
    const text = input.value.trim();

    if (!text || loading.value) return;

    const lastMsg = messages.value[messages.value.length - 1];

    if (lastMsg?.status === 'completed') {
        messages.value = [];
    }

    messages.value.push({ role: 'user', content: text });
    input.value = '';
    loading.value = true;
    error.value = null;
    scrollToBottom();

    try {
        const body = {
            client_id: clientId,
            message: text,
            context_station_id: props.contextStation?.id ?? null,
        };

        if (props.userPosition?.lat != null && props.userPosition?.lng != null) {
            body.latitude = props.userPosition.lat;
            body.longitude = props.userPosition.lng;
        }

        const res = await fetch(apiUrl('/api/fuel-assistant/message'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка');

        const data = json.data;
        const lastAssistant = [...(data.messages ?? [])].reverse().find((m) => m.role === 'assistant');

        if (lastAssistant) {
            messages.value.push(lastAssistant);
        }

        applyPreviewSelection(data);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
        scrollToBottom();
    }
}

async function confirmPreview() {
    if (!hasPreview.value || confirming.value || !canConfirm.value) return;

    confirming.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl('/api/fuel-assistant/confirm'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                client_id: clientId,
                station_id: selectedStationId.value,
            }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка публикации');

        messages.value = [{
            role: 'assistant',
            content: json.message || 'Спасибо! Данные на карте обновлены. Можете начать новый диалог.',
            status: 'completed',
        }];
        preview.value = null;
        selectedStationId.value = null;
        emit('published', json.data);
    } catch (e) {
        error.value = e.message;
    } finally {
        confirming.value = false;
        scrollToBottom();
    }
}

async function rejectPreview() {
    if (loading.value) return;

    loading.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl('/api/fuel-assistant/reject'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ client_id: clientId }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка');

        const data = json.data;
        const lastAssistant = [...(data.messages ?? [])].reverse().find((m) => m.role === 'assistant');

        if (lastAssistant) {
            messages.value.push(lastAssistant);
        }

        applyPreviewSelection(data);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
        scrollToBottom();
    }
}

async function closeAssistant() {
    try {
        await fetch(apiUrl('/api/fuel-assistant/close'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ client_id: clientId }),
        });
    } catch {
        // ignore
    }

    emit('close');
}

function previewFuelsSummary() {
    if (!preview.value?.fuels?.length) return '';

    return preview.value.fuels.map((fuel) => {
        const queue = fuel.queue_label && fuel.queue_label !== 'Не знаю' && fuel.queue_label !== 'Очереди нет'
            ? `, ${fuel.queue_label}`
            : '';

        return `${fuel.fuel_label}: ${fuel.status_label}${queue}`;
    }).join(' · ');
}

watch(selectedStationId, (value) => {
    if (preview.value && value) {
        preview.value.station_id = value;
    }
});

onMounted(loadSession);
</script>

<template>
    <div class="fuel-assistant">
        <header class="fuel-assistant-head">
            <div>
                <h2 class="fuel-assistant-title">Помощник</h2>
                <p class="fuel-assistant-sub">Личный диалог · после публикации или закрытия начнётся новый</p>
            </div>
            <button type="button" class="fuel-assistant-close" aria-label="Закрыть" @click="closeAssistant">
                <UiIcon name="x" :size="18" />
            </button>
        </header>

        <div ref="messagesEl" class="fuel-assistant-messages">
            <div
                v-for="(msg, index) in messages"
                :key="index"
                class="fuel-assistant-msg"
                :class="`fuel-assistant-msg--${msg.role}`"
            >
                {{ msg.content }}
            </div>

            <div v-if="loading" class="fuel-assistant-msg fuel-assistant-msg--assistant fuel-assistant-msg--typing">
                Думаю…
            </div>
        </div>

        <div v-if="hasPreview" class="fuel-assistant-preview">
            <p class="fuel-assistant-preview-label">Проверьте перед публикацией</p>
            <p v-if="preview.detected_label && preview.detected_label !== preview.station_label" class="fuel-assistant-preview-detected">
                Вы описали: {{ preview.detected_label }}
                <span v-if="preview.detected_address"> · {{ preview.detected_address }}</span>
            </p>
            <p v-if="preview.station_label" class="fuel-assistant-preview-station">
                {{ preview.station_label }}
            </p>
            <p v-if="preview.station_address" class="fuel-assistant-preview-address">
                {{ preview.station_address }}
            </p>
            <p class="fuel-assistant-preview-fuels">{{ previewFuelsSummary() }}</p>

            <p v-if="stationNotFound" class="fuel-assistant-preview-warning">
                Такой АЗС нет в базе. Уточните адрес или нажмите «Не так».
            </p>

            <p v-else-if="!needsStationPick && selectedStationId" class="fuel-assistant-preview-linked">
                На карте: {{ preview.station_label }}
            </p>

            <label v-else-if="needsStationPick && stationOptions.length" class="fuel-assistant-select-label">
                Выберите АЗС на карте
                <select v-model.number="selectedStationId" class="field-input fuel-assistant-select">
                    <option :value="null" disabled>— выберите —</option>
                    <option
                        v-for="item in stationOptions"
                        :key="item.station_id"
                        :value="item.station_id"
                    >
                        {{ item.label }}
                    </option>
                </select>
            </label>

            <div class="fuel-assistant-preview-actions">
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="confirming || !canConfirm"
                    @click="confirmPreview"
                >
                    {{ confirming ? 'Публикация…' : 'Всё верно' }}
                </button>
                <button
                    type="button"
                    class="btn btn-ghost btn-sm"
                    :disabled="confirming || loading"
                    @click="rejectPreview"
                >
                    Не так
                </button>
            </div>
        </div>

        <p v-if="error" class="fuel-assistant-error">{{ error }}</p>

        <form class="fuel-assistant-input-row" @submit.prevent="sendMessage">
            <textarea
                v-model="input"
                class="field-textarea fuel-assistant-input"
                rows="2"
                maxlength="2000"
                placeholder="Например: на Атан с Победы большая очередь, 95 есть"
                :disabled="loading || confirming"
            />
            <button type="submit" class="btn btn-primary fuel-assistant-send" :disabled="!canSend">
                Отправить
            </button>
        </form>
    </div>
</template>
