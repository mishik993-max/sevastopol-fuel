<script setup>
import { computed, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'done']);

const loading = ref(false);
const syncing = ref(false);
const preview = ref(null);
const rows = ref([]);

const selectedCount = computed(() => rows.value.filter((row) => row.selected && row.station_id).length);
const reportCount = computed(() => rows.value
    .filter((row) => row.selected && row.station_id)
    .reduce((sum, row) => sum + row.fuels.filter((fuel) => fuel.changed).length, 0));

async function loadPreview() {
    loading.value = true;
    preview.value = null;
    rows.value = [];
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/sevtech/preview'), {
            headers: props.authHeaders(),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        preview.value = json.data;
        rows.value = (json.data.items || []).map((item) => ({
            ...item,
            selected: Boolean(item.selected && item.station_id),
        }));
    } catch (e) {
        emit('error', e.message);
    } finally {
        loading.value = false;
    }
}

async function applySync() {
    const stationIds = rows.value
        .filter((row) => row.selected && row.station_id)
        .map((row) => row.station_id);

    if (!stationIds.length) {
        emit('error', 'Выберите хотя бы одну АЗС с изменениями');

        return;
    }

    const ok = window.confirm(`Обновить ${reportCount.value} отчётов для ${stationIds.length} АЗС?`);

    if (!ok) return;

    syncing.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/sevtech/sync'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({ station_ids: stationIds }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка синхронизации');

        emit('done', json.message);
        await loadPreview();
    } catch (e) {
        emit('error', e.message);
    } finally {
        syncing.value = false;
    }
}

function formatFetchedAt(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleString('ru-RU');
}

function fuelsSummary(fuels) {
    return fuels.map((fuel) => {
        if (fuel.changed) {
            return `${fuel.fuel_label}: ${fuel.current_status_label} → ${fuel.new_status_label}`;
        }

        return `${fuel.fuel_label}: ${fuel.new_status_label}`;
    }).join(', ');
}
</script>

<template>
    <section class="admin-section admin-sevtech-section">
        <div class="admin-ai-hero">
            <div>
                <h2>SevTech map (ТЭС)</h2>
                <p class="admin-ai-lead">
                    Официальная карта наличия топлива
                    <a href="https://fuel.sevtech.org/map/" target="_blank" rel="noopener noreferrer">fuel.sevtech.org/map</a>
                    (API: <code>/map/a</code>).
                    Данные подтягиваются по API и записываются как отчёты (продажа по QR).
                </p>
            </div>
        </div>

        <div class="admin-item-actions">
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="loading || syncing"
                @click="loadPreview"
            >
                {{ loading ? 'Загрузка…' : 'Загрузить с карты' }}
            </button>
        </div>

        <p v-if="preview" class="hint admin-sevtech-meta">
            Источник: {{ preview.source_url }} · {{ formatFetchedAt(preview.fetched_at) }}
        </p>

        <div v-if="preview" class="admin-stats-grid admin-osm-summary">
            <div class="admin-stat-card admin-stat-card--static">
                <span class="admin-stat-value">{{ preview.summary.total }}</span>
                <span class="admin-stat-label">На карте</span>
            </div>
            <div class="admin-stat-card admin-stat-card--static">
                <span class="admin-stat-value">{{ preview.summary.matched }}</span>
                <span class="admin-stat-label">Сопоставлено</span>
            </div>
            <div class="admin-stat-card admin-stat-card--static">
                <span class="admin-stat-value">{{ preview.summary.will_create }}</span>
                <span class="admin-stat-label">Обновить</span>
            </div>
            <div class="admin-stat-card admin-stat-card--static">
                <span class="admin-stat-value">{{ preview.summary.unmatched }}</span>
                <span class="admin-stat-label">Без пары</span>
            </div>
        </div>

        <div v-if="rows.length" class="admin-ai-card-list admin-sevtech-list">
            <article
                v-for="row in rows"
                :key="row.external_id"
                class="admin-ai-card"
                :class="{ 'admin-ai-card--warn': !row.station_id }"
            >
                <div class="admin-ai-card-top">
                    <label v-if="row.station_id && row.will_create" class="admin-ai-card-check">
                        <input v-model="row.selected" type="checkbox" :disabled="syncing" />
                    </label>
                    <div v-else class="admin-ai-card-check" />

                    <div class="admin-ai-card-main">
                        <div class="admin-ai-card-title-row">
                            <h3 class="admin-ai-card-title">{{ row.name }}</h3>
                            <span v-if="row.confidence != null" class="admin-ai-badge admin-ai-badge--ok">
                                {{ row.confidence }}%
                            </span>
                            <span v-else-if="!row.station_id" class="admin-ai-badge admin-ai-badge--warn">
                                Не сопоставлено
                            </span>
                        </div>
                        <p v-if="row.address" class="admin-ai-card-address">{{ row.address }}</p>
                        <p v-if="row.station_label" class="admin-sevtech-match">{{ row.station_label }}</p>
                        <p class="admin-sevtech-fuels">{{ fuelsSummary(row.fuels) }}</p>
                    </div>
                </div>
            </article>
        </div>

        <div v-if="rows.length && selectedCount" class="admin-item-actions">
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="syncing || selectedCount === 0"
                @click="applySync"
            >
                {{ syncing ? 'Синхронизация…' : `Применить (${reportCount})` }}
            </button>
        </div>

        <details v-if="preview?.raw_sample" class="admin-sevtech-raw">
            <summary>Сырой ответ API (для отладки)</summary>
            <pre class="admin-ai-code admin-ai-code--scroll">{{ preview.raw_sample }}</pre>
        </details>

        <p class="hint admin-sevtech-note">
            Автосинхронизация: задайте <code>SEVTECH_FUEL_SCHEDULE_MINUTES=10</code> в <code>.env</code>
            и добавьте <code>stations:sync-sevtech</code> в cron Laravel.
            API может быть недоступен вне РФ/Крыма (403).
        </p>
    </section>
</template>
