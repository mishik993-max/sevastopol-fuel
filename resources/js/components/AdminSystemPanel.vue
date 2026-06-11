<script setup>
import { onMounted, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error']);

const loading = ref(false);
const system = ref(null);

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
        const res = await fetch(apiUrl('/api/admin/system'), { headers: props.authHeaders() });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        system.value = json.data || null;
    } catch (e) {
        emit('error', e.message);
        system.value = null;
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
                <h2>Нагрузка на систему</h2>
                <p class="hint admin-analytics-lead">
                    Снимок сервера в момент запроса. Load average доступен на Linux.
                </p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="loading" @click="load">
                {{ loading ? '…' : 'Обновить' }}
            </button>
        </div>

        <p v-if="loading && !system" class="hint">Загрузка…</p>

        <template v-if="system">
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
                <li>
                    <span>БД приложения</span>
                    <strong>{{ system.database_connection_label }}</strong>
                </li>
                <li>
                    <span>Кэш</span>
                    <strong>{{ system.cache_driver_label }}</strong>
                </li>
                <li>
                    <span>Очередь</span>
                    <strong>{{ system.queue_driver_label }}</strong>
                </li>
                <li><span>PHP</span><strong>{{ system.php_version }}</strong></li>
                <li><span>Окружение</span><strong>{{ system.app_env }}</strong></li>
            </ul>

            <p
                v-if="system.cache_driver === 'database' || system.queue_driver === 'database'"
                class="admin-overview-note admin-system-note"
            >
                «database» в настройках Laravel — это не имя базы данных, а способ хранения:
                кэш и очередь лежат в таблицах MySQL (<code>cache</code>, <code>jobs</code>),
                задано в <code>.env</code> как <code>CACHE_STORE=database</code> и
                <code>QUEUE_CONNECTION=database</code>.
            </p>
        </template>
    </section>
</template>
