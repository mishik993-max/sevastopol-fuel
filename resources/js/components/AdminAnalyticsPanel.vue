<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error']);

const CHART_DAYS = 14;

const loading = ref(false);
const analytics = ref(null);

const chartDays = computed(() => {
    const rows = analytics.value?.visitors_daily || [];
    const byDate = new Map(rows.map((row) => [row.date, row.unique_visitors]));
    const today = new Date();
    const days = [];

    for (let offset = CHART_DAYS - 1; offset >= 0; offset -= 1) {
        const date = new Date(today.getFullYear(), today.getMonth(), today.getDate() - offset);
        const iso = localIsoDate(date);
        const visitors = byDate.get(iso) ?? 0;

        days.push({
            date: iso,
            label: date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }),
            visitors,
        });
    }

    return days;
});

function localIsoDate(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

const maxChartVisitors = computed(() => Math.max(1, ...chartDays.value.map((day) => day.visitors)));

const summaryLine = computed(() => {
    const summary = analytics.value?.summary;
    if (!summary) {
        return null;
    }

    const parts = [
        `${summary.total_visits} заходов`,
        `${summary.days_with_visits} ${dayWord(summary.days_with_visits)} с посещениями`,
    ];

    if (summary.tracking_since) {
        parts.push(`с ${formatDate(summary.tracking_since)}`);
    }

    return parts.join(' · ');
});

const tableRows = computed(() => {
    const rows = analytics.value?.visitors_daily || [];

    return [...rows].reverse();
});

function dayWord(count) {
    const mod10 = count % 10;
    const mod100 = count % 100;

    if (mod10 === 1 && mod100 !== 11) return 'день';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return 'дня';

    return 'дней';
}

function formatDate(iso) {
    const [year, month, day] = iso.split('-');

    return `${day}.${month}.${year}`;
}

async function load() {
    loading.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/analytics'), { headers: props.authHeaders() });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        analytics.value = json.data || null;
    } catch (e) {
        emit('error', e.message);
        analytics.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

defineExpose({ load });
</script>

<template>
    <section class="admin-section">
        <div class="admin-section-head">
            <div>
                <h2>Посетители</h2>
                <p class="hint admin-analytics-lead">
                    Уникальные устройства в день (анонимный ID в браузере, без регистрации).
                </p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="loading" @click="load">
                {{ loading ? '…' : 'Обновить' }}
            </button>
        </div>

        <p v-if="loading && !analytics" class="hint">Загрузка…</p>

        <template v-if="analytics">
            <div class="admin-stats-grid admin-stats-grid--compact">
                <div class="admin-stat-card admin-stat-card--static">
                    <span class="admin-stat-value">{{ analytics.visitors_today }}</span>
                    <span class="admin-stat-label">Сегодня</span>
                </div>
                <div class="admin-stat-card admin-stat-card--static">
                    <span class="admin-stat-value">{{ analytics.visitors_yesterday }}</span>
                    <span class="admin-stat-label">Вчера</span>
                </div>
            </div>

            <p v-if="summaryLine" class="admin-visitor-summary">{{ summaryLine }}</p>

            <div v-if="tableRows.length" class="admin-visitor-columns-wrap">
                <p class="admin-visitor-columns-title">Последние {{ CHART_DAYS }} дней</p>
                <div class="admin-visitor-columns" role="img" :aria-label="`График за ${CHART_DAYS} дней`">
                    <div
                        v-for="day in chartDays"
                        :key="day.date"
                        class="admin-visitor-column"
                        :title="`${day.label}: ${day.visitors} уник.`"
                    >
                        <div class="admin-visitor-column-track">
                            <div
                                class="admin-visitor-column-bar"
                                :class="{ 'admin-visitor-column-bar--empty': day.visitors === 0 }"
                                :style="{ height: `${(day.visitors / maxChartVisitors) * 100}%` }"
                            />
                        </div>
                    </div>
                </div>
                <div class="admin-visitor-columns-axis">
                    <span>{{ chartDays[0]?.label }}</span>
                    <span>{{ chartDays[chartDays.length - 1]?.label }}</span>
                </div>
            </div>

            <div v-if="tableRows.length" class="admin-visitor-table-wrap">
                <table class="admin-visitor-table" aria-label="Дни с посещениями">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Уникальные</th>
                            <th>Заходов</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in tableRows" :key="row.date">
                            <td>{{ row.date_label }}</td>
                            <td>{{ row.unique_visitors }}</td>
                            <td>{{ row.total_visits }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="admin-empty">
                <p>За последние 30 дней заходов пока не было</p>
            </div>
        </template>
    </section>
</template>
