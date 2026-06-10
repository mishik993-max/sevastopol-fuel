<script setup>
import { ref, watch } from 'vue';
import { isInSevastopolArea } from '../constants';
import { apiUrl } from '../api';

const props = defineProps({
    station: { type: Object, required: true },
    selectedFuel: { type: String, default: 'a95' },
    pickCoords: { type: Object, default: null },
    userPosition: { type: Object, default: null },
});

const emit = defineEmits(['close', 'submit', 'start-pick', 'stop-pick']);

const name = ref(props.station.name || '');
const address = ref(props.station.address || '');
const latitude = ref(null);
const longitude = ref(null);
const submitting = ref(false);
const error = ref(null);
const picking = ref(false);

watch(() => props.pickCoords, (coords) => {
    if (!coords) return;
    latitude.value = coords.lat;
    longitude.value = coords.lng;
    picking.value = false;
    emit('stop-pick');
}, { deep: true });

function togglePick() {
    if (picking.value) {
        picking.value = false;
        emit('stop-pick');
        return;
    }

    picking.value = true;
    emit('start-pick');
}

function useMyLocation() {
    if (!props.userPosition) {
        error.value = 'Не удалось определить ваше местоположение';
        return;
    }

    const lat = Number(props.userPosition.lat);
    const lng = Number(props.userPosition.lng);

    if (!isInSevastopolArea(lat, lng)) {
        error.value = 'Вы находитесь не в Севастополе';
        return;
    }

    latitude.value = lat;
    longitude.value = lng;
    error.value = null;
}

function buildCorrections() {
    const items = [];
    const trimmedName = name.value.trim();
    const trimmedAddress = address.value.trim();

    if (trimmedName && trimmedName !== props.station.name) {
        items.push({ field: 'name', name: trimmedName });
    }

    if (trimmedAddress && trimmedAddress !== props.station.address) {
        items.push({ field: 'address', address: trimmedAddress });
    }

    if (latitude.value !== null && longitude.value !== null) {
        const sameLat = Math.abs(Number(latitude.value) - Number(props.station.latitude)) < 0.00001;
        const sameLng = Math.abs(Number(longitude.value) - Number(props.station.longitude)) < 0.00001;

        if (!sameLat || !sameLng) {
            items.push({
                field: 'location',
                latitude: latitude.value,
                longitude: longitude.value,
            });
        }
    }

    return items;
}

async function submit() {
    const corrections = buildCorrections();

    if (corrections.length === 0) {
        error.value = 'Поменяйте название, адрес или передвиньте точку на карте';
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl(`/api/stations/${props.station.id}/corrections?fuel=${props.selectedFuel}`), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ corrections }),
        });

        const json = await res.json();

        if (!res.ok) {
            const msg = json.message
                || Object.values(json.errors || {}).flat().join(' ')
                || 'Не удалось отправить';
            throw new Error(msg);
        }

        emit('submit', json.data.station);
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
}

function onClose() {
    if (picking.value) emit('stop-pick');
    emit('close');
}
</script>

<template>
    <div v-if="picking" class="add-pick-bar">
        <div class="add-pick-bar-inner">
            <p class="add-pick-bar-text">Нажмите на карту - куда передвинуть заправку</p>
            <button type="button" class="btn btn-secondary btn-sm" @click="togglePick">
                Отмена
            </button>
        </div>
    </div>

    <div v-else class="modal-overlay" @click.self="onClose">
        <div class="modal">
            <button class="close-btn" type="button" @click="onClose">✕</button>
            <h2>Исправить данные</h2>
            <p class="hint">
                {{ station.network }}. Изменения появятся после того, как их подтвердят 5 разных человек.
            </p>

            <form @submit.prevent="submit">
                <label class="field">
                    Название
                    <input v-model="name" class="field-input" type="text" maxlength="120" />
                </label>

                <label class="field">
                    Адрес
                    <input v-model="address" class="field-input" type="text" maxlength="255" />
                </label>

                <div class="field">
                    <span class="field-label">Местоположение на карте</span>
                    <div class="pick-actions">
                        <button type="button" class="btn btn-secondary btn-sm" @click="togglePick">
                            Перенести
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="useMyLocation">
                            Где я
                        </button>
                    </div>
                    <p v-if="latitude != null" class="coords-hint">
                        Новая точка: {{ Number(latitude).toFixed(5) }}, {{ Number(longitude).toFixed(5) }}
                    </p>
                    <p v-else class="pick-hint">
                        Сейчас: {{ Number(station.latitude).toFixed(5) }}, {{ Number(station.longitude).toFixed(5) }}
                    </p>
                </div>

                <p v-if="error" class="error">{{ error }}</p>

                <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
                    {{ submitting ? 'Отправка…' : 'Предложить исправление' }}
                </button>
            </form>
        </div>
    </div>
</template>
