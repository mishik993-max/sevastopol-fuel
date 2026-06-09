<script setup>
import { computed, ref } from 'vue';
import { FUEL_TYPES, FUEL_STATUSES, QUEUE_SIZES, SALE_TYPES, FILL_VOLUMES, PHOTO_MAX_BYTES, PHOTO_ACCEPT_TYPES } from '../constants';
import { apiUrl } from '../api';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    station: { type: Object, required: true },
    selectedFuel: { type: String, default: 'a95' },
});

const emit = defineEmits(['submit', 'close']);

const fuelType = ref(props.selectedFuel);
const statuses = ref(['available']);
const queueSize = ref('none');
const saleTypes = ref(['regular']);
const fillVolume = ref('liters_20');
const comment = ref('');
const photo = ref(null);
const photoName = ref('');
const submitting = ref(false);
const error = ref(null);
const success = ref(false);

const photoMaxMb = PHOTO_MAX_BYTES / (1024 * 1024);

const hasNoneStatus = computed(() => statuses.value.includes('none'));

const showSaleTypes = computed(() => !hasNoneStatus.value);

const showFillVolume = computed(() => (
    !hasNoneStatus.value
    && (statuses.value.includes('available') || statuses.value.includes('low'))
));

function onStatusChange(value, event) {
    const checked = event.target.checked;

    if (value === 'none') {
        statuses.value = checked ? ['none'] : [];

        return;
    }

    if (statuses.value.includes('none')) {
        event.target.checked = false;

        return;
    }

    if (checked) {
        if (!statuses.value.includes(value)) {
            statuses.value = [...statuses.value, value];
        }
    } else {
        statuses.value = statuses.value.filter((v) => v !== value);
    }
}

