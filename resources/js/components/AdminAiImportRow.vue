<script setup>
import { computed } from 'vue';

const props = defineProps({
    row: { type: Object, required: true },
    applying: { type: Boolean, default: false },
    highlight: { type: Boolean, default: false },
    showRemove: { type: Boolean, default: false },
});

const emit = defineEmits(['remove']);

const candidate = computed(() => {
    if (!props.row.station_id) {
        return null;
    }

    return props.row.candidates?.find((item) => item.station_id === props.row.station_id) ?? {
        station_id: props.row.station_id,
        label: props.row.station_label,
        address: props.row.station_address,
        score: props.row.confidence,
        map_url: mapUrl(props.row.station_id),
    };
});

const isMatched = computed(() => Boolean(props.row.station_id));
const isLowConfidence = computed(() => candidate.value?.score != null && candidate.value.score < 50);

function mapUrl(stationId) {
    return stationId ? `/?station=${stationId}` : '#';
}

function confidenceClass(score) {
    if (score == null) return '';
    if (score >= 75) return 'admin-ai-badge--good';
    if (score >= 50) return 'admin-ai-badge--ok';

    return 'admin-ai-badge--low';
}

function formatQueuedAt(iso) {
    if (!iso) return null;

    return new Date(iso).toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fuelStatusClass(status) {
    if (status === 'available') return 'admin-ai-fuel--ok';
    if (status === 'low') return 'admin-ai-fuel--low';
    if (status === 'none') return 'admin-ai-fuel--none';

    return 'admin-ai-fuel--unknown';
}
</script>

<template>
    <article
        class="admin-ai-card"
        :class="{
            'admin-ai-card--highlight': highlight,
            'admin-ai-card--warn': !isMatched,
            'admin-ai-card--risk': isLowConfidence,
        }"
    >
        <div class="admin-ai-card-top">
            <label class="admin-ai-card-check">
                <input
                    v-model="row.selected"
                    type="checkbox"
                    :disabled="!row.station_id || applying"
                />
            </label>

            <div class="admin-ai-card-main">
                <div class="admin-ai-card-title-row">
                    <h3 class="admin-ai-card-title">{{ row.raw }}</h3>
                    <span
                        v-if="candidate?.score != null"
                        class="admin-ai-badge"
                        :class="confidenceClass(candidate.score)"
                    >
                        {{ candidate.score }}%
                        <template v-if="candidate.match_type === 'address'"> · адрес</template>
                    </span>
                    <span v-else-if="!isMatched" class="admin-ai-badge admin-ai-badge--warn">
                        Не сопоставлено
                    </span>
                </div>

                <p v-if="formatQueuedAt(row.queued_at)" class="admin-ai-card-queued">
                    В очереди с {{ formatQueuedAt(row.queued_at) }}
                </p>

                <div class="admin-ai-card-fuels">
                    <span
                        v-for="fuel in row.fuels"
                        :key="fuel.fuel_type"
                        class="admin-ai-fuel"
                        :class="fuelStatusClass(fuel.statuses?.[0])"
                    >
                        {{ fuel.fuel_label }}
                        <span class="admin-ai-fuel-status">{{ fuel.status_label }}</span>
                    </span>
                </div>
            </div>
        </div>

        <div v-if="row.candidates?.length" class="admin-ai-card-match">
            <label class="admin-ai-card-select-label">
                АЗС в базе
                <select v-model.number="row.station_id" class="field-input admin-ai-card-select" :disabled="applying">
                    <option :value="null">— выберите вручную —</option>
                    <option
                        v-for="item in row.candidates"
                        :key="item.station_id"
                        :value="item.station_id"
                    >
                        {{ item.label }} · {{ item.score }}%<template v-if="item.match_type === 'address'"> (адрес)</template>
                    </option>
                </select>
            </label>

            <div v-if="candidate" class="admin-ai-card-match-meta">
                <p v-if="candidate.address" class="admin-ai-card-address">{{ candidate.address }}</p>
                <a
                    :href="candidate.map_url || mapUrl(row.station_id)"
                    class="admin-ai-card-map"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Открыть на карте ↗
                </a>
            </div>
        </div>

        <div v-else class="admin-ai-card-empty">
            В базе нет АЗС с таким номером или адресом. Добавьте заправку в каталог или дождитесь обновления данных.
        </div>

        <button
            v-if="showRemove"
            type="button"
            class="btn btn-ghost btn-sm admin-ai-card-remove"
            :disabled="applying"
            @click="emit('remove', row.queue_id)"
        >
            Убрать из очереди
        </button>
    </article>
</template>
