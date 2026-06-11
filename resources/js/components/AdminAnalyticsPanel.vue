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

const system = computed(() => analytics.value?.system || null);

function formatLoad(load) {
    if (!load || load.length === 0) {
        return '—';
    }

    return load.map((value) => String(value)).join(' / ');
}

function diskLabel(free, total) {
    if (free == null || total == null) {
        return '—';
    }

    return `${free} ГБ свободно из ${total} ГБ`;
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
            <h2>Аналитика</h2>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="loading" @click="load">
                {{ loading ? '…' : 'Обновить' }}
            </button>
        </div>

        <p v-if="loading && !analytics" class="hint">Загрузка…</p>

        <template v-if="analytics">
            <div class="admin-analytics-block">
                <h3 class="admin-analytics-title">Посетители</h3>
                <p class="hint admin-analytics-lead">
                    Уникальные устройства в день (анонимный ID в браузере, без регистрации).
                </p>

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
            </div>

            <div v-if="system" class="admin-analytics-block">
                <h3 class="admin-analytics-title">Нагрузка на систему</h3>
                <p class="hint admin-analytics-lead">
                    Снимок сервера в момент запроса. Load average доступен на Linux.
                </p>

                <div class="admin-stats-grid">
                    <div class="admin-stat-card admin-stat-card--static">
                        <span class="admin-stat-value">{{ system.memory_used_mb }}</span>
                        <span class="admin-stat-label">Память PHP, МБ</span>
                    </div>
                    <div class="admin-stat-card admin-stat-card--static">
                        <span class="admin-stat-value">{{ system.memory_peak_mb }}</span>
                        <span class="admin-stat-label">Пик памяти, МБ</span>
                    </div>
                    <div class="admin-stat-card admin-stat-card--static">
                        <span class="admin-stat-value admin-stat-value--sm">{{ system.memory_limit || '—' }}</span>
                        <span class="admin-stat-label">Лимит PHP</span>
                    </div>
                    <div class="admin-stat-card admin-stat-card--static">
                        <span class="admin-stat-value admin-stat-value--sm">{{ formatLoad(system.load_avg) }}</span>
                        <span class="admin-stat-label">Load avg (1/5/15)</span>
                    </div>
                    <div class="admin-stat-card admin-stat-card--static">
                        <span class="admin-stat-value">{{ system.queue_pending }}</span>
                        <span class="admin-stat-label">Задач в очереди</span>
                    </div>
                    <div class="admin-stat-card admin-stat-card--static">
                        <span class="admin-stat-value">{{ system.queue_failed }}</span>
                        <span class="admin-stat-label">Ошибок очереди</span>
                    </div>
                </div>

                <ul class="admin-system-meta">
                    <li><span>Диск (storage)</span><strong>{{ diskLabel(system.disk_free_gb, system.disk_total_gb) }}</strong></li>
                    <li><span>Кэш</span><strong>{{ system.cache_driver }}</strong></li>
                    <li><span>Очередь</span><strong>{{ system.queue_driver }}</strong></li>
                    <li><span>PHP</span><strong>{{ system.php_version }}</strong></li>
                    <li><span>Окружение</span><strong>{{ system.app_env }}</strong></li>
                </ul>
            </div>
        </template>
    </section>
</template>
