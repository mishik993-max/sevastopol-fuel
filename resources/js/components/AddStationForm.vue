<script setup>
import { computed, ref, watch } from 'vue';
import { isInBbox, useAppSettings } from '../composables/useAppSettings';
import { apiUrl } from '../api';

const props = defineProps({
    selectedFuel: { type: String, default: 'a95' },
    pickCoords: { type: Object, default: null },
    userPosition: { type: Object, default: null },
});

const emit = defineEmits(['close', 'submit', 'start-pick', 'stop-pick']);

const networkPreset = ref('Атан');
const networkCustom = ref('');
const name = ref('');
const address = ref('');
const latitude = ref(null);
const longitude = ref(null);
const submitting = ref(false);
const error = ref(null);
const picking = ref(false);

const { networkPriority } = useAppSettings();
const networkOptions = computed(() => [...networkPriority.value, 'АЗС', 'Другое']);

const network = computed(() => (
    networkPreset.value === 'Другое' ? networkCustom.value.trim() : networkPreset.value
));

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

    if (!isInBbox(lat, lng)) {
        error.value = 'Вы находитесь не в Севастополе';
        return;
    }

    latitude.value = lat;
    longitude.value = lng;
    error.value = null;
}

async function submit() {
    if (!network.value) {
        error.value = 'Выберите или впишите сеть заправки';
        return;
    }

    if (latitude.value === null || longitude.value === null) {
        error.value = 'Поставьте точку на карте, где стоит заправка';
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl(`/api/stations?fuel=${props.selectedFuel}`), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                network: network.value,
                name: name.value.trim() || network.value,
                address: address.value.trim(),
                latitude: latitude.value,
                longitude: longitude.value,
            }),
        });

        const json = await res.json();

        if (!res.ok) {
            const msg = json.message
                || Object.values(json.errors || {}).flat().join(' ')
                || 'Не удалось сохранить';
            throw new Error(msg);
        }

        emit('submit', json.data);
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
            <p class="add-pick-bar-text">Нажмите на карту, где стоит заправка</p>
            <button type="button" class="btn btn-secondary btn-sm" @click="togglePick">
                Отмена
            </button>
        </div>
    </div>

    <div v-else class="modal-overlay" @click.self="onClose">
        <div class="modal">
            <button class="close-btn" type="button" @click="onClose">✕</button>
            <h2>Добавить заправку</h2>
            <p class="hint">Не нашли заправку на карте? Добавьте её, и её увидят все.</p>

            <form @submit.prevent="submit">
                <label class="field">
                    Сеть
                    <select v-model="networkPreset" class="field-input">
                        <option v-for="n in networkOptions" :key="n" :value="n">{{ n }}</option>
                    </select>
                </label>

                <label v-if="networkPreset === 'Другое'" class="field">
                    Название сети
                    <input v-model="networkCustom" class="field-input" type="text" maxlength="80" />
                </label>

                <label class="field">
                    Название (по желанию)
                    <input v-model="name" class="field-input" type="text" maxlength="120" placeholder="Например: АЗС №3" />
                </label>

                <label class="field">
                    Адрес
                    <input
                        v-model="address"
                        class="field-input"
                        type="text"
                        maxlength="255"
                        required
                        placeholder="ул. Примерная, 1"
                    />
                </label>

                <div class="field">
                    <span class="field-label">Местоположение</span>
                    <div class="pick-actions">
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            :class="{ active: picking }"
                            @click="togglePick"
                        >
                            {{ picking ? 'Отмена' : 'На карте' }}
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="useMyLocation">
                            Где я
                        </button>
                    </div>
                    <p v-if="latitude != null" class="coords-hint">
                        {{ Number(latitude).toFixed(5) }}, {{ Number(longitude).toFixed(5) }}
                    </p>
                    <p v-else class="pick-hint">Точка не выбрана</p>
                </div>

                <p v-if="error" class="error">{{ error }}</p>

                <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
                    {{ submitting ? 'Сохранение…' : 'Добавить на карту' }}
                </button>
            </form>
        </div>
    </div>
</template>
