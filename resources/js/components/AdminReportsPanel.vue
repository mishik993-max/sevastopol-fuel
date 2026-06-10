<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiUrl } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'refresh', 'saved']);

const reports = ref([]);
const loading = ref(false);
const filter = ref('all');
const notice = ref(null);

const filteredReports = computed(() => {
    if (filter.value === 'visible') {
        return reports.value.filter((item) => !item.is_hidden);
    }

    if (filter.value === 'hidden') {
        return reports.value.filter((item) => item.is_hidden);
    }

    return reports.value;
});

const counts = computed(() => ({
    all: reports.value.length,
    visible: reports.value.filter((item) => !item.is_hidden).length,
    hidden: reports.value.filter((item) => item.is_hidden).length,
}));

async function apiFetch(url, options = {}) {
    const res = await fetch(apiUrl(url), {
        ...options,
        headers: { ...props.authHeaders(), ...options.headers },
    });
    const json = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new Error(json.message || 'Ошибка запроса');
    }

    return json;
}

async function load() {
    loading.value = true;

    try {
        const json = await apiFetch('/api/admin/reports');
        reports.value = json.data;
    } catch (e) {
        emit('error', e.message);
    } finally {
        loading.value = false;
    }
}

async function toggleHidden(item, hide) {
    loading.value = true;
    notice.value = null;

    try {
        const json = await apiFetch(`/api/admin/reports/${item.id}/${hide ? 'hide' : 'unhide'}`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        reports.value = json.data;
        notice.value = json.message;
        emit('saved', json.message);
        emit('refresh');
    } catch (e) {
        emit('error', e.message);
    } finally {
        loading.value = false;
    }
}

async function remove(item) {
    const label = `${item.station_network} · ${item.station_name} · ${item.fuel_label}`;
    const ok = window.confirm(`Удалить отчёт безвозвратно?\n\n${label}\n${item.created_at}`);

    if (!ok) {
        return;
    }

    loading.value = true;
    notice.value = null;

    try {
        const json = await apiFetch(`/api/admin/reports/${item.id}`, {
            method: 'DELETE',
        });
        reports.value = json.data;
        notice.value = json.message;
        emit('saved', json.message);
        emit('refresh');
    } catch (e) {
        emit('error', e.message);
    } finally {
        loading.value = false;
    }
}

function metaLine(item) {
    const parts = [];

    if (item.is_confirmation) {
        parts.push('Подтверждение');
    }

    if (item.sale_type_labels?.length) {
        parts.push(...item.sale_type_labels);
    }

    if (item.fill_volume_label) {
        parts.push(item.fill_volume_label);
    }

    if (item.canister_policy_label) {
        parts.push(item.canister_policy_label);
    }

    if (item.queue_label && item.queue_label !== 'Очереди нет' && item.queue_label !== 'Не знаю') {
        parts.push(`Очередь: ${item.queue_label}`);
    }

    return parts.join(' · ');
}

onMounted(load);
</script>

<template>
    <section class="admin-section admin-reports-section">
        <div class="admin-reports-head">
            <div>
                <h2>
                    Отчёты пользователей
                    <span class="admin-count">{{ counts.all }} всего</span>
                </h2>
                <p class="hint">
                    Скрытие убирает отчёт с карты. Удаление безвозвратно (вместе с фото).
                </p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="loading" @click="load">
                Обновить
            </button>
        </div>

        <p v-if="notice" class="admin-notice">{{ notice }}</p>

        <div class="admin-reports-filters">
            <button
                type="button"
                class="admin-reports-filter"
                :class="{ active: filter === 'all' }"
                @click="filter = 'all'"
            >
                Все ({{ counts.all }})
            </button>
            <button
                type="button"
                class="admin-reports-filter"
                :class="{ active: filter === 'visible' }"
                @click="filter = 'visible'"
            >
                Видимые ({{ counts.visible }})
            </button>
            <button
                type="button"
                class="admin-reports-filter"
                :class="{ active: filter === 'hidden' }"
                @click="filter = 'hidden'"
            >
                Скрытые ({{ counts.hidden }})
            </button>
        </div>

        <p v-if="!loading && !filteredReports.length" class="hint">Нет отчётов в этой вкладке</p>

        <div v-else class="admin-reports-table-wrap">
            <table class="admin-reports-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>АЗС</th>
                        <th>Топливо</th>
                        <th>Статус</th>
                        <th>Детали</th>
                        <th>Фото</th>
                        <th aria-label="Действия" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in filteredReports"
                        :key="item.id"
                        class="admin-reports-row"
                        :class="{ 'admin-reports-row--hidden': item.is_hidden }"
                    >
                        <td class="admin-reports-date" data-label="Дата">{{ item.created_at }}</td>
                        <td class="admin-reports-station" data-label="АЗС">
                            <span class="admin-reports-station-network">{{ item.station_network }}</span>
                            <span class="admin-reports-station-name">{{ item.station_name }}</span>
                            <span v-if="item.is_hidden" class="admin-reports-badge">скрыт</span>
                        </td>
                        <td data-label="Топливо">{{ item.fuel_label }}</td>
                        <td data-label="Статус">
                            <span v-if="item.is_confirmation" class="admin-reports-badge admin-reports-badge--confirm">✓</span>
                            {{ item.status_label }}
                        </td>
                        <td class="admin-reports-details" data-label="Детали">
                            <span v-if="metaLine(item)">{{ metaLine(item) }}</span>
                            <span v-else class="admin-reports-muted">-</span>
                            <p v-if="item.comment" class="admin-reports-comment">{{ item.comment }}</p>
                        </td>
                        <td data-label="Фото">
                            <a
                                v-if="item.photo_url"
                                :href="item.photo_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="admin-reports-photo-link"
                            >
                                <img
                                    :src="item.photo_url"
                                    alt=""
                                    class="admin-reports-photo-thumb"
                                    loading="lazy"
                                />
                            </a>
                            <span v-else class="admin-reports-muted">-</span>
                        </td>
                        <td class="admin-reports-actions-cell">
                            <div class="admin-reports-actions">
                                <button
                                    v-if="!item.is_hidden"
                                    type="button"
                                    class="btn btn-secondary btn-sm"
                                    :disabled="loading"
                                    @click="toggleHidden(item, true)"
                                >
                                    Скрыть
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    :disabled="loading"
                                    @click="toggleHidden(item, false)"
                                >
                                    Вернуть
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    :disabled="loading"
                                    @click="remove(item)"
                                >
                                    Удалить
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
