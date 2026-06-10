<script setup>
import { onMounted, ref, watch } from 'vue';
import { apiUrl } from '../api';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    selectedFuel: { type: String, default: 'a95' },
});

const emit = defineEmits(['close']);

const stats = ref(null);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const res = await fetch(apiUrl(`/api/stats?fuel=${props.selectedFuel}`));
        const json = await res.json();

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        stats.value = json.data;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

watch(() => props.selectedFuel, load);

onMounted(load);

const statusLabels = {
    available: 'Есть',
    low: 'Мало',
    none: 'Нет',
    unknown: 'Нет данных',
};

const statusColors = {
    available: '#22C55E',
    low: '#EAB308',
    none: '#EF4444',
    unknown: '#6B7280',
};

const markerLabels = {
    green: 'Зелёные',
    yellow: 'Жёлтые',
    red: 'Красные',
    black: 'Чёрные',
};

const markerColors = {
    green: '#22C55E',
    yellow: '#EAB308',
    red: '#EF4444',
    black: '#6B7280',
};
</script>

<template>
    <div class="modal-overlay modal-overlay--sheet" @click.self="emit('close')">
        <div class="modal modal--sheet stats-modal">
            <div class="modal-report-handle" aria-hidden="true" />
            <div class="modal-report-header">
                <span class="modal-report-icon" aria-hidden="true">
                    <UiIcon name="gauge" :size="18" color="#E8B84B" />
                </span>
                <div class="modal-report-head-text">
                    <h2>Статистика</h2>
                    <p v-if="stats">{{ stats.stations_total }} АЗС · {{ stats.fuel_label }}</p>
                    <p v-else>Общая картина по городу</p>
                </div>
                <button class="close-btn close-btn--square" type="button" @click="emit('close')">
                    <UiIcon name="x" :size="14" color="#7A7570" />
                </button>
            </div>

            <div class="modal-sheet-body">
                <div v-if="loading" class="hint">Загрузка…</div>
                <p v-else-if="error" class="error">{{ error }}</p>

                <template v-else-if="stats">
                    <p class="stats-summary">
                        {{ stats.reports_24h }} сообщений за сутки
                        <span v-if="stats.pending_corrections">
                            · {{ stats.pending_corrections }} исправлений ждут подтверждения
                        </span>
                    </p>

                    <section class="stats-block">
                        <h3 class="section-label">Топливо {{ stats.fuel_label }}</h3>
                        <div class="stats-grid">
                            <div
                                v-for="(count, key) in stats.status_counts"
                                :key="key"
                                class="stats-chip"
                                :style="{ '--chip-color': statusColors[key] ?? '#6B7280' }"
                            >
                                <span class="stats-chip-dot" />
                                <span class="stats-chip-label">{{ statusLabels[key] }}</span>
                                <span class="stats-chip-value">{{ count }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="stats-block">
                        <h3 class="section-label">Цвета на карте</h3>
                        <div class="stats-grid">
                            <div
                                v-for="(count, key) in stats.marker_counts"
                                :key="key"
                                class="stats-chip"
                                :style="{ '--chip-color': markerColors[key] ?? '#6B7280' }"
                            >
                                <span class="stats-chip-dot" />
                                <span class="stats-chip-label">{{ markerLabels[key] }}</span>
                                <span class="stats-chip-value">{{ count }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="stats-block">
                        <h3 class="section-label">По сетям</h3>
                        <div class="stats-network-list">
                            <div v-for="n in stats.networks" :key="n.network" class="stats-network-row">
                                <span class="stats-network-name">{{ n.network }}</span>
                                <span class="stats-network-count">{{ n.stations_count }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="stats-block">
                        <h3 class="section-label">Всё топливо</h3>
                        <div v-for="f in stats.fuels_overview" :key="f.fuel_type" class="stats-fuel-row">
                            <span class="stats-fuel-label">{{ f.label }}</span>
                            <span class="stats-fuel-counts">
                                <span class="stats-fuel-count stats-fuel-count--ok">{{ f.counts.available }} есть</span>
                                <span class="stats-fuel-count stats-fuel-count--no">{{ f.counts.none }} нет</span>
                                <span class="stats-fuel-count stats-fuel-count--unk">{{ f.counts.unknown }} н/д</span>
                            </span>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
