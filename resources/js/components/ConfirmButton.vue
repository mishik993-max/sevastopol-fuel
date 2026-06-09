<script setup>
import { ref } from 'vue';
import { FUEL_TYPES } from '../constants';
import { apiUrl } from '../api';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    station: { type: Object, required: true },
    selectedFuel: { type: String, default: 'a95' },
});

const emit = defineEmits(['done', 'close']);

const fuelType = ref(props.selectedFuel);
const submitting = ref(false);
const error = ref(null);

async function confirm() {
    submitting.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl(`/api/stations/${props.station.id}/confirm`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ fuel_type: fuelType.value }),
        });
        const json = await res.json();
        if (!res.ok) {
            const msg = json.errors?.fuel_type?.[0] || json.message || 'Ошибка';
            throw new Error(msg);
        }
        emit('done', json.data);
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="modal-overlay" @click.self="emit('close')">
        <div class="modal modal-sm modal--confirm">
            <div class="modal-report-handle" aria-hidden="true" />
            <div class="modal-report-header">
                <span class="modal-report-icon" aria-hidden="true">
                    <UiIcon name="thumbs-up" :size="18" color="#22C55E" />
                </span>
                <div class="modal-report-head-text">
                    <h2>Подтверждаю</h2>
                    <p>Информация на карте актуальна</p>
                </div>
                <button class="close-btn close-btn--square" type="button" @click="emit('close')">
                    <UiIcon name="x" :size="14" color="#7A7570" />
                </button>
            </div>

            <div class="modal-report-form">
                <fieldset>
                    <legend class="section-label">Топливо</legend>
                    <div class="radio-grid">
                        <label v-for="f in FUEL_TYPES" :key="f.value" class="radio-label">
                            <input v-model="fuelType" type="radio" :value="f.value" />
                            {{ f.label }}
                        </label>
                    </div>
                </fieldset>

                <p v-if="error" class="error">{{ error }}</p>
            </div>

            <div class="modal-report-footer">
                <button
                    type="button"
                    class="btn btn-accent btn-block btn--confirm-green"
                    :disabled="submitting"
                    @click="confirm"
                >
                    <UiIcon v-if="!submitting" name="check" :size="16" color="#0A0807" />
                    {{ submitting ? '…' : 'Подтверждаю' }}
                </button>
            </div>
        </div>
    </div>
</template>
