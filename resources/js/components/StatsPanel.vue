<script setup>
import { onMounted, ref, watch } from 'vue';
import { apiUrl } from '../api';

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

const markerLabels = {
    green: 'Зелёные',
    yellow: 'Жёлтые',
    red: 'Красные',
    black: 'Чёрные',
};
</script>

<template>
    <div class="modal-overlay" @click.self="emit('close')">
        <div class="modal stats-modal">
            <button class="close-btn" type="button" @click="emit('close')">✕</button>
            <h2>Статистика</h2>

            <div v-if="loading" class="hint">Загрузка…</div>
            <p v-else-if="error" class="error">{{ error }}</p>

            <template v-else-if="stats">
                <p class="stats-summary">
                    {{ stats.stations_total }} АЗС · {{ stats.fuel_label }} ·
                    {{ stats.reports_24h }} отчётов за 24 ч
                </p>

                <section class="stats-block">
                    <h3>Статус ({{ stats.fuel_label }})</h3>
                    <div class="stats-grid">
                        <div
                            v-for="(count, key) in stats.status_counts"
                            :key="key"
                            class="stats-chip"
                        >
                            <span class="stats-chip-label">{{ statusLabels[key] }}</span>
                            <span class="stats-chip-value">{{ count }}</span>
                        </div>
                    </div>
                </section>

                <section class="stats-block">
                    <h3>Маркеры на карте</h3>
                    <div class="stats-grid">
                        <div
                            v-for="(count, key) in stats.marker_counts"
                            :key="key"
                            class="stats-chip"
                        >
                            <span class="stats-chip-label">{{ markerLabels[key] }}</span>
                            <span class="stats-chip-value">{{ count }}</span>
                        </div>
                    </div>
                </section>

                <section class="stats-block">
                    <h3>По сетям</h3>
                    <div class="stats-network-list">
                        <div v-for="n in stats.networks" :key="n.network" class="stats-network-row">
                            <span>{{ n.network }}</span>
                            <span>{{ n.stations_count }}</span>
                        </div>
                    </div>
                </section>

                <section class="stats-block">
                    <h3>Все виды топлива</h3>
                    <div v-for="f in stats.fuels_overview" :key="f.fuel_type" class="stats-fuel-row">
                        <span class="stats-fuel-label">{{ f.label }}</span>
                        <span class="stats-fuel-counts">
                            {{ f.counts.available }} е · {{ f.counts.none }} нет · {{ f.counts.unknown }} ?
                        </span>
                    </div>
                </section>

                <p v-if="stats.pending_corrections" class="stats-note">
                    Ожидают подтверждения: {{ stats.pending_corrections }} исправлений
                </p>
            </template>
        </div>
    </div>
</template>
