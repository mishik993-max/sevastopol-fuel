<script setup>
import { ref } from 'vue';
import { apiUrl } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'done']);

const loading = ref(false);
const applying = ref(false);
const preview = ref(null);
const applyResult = ref(null);

async function loadPreview() {
    loading.value = true;
    preview.value = null;
    applyResult.value = null;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/osm-import/preview'), {
            headers: props.authHeaders(),
        });
        const json = await res.json();

        if (res.status === 429) {
            throw new Error('Слишком частые запросы - подождите до 30 минут и попробуйте снова');
        }

        if (!res.ok) throw new Error(json.message || 'Ошибка превью');

        preview.value = json.data;
    } catch (e) {
        emit('error', e.message);
    } finally {
        loading.value = false;
    }
}

async function applyImport(runSync) {
    if (!preview.value?.apply_token) return;

    const ok = window.confirm(
        runSync
            ? 'Применить импорт и запустить полную проверку закрытых OSM-станций? Это может занять несколько минут.'
            : 'Применить импорт без полной проверки закрытия?',
    );

    if (!ok) return;

    applying.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/osm-import/run'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({
                apply_token: preview.value.apply_token,
                run_sync: runSync,
            }),
        });
        const json = await res.json();

        if (res.status === 429) {
            throw new Error('Слишком частые запросы - подождите до 30 минут');
        }

        if (!res.ok) throw new Error(json.message || 'Ошибка импорта');

        applyResult.value = json.data;
        preview.value = null;
        emit('done', json.message);
    } catch (e) {
        emit('error', e.message);
    } finally {
        applying.value = false;
    }
}

function fieldLabel(field) {
    return {
        name: 'Название',
        network: 'Сеть',
        address: 'Адрес',
        latitude: 'Широта',
        longitude: 'Долгота',
    }[field] ?? field;
}
</script>

<template>
    <section class="admin-section">
        <h2>Импорт АЗС из OpenStreetMap</h2>
        <p class="hint">
            Автообновление по расписанию отключено. Загрузите превью, проверьте изменения и примените вручную.
            Сбор данных через Nominatim занимает 1-2 минуты.
        </p>

        <div class="admin-item-actions admin-osm-actions">
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="loading || applying"
                @click="loadPreview"
            >
                {{ loading ? 'Загрузка превью…' : 'Загрузить превью' }}
            </button>
        </div>

        <div v-if="preview" class="admin-osm-preview">
            <div class="admin-stats-grid admin-osm-summary">
                <div class="admin-stat-card admin-stat-card--static">
                    <span class="admin-stat-value">{{ preview.summary.new }}</span>
                    <span class="admin-stat-label">Новых</span>
                </div>
                <div class="admin-stat-card admin-stat-card--static">
                    <span class="admin-stat-value">{{ preview.summary.updated }}</span>
                    <span class="admin-stat-label">Изменённых</span>
                </div>
                <div class="admin-stat-card admin-stat-card--static">
                    <span class="admin-stat-value">{{ preview.summary.would_deactivate }}</span>
                    <span class="admin-stat-label">Отключить</span>
                </div>
                <div class="admin-stat-card admin-stat-card--static">
                    <span class="admin-stat-value">{{ preview.summary.would_reactivate }}</span>
                    <span class="admin-stat-label">Включить снова</span>
                </div>
            </div>

            <p class="hint">
                Всего элементов: {{ preview.summary.total_elements }},
                без изменений: {{ preview.summary.unchanged }},
                пропущено: {{ preview.summary.skipped }}.
                <span v-if="preview.note"> {{ preview.note }}</span>
            </p>

            <div v-if="preview.new.length" class="admin-osm-block">
                <h3>Новые АЗС ({{ preview.new.length }})</h3>
                <ul class="admin-osm-list">
                    <li v-for="item in preview.new.slice(0, 30)" :key="item.external_id">
                        <strong>{{ item.network }}</strong> - {{ item.name }}
                        <span class="admin-item-meta">{{ item.address }}</span>
                        <span v-if="item.note" class="admin-osm-warn">{{ item.note }}</span>
                    </li>
                </ul>
                <p v-if="preview.new.length > 30" class="hint">…и ещё {{ preview.new.length - 30 }}</p>
            </div>

            <div v-if="preview.updated.length" class="admin-osm-block">
                <h3>Изменения ({{ preview.updated.length }})</h3>
                <article v-for="item in preview.updated.slice(0, 20)" :key="item.id" class="admin-item admin-item--compact">
                    <p class="admin-item-title">{{ item.network }} - {{ item.name }}</p>
                    <p v-for="(ch, i) in item.changes" :key="i" class="admin-item-text">
                        {{ fieldLabel(ch.field) }}: «{{ ch.from }}» → «{{ ch.to }}»
                    </p>
                </article>
            </div>

            <div v-if="preview.would_deactivate.length" class="admin-osm-block">
                <h3>Будут отключены ({{ preview.would_deactivate.length }})</h3>
                <ul class="admin-osm-list">
                    <li v-for="item in preview.would_deactivate" :key="item.id">
                        {{ item.network }} - {{ item.name }}
                        <span class="admin-osm-warn">{{ item.reason }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="preview.would_reactivate.length" class="admin-osm-block">
                <h3>Снова появятся на карте ({{ preview.would_reactivate.length }})</h3>
                <ul class="admin-osm-list">
                    <li v-for="item in preview.would_reactivate" :key="item.id">
                        {{ item.network }} - {{ item.name }}
                    </li>
                </ul>
            </div>

            <div class="admin-item-actions">
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="applying"
                    @click="applyImport(false)"
                >
                    {{ applying ? 'Применение…' : 'Применить импорт' }}
                </button>
                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :disabled="applying"
                    @click="applyImport(true)"
                >
                    Импорт + полная проверка закрытия
                </button>
            </div>
        </div>

        <div v-if="applyResult" class="admin-notice admin-osm-result">
            Импорт: +{{ applyResult.import.imported }}, обновлено {{ applyResult.import.updated }},
            пропущено {{ applyResult.import.skipped }}.
            <span v-if="applyResult.sync">
                Sync: проверено {{ applyResult.sync.checked }}, отключено {{ applyResult.sync.deactivated }},
                включено {{ applyResult.sync.reactivated }}.
            </span>
        </div>
    </section>
</template>