async function submit() {
    if (!statuses.value.length) {
        error.value = 'Выберите хотя бы один статус';
        return;
    }

    if (showSaleTypes.value && !saleTypes.value.length) {
        error.value = 'Выберите хотя бы один способ отпуска';
        return;
    }

    submitting.value = true;
    error.value = null;

    const formData = new FormData();
    formData.append('station_id', props.station.id);
    formData.append('fuel_type', fuelType.value);
    statuses.value.forEach((v) => formData.append('statuses[]', v));
    formData.append('queue_size', queueSize.value);
    if (showSaleTypes.value) {
        saleTypes.value.forEach((v) => formData.append('sale_types[]', v));
    } else {
        formData.append('sale_types[]', 'regular');
    }
    if (showFillVolume.value) {
        formData.append('fill_volume', fillVolume.value);
    }
    if (comment.value) formData.append('comment', comment.value);
    if (photo.value) formData.append('photo', photo.value);

    try {
        const res = await fetch(apiUrl('/api/reports'), {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: formData,
        });
        const json = await res.json();
        if (!res.ok) {
            const photoError = json.errors?.photo?.[0];
            throw new Error(photoError || json.message || 'Ошибка отправки');
        }
        emit('submit', json.data);
        success.value = true;
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
}

function onPhotoChange(e) {
    const input = e.target;
    const file = input.files[0] || null;
    error.value = null;

    if (!file) {
        photo.value = null;
        photoName.value = '';
        return;
    }

    if (!PHOTO_ACCEPT_TYPES.includes(file.type)) {
        error.value = 'Только JPG или PNG';
        input.value = '';
        photo.value = null;
        photoName.value = '';
        return;
    }

    if (file.size > PHOTO_MAX_BYTES) {
        error.value = `Фото слишком большое (максимум ${photoMaxMb} МБ)`;
        input.value = '';
        photo.value = null;
        photoName.value = '';
        return;
    }

    photo.value = file;
    photoName.value = file.name;
}
</script>

<template>
    <div class="modal-overlay modal-overlay--report" @click.self="emit('close')">
        <div class="modal modal--report">
            <template v-if="success">
                <div class="report-success">
                    <div class="report-success__icon">
                        <UiIcon name="check" :size="32" color="#22C55E" />
                    </div>
                    <h2 class="report-success__title">Спасибо за отчёт!</h2>
                    <p class="report-success__text">
                        Ваша информация поможет другим водителям Севастополя
                    </p>
                    <button type="button" class="btn btn-secondary btn-block" @click="emit('close')">
                        Вернуться
                    </button>
                </div>
            </template>

            <template v-else>
                <div class="modal-report-handle" aria-hidden="true" />
                <div class="modal-report-header">
                    <span class="modal-report-icon" aria-hidden="true">
                        <UiIcon name="message-square" :size="18" color="#E8B84B" />
                    </span>
                    <div class="modal-report-head-text">
                        <h2>Сообщить об АЗС</h2>
                        <p>{{ station.network }} · {{ station.address || station.name }}</p>
                    </div>
                    <button class="close-btn close-btn--square" type="button" @click="emit('close')">
                        <UiIcon name="x" :size="14" color="#7A7570" />
                    </button>
                </div>

                <form class="modal-report-form" @submit.prevent="submit">
                <fieldset>
                    <legend>Какое топливо?</legend>
                    <div class="radio-grid">
                        <label v-for="f in FUEL_TYPES" :key="f.value" class="radio-label">
                            <input v-model="fuelType" type="radio" :value="f.value" />
                            {{ f.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Статус? <span class="fieldset-hint">можно несколько</span></legend>
                    <div class="check-grid">
                        <label v-for="s in FUEL_STATUSES" :key="s.value" class="check-label">
                            <input
                                type="checkbox"
                                :checked="statuses.includes(s.value)"
                                :disabled="s.value !== 'none' && hasNoneStatus"
                                @change="onStatusChange(s.value, $event)"
                            />
                            {{ s.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset v-if="showSaleTypes">
                    <legend>Как отпускают? <span class="fieldset-hint">можно несколько</span></legend>
                    <div class="check-grid">
                        <label v-for="t in SALE_TYPES" :key="t.value" class="check-label">
                            <input v-model="saleTypes" type="checkbox" :value="t.value" />
                            {{ t.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset v-if="showFillVolume">
                    <legend>Сколько наливают?</legend>
                    <div class="radio-grid">
                        <label v-for="v in FILL_VOLUMES" :key="v.value" class="radio-label">
                            <input v-model="fillVolume" type="radio" :value="v.value" />
                            {{ v.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="section-label">Очередь?</legend>
                    <div class="radio-grid">
                        <label v-for="q in QUEUE_SIZES" :key="q.value" class="radio-label">
                            <input v-model="queueSize" type="radio" :value="q.value" />
                            {{ q.label }}
                        </label>
                    </div>
                </fieldset>

                <label class="field">
                    Комментарий (необязательно)
                    <textarea v-model="comment" class="field-textarea" rows="2" maxlength="500" />
                </label>

                <div class="field">
                    <span class="section-label">Фото (необязательно)</span>
                    <label class="file-upload-dashed">
                        <span class="file-upload-dashed-icon">
                            <UiIcon name="camera" :size="22" color="#4A4845" />
                        </span>
                        <span class="file-upload-dashed-text">
                            {{ photoName || `Нажмите, чтобы добавить фото (до ${photoMaxMb} МБ)` }}
                        </span>
                        <input
                            type="file"
                            class="file-upload-input"
                            accept="image/jpeg,image/png"
                            @change="onPhotoChange"
                        />
                    </label>
                </div>

                <p v-if="error" class="error">{{ error }}</p>
            </form>

            <div class="modal-report-footer">
                <button type="button" class="btn btn-accent btn-block" :disabled="submitting" @click="submit">
                    <UiIcon v-if="!submitting" name="check" :size="16" color="#0A0807" />
                    {{ submitting ? 'Отправка…' : 'Отправить отчёт' }}
                </button>
            </div>
            </template>
        </div>
    </div>
</template>
