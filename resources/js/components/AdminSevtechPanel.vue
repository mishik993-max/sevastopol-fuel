<script setup>
import { computed, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';
import { distanceM } from '../constants';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'done']);

const loading = ref(false);
const syncing = ref(false);
const rebindingId = ref(null);
const preview = ref(null);
const rows = ref([]);
const stationCatalog = ref([]);

const selectedCount = computed(() => rows.value.filter((row) => row.selected && row.station_id).length);
const reportCount = computed(() => rows.value
    .filter((row) => row.selected && row.station_id)
    .reduce((sum, row) => sum + row.fuels.filter((fuel) => fuel.changed).length, 0));

function mapUrl(stationId) {
    return stationId ? `/?station=${stationId}` : '#';
}

function confidenceClass(score) {
    if (score == null) return '';
    if (score >= 75) return 'admin-ai-badge--good';
    if (score >= 50) return 'admin-ai-badge--ok';

    return 'admin-ai-badge--low';
}

function catalogDistance(row, stationId) {
    if (row.latitude == null || row.longitude == null) {
        return null;
    }

    const entry = stationCatalog.value.find((item) => item.station_id === stationId);

    if (!entry?.latitude || !entry?.longitude) {
        return null;
    }

    return Math.round(distanceM(row.latitude, row.longitude, entry.latitude, entry.longitude));
}

function stationOptions(row) {
    const seen = new Set();
    const options = [];

    for (const item of row.candidates ?? []) {
        if (seen.has(item.station_id)) {
            continue;
        }

        seen.add(item.station_id);
        options.push({
            ...item,
            distance_m: item.distance_m ?? catalogDistance(row, item.station_id),
        });
    }

    const catalog = [...(stationCatalog.value || [])]
        .map((item) => ({
            ...item,
            score: null,
            match_type: 'manual',
            distance_m: catalogDistance(row, item.station_id),
        }))
        .sort((left, right) => (left.distance_m ?? 999999) - (right.distance_m ?? 999999));

    for (const item of catalog) {
        if (seen.has(item.station_id)) {
            continue;
        }

        seen.add(item.station_id);
        options.push(item);
    }

    return options;
}

function selectedStation(row) {
    if (!row.station_id) {
        return null;
    }

    return stationOptions(row).find((item) => item.station_id === row.station_id) ?? {
        station_id: row.station_id,
        label: row.station_label,
        address: row.station_address,
        score: row.confidence,
        match_type: row.match_type,
        distance_m: row.match_distance_m,
    };
}

function sevtechFuelsPayload(row) {
    return row.fuels.map((fuel) => ({
        fuel_type: fuel.fuel_type,
        status: fuel.new_status,
        sale_types: fuel.sale_types,
    }));
}

async function loadPreview() {
    loading.value = true;
    preview.value = null;
    rows.value = [];
    stationCatalog.value = [];
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/sevtech/preview'), {
            headers: props.authHeaders(),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        preview.value = json.data;
        stationCatalog.value = json.data.station_catalog || [];
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

async function onStationChange(row) {
    if (!row.station_id) {
        row.station_label = null;
        row.station_address = null;
        row.confidence = null;
        row.match_type = null;
        row.match_distance_m = null;
        row.will_create = false;
        row.selected = false;

        return;
    }

    rebindingId.value = row.external_id;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/sevtech/rebind'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({
                station_id: row.station_id,
                fuels: sevtechFuelsPayload(row),
            }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка привязки');

        const data = json.data;
        row.station_label = data.station_label;
        row.station_address = data.station_address;
        row.fuels = data.fuels;
        row.will_create = data.will_create;
        row.selected = Boolean(data.will_create);
        row.confidence = selectedStation(row)?.score ?? null;
        row.match_type = selectedStation(row)?.match_type ?? 'manual';
        row.match_distance_m = selectedStation(row)?.distance_m ?? null;
    } catch (e) {
        emit('error', e.message);
    } finally {
        rebindingId.value = null;
    }
}

async function applySync() {
    const items = rows.value
        .filter((row) => row.selected && row.station_id)
        .map((row) => ({
            station_id: row.station_id,
            fuels: row.fuels,
        }));

    if (!items.length) {
        emit('error', 'Выберите хотя бы одну АЗС с изменениями');

        return;
    }

    const ok = window.confirm(`Обновить ${reportCount.value} отчётов для ${items.length} АЗС?`);

    if (!ok) return;

    syncing.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/sevtech/sync'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({ items }),
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

function optionLabel(item) {
    let text = item.label;

    if (item.score != null) {
        text += ` · ${item.score}%`;
    }

    if (item.match_type === 'coordinates') {
        text += ' (GPS)';
    } else if (item.match_type === 'address') {
        text += ' (адрес)';
    }

    if (item.distance_m != null) {
        text += ` · ${item.distance_m} м`;
    }

    return text;
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
                        <input v-model="row.selected" type="checkbox" :disabled="syncing || rebindingId === row.external_id" />
                    </label>
                    <div v-else class="admin-ai-card-check" />

                    <div class="admin-ai-card-main">
                        <div class="admin-ai-card-title-row">
                            <h3 class="admin-ai-card-title">{{ row.name }}</h3>
                            <span
                                v-if="row.confidence != null"
                                class="admin-ai-badge"
                                :class="confidenceClass(row.confidence)"
                            >
                                {{ row.confidence }}%
                                <template v-if="row.match_type === 'coordinates'"> · GPS</template>
                                <template v-else-if="row.match_type === 'address'"> · адрес</template>
                            </span>
                            <span v-else-if="!row.station_id" class="admin-ai-badge admin-ai-badge--warn">
                                Не сопоставлено
                            </span>
                        </div>
                        <p v-if="row.address" class="admin-ai-card-address">{{ row.address }}</p>
                        <p class="admin-sevtech-fuels">{{ fuelsSummary(row.fuels) }}</p>
                    </div>
                </div>

                <div v-if="stationCatalog.length" class="admin-ai-card-match">
                    <label class="admin-ai-card-select-label">
                        АЗС в базе
                        <select
                            v-model.number="row.station_id"
                            class="field-input admin-ai-card-select"
                            :disabled="syncing || rebindingId === row.external_id"
                            @change="onStationChange(row)"
                        >
                            <option :value="null">— выберите вручную —</option>
                            <option
                                v-for="item in stationOptions(row)"
                                :key="item.station_id"
                                :value="item.station_id"
                            >
                                {{ optionLabel(item) }}
                            </option>
                        </select>
                    </label>

                    <div v-if="selectedStation(row)" class="admin-ai-card-match-meta">
                        <p v-if="selectedStation(row).address" class="admin-ai-card-address">
                            {{ selectedStation(row).address }}
                        </p>
                        <a
                            :href="mapUrl(row.station_id)"
                            class="admin-ai-card-map"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Открыть на карте ↗
                        </a>
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
