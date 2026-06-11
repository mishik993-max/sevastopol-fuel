<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error']);

const loading = ref(false);
const analytics = ref(null);

const maxVisitors = computed(() => {
    const daily = analytics.value?.visitors_daily || [];

    return Math.max(1, ...daily.map((row) => row.unique_visitors));
});

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

            <div class="admin-visitor-chart">
                <div
                    v-for="row in analytics.visitors_daily"
                    :key="row.date"
                    class="admin-visitor-bar-row"
                    :title="`${row.date_label}: ${row.unique_visitors} уник., ${row.total_visits} заходов`"
                >
                    <span class="admin-visitor-bar-label">{{ row.date_label }}</span>
                    <div class="admin-visitor-bar-track">
                        <div
                            class="admin-visitor-bar-fill"
                            :style="{ width: `${(row.unique_visitors / maxVisitors) * 100}%` }"
                        />
                    </div>
                    <span class="admin-visitor-bar-value">{{ row.unique_visitors }}</span>
                </div>
            </div>

            <table class="admin-visitor-table" aria-label="Посетители по дням">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Уникальные</th>
                        <th>Заходов</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in [...analytics.visitors_daily].reverse()" :key="row.date">
                        <td>{{ row.date_label }}</td>
                        <td>{{ row.unique_visitors }}</td>
                        <td>{{ row.total_visits }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </section>
</template>
