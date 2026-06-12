<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'saved']);

const configured = ref(false);
const model = ref('');
const message = ref('');
const parsing = ref(false);
const applying = ref(false);
const loadingQueue = ref(false);
const preview = ref(null);
const rows = ref([]);
const queueRows = ref([]);

const selectedCount = computed(() => rows.value.filter((row) => row.selected && row.station_id).length);
const reportCount = computed(() => rows.value
    .filter((row) => row.selected && row.station_id)
    .reduce((sum, row) => sum + row.fuels.length, 0));

const queueSelectedCount = computed(() => queueRows.value.filter((row) => row.selected && row.station_id).length);
const queueReportCount = computed(() => queueRows.value
    .filter((row) => row.selected && row.station_id)
    .reduce((sum, row) => sum + row.fuels.length, 0));

async function loadStatus() {
    try {
        const res = await fetch(apiUrl('/api/admin/ai-chat/status'), { headers: props.authHeaders() });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка');

        configured.value = Boolean(json.data?.configured);
        model.value = json.data?.model || '';
    } catch (e) {
        emit('error', e.message);
    }
}

async function loadQueue() {
    loadingQueue.value = true;

    try {
        const res = await fetch(apiUrl('/api/admin/ai-chat/queue'), { headers: props.authHeaders() });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки очереди');

        queueRows.value = (json.data || []).map((item) => normalizeRow(item, Boolean(item.station_id)));
    } catch (e) {
        emit('error', e.message);
    } finally {
        loadingQueue.value = false;
    }
}

async function parseMessage() {
    if (!message.value.trim()) return;

    parsing.value = true;
    preview.value = null;
    rows.value = [];
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/ai-chat/parse'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({ message: message.value.trim() }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка разбора');

        preview.value = json.data;
        rows.value = [
            ...(json.data.items || []).map((item) => normalizeRow(item, true)),
            ...(json.data.unmatched || []).map((item) => normalizeRow(item, false)),
        ];
        await loadQueue();
    } catch (e) {
        emit('error', e.message);
    } finally {
        parsing.value = false;
    }
}

function normalizeRow(item, selectedDefault) {
    const firstCandidate = item.candidates?.[0]?.station_id ?? null;

    return {
        ...item,
        selected: selectedDefault && Boolean(item.station_id),
        station_id: item.station_id ?? firstCandidate,
    };
}

function selectedCandidate(row) {
    if (!row.station_id) {
        return null;
    }

    return row.candidates?.find((candidate) => candidate.station_id === row.station_id) ?? {
        station_id: row.station_id,
        label: row.station_label,
        address: row.station_address,
        score: row.confidence,
        map_url: mapUrl(row.station_id),
    };
}

function mapUrl(stationId) {
    return stationId ? `/?station=${stationId}` : '#';
}

function confidenceClass(score) {
    if (score == null) return '';
    if (score >= 75) return 'admin-ai-confidence--good';
    if (score >= 50) return 'admin-ai-confidence--ok';

    return 'admin-ai-confidence--low';
}

function fuelsLabel(fuels) {
    return fuels.map((fuel) => `${fuel.fuel_label} (${fuel.status_label})`).join(', ');
}

function saleTypesLabel(fuels) {
    const labels = [...new Set(fuels.flatMap((fuel) => fuel.sale_types_labels || []))];

    return labels.join(', ') || '—';
}

function formatQueuedAt(iso) {
    if (!iso) return null;

    return new Date(iso).toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function applyRows(sourceRows, { clearPreview = false } = {}) {
    const selected = sourceRows.filter((row) => row.selected && row.station_id);

    const items = selected.map((row) => ({
        station_id: row.station_id,
        fuels: row.fuels.map((fuel) => ({
            fuel_type: fuel.fuel_type,
            statuses: fuel.statuses,
            sale_types: fuel.sale_types,
            comment: fuel.comment,
        })),
    }));

    if (!items.length) {
        emit('error', 'Выберите хотя бы одну АЗС для импорта');

        return;
    }

    const reportTotal = selected.reduce((sum, row) => sum + row.fuels.length, 0);
    const queueIds = selected.map((row) => row.queue_id).filter(Boolean);

    const ok = window.confirm(`Создать ${reportTotal} отчётов для ${items.length} АЗС?`);

    if (!ok) return;

    applying.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/ai-chat/apply'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({ items, queue_ids: queueIds }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка применения');

        if (clearPreview) {
            preview.value = null;
            rows.value = [];
            message.value = '';
        }

        await loadQueue();
        emit('saved', json.message);
    } catch (e) {
        emit('error', e.message);
    } finally {
        applying.value = false;
    }
}

async function removeFromQueue(queueId) {
    applying.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl(`/api/admin/ai-chat/queue/${queueId}`), {
            method: 'DELETE',
            headers: props.authHeaders(),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка удаления');

        await loadQueue();
    } catch (e) {
        emit('error', e.message);
    } finally {
        applying.value = false;
    }
}

onMounted(async () => {
    await loadStatus();
    await loadQueue();
});
</script>

<template>
    <section class="admin-section admin-ai-section">
        <div class="admin-section-head">
            <div>
                <h2>AI-импорт топлива</h2>
                <p class="hint admin-analytics-lead">
                    Вставьте текст из Telegram — нейросеть разберёт список один раз.
                    Неимпортированные строки сохраняются в очереди без повторного запроса к AI.
                </p>
            </div>
        </div>

        <div v-if="queueRows.length" class="admin-ai-queue">
            <div class="admin-ai-preview-head">
                <strong>Ожидают импорта ({{ queueRows.length }})</strong>
                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :disabled="loadingQueue || applying"
                    @click="loadQueue"
                >
                    {{ loadingQueue ? '…' : 'Обновить' }}
                </button>
            </div>

            <p class="hint admin-ai-preview-hint">
                Сопоставление обновляется без нейросети. Выберите АЗС, проверьте на карте и примените.
            </p>

            <div class="admin-ai-rows">
                <article
                    v-for="row in queueRows"
                    :key="row.queue_id"
                    class="admin-ai-row"
                    :class="{
                        'admin-ai-row--low': selectedCandidate(row)?.score != null && selectedCandidate(row).score < 50,
                    }"
                >
                    <label class="admin-ai-row-check">
                        <input
                            v-model="row.selected"
                            type="checkbox"
                            :disabled="!row.station_id || applying"
                        />
                    </label>

                    <div class="admin-ai-row-body">
                        <p class="admin-ai-row-source">{{ row.raw }}</p>
                        <p v-if="formatQueuedAt(row.queued_at)" class="admin-ai-row-queued hint">
                            В очереди с {{ formatQueuedAt(row.queued_at) }}
                        </p>
                        <p class="admin-ai-row-fuels">{{ fuelsLabel(row.fuels) }}</p>
                        <p class="admin-ai-row-meta hint">Продажа: {{ saleTypesLabel(row.fuels) }}</p>

                        <label v-if="row.candidates?.length" class="field admin-ai-row-select">
                            АЗС в базе
                            <select v-model.number="row.station_id" class="field-input" :disabled="applying">
                                <option :value="null">— выберите —</option>
                                <option
                                    v-for="candidate in row.candidates"
                                    :key="candidate.station_id"
                                    :value="candidate.station_id"
                                >
                                    {{ candidate.label }} ({{ candidate.score }}%)
                                </option>
                            </select>
                        </label>

                        <div v-if="selectedCandidate(row)" class="admin-ai-match-card">
                            <p class="admin-ai-row-match">
                                {{ selectedCandidate(row).label }}
                                <span
                                    class="admin-ai-confidence"
                                    :class="confidenceClass(selectedCandidate(row).score)"
                                >
                                    {{ selectedCandidate(row).score }}%
                                </span>
                            </p>
                            <p v-if="selectedCandidate(row).address" class="admin-ai-row-address hint">
                                {{ selectedCandidate(row).address }}
                            </p>
                            <a
                                :href="selectedCandidate(row).map_url || mapUrl(row.station_id)"
                                class="admin-ai-map-link"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                На карте ↗
                            </a>
                        </div>

                        <p v-else class="admin-ai-row-warn">Не найдено совпадение в базе</p>

                        <button
                            type="button"
                            class="btn btn-ghost btn-sm admin-ai-remove"
                            :disabled="applying"
                            @click="removeFromQueue(row.queue_id)"
                        >
                            Убрать из очереди
                        </button>
                    </div>
                </article>
            </div>

            <button
                type="button"
                class="btn btn-primary"
                :disabled="applying || queueSelectedCount === 0"
                @click="applyRows(queueRows)"
            >
                {{ applying ? 'Применение…' : `Применить из очереди (${queueReportCount})` }}
            </button>
        </div>

        <p v-if="!configured" class="admin-alert">
            Задайте <code>TIMEWEB_AI_API_KEY</code> в <code>.env</code> и перезапустите приложение.
        </p>
        <p v-else class="admin-ai-model hint">Модель: {{ model }}</p>

        <label class="field admin-ai-input">
            Текст сообщения
            <textarea
                v-model="message"
                class="field-textarea admin-ai-textarea"
                rows="10"
                placeholder="Вставьте сообщение о продаже топлива…"
                :disabled="!configured || parsing || applying"
            />
        </label>

        <div class="admin-ai-actions">
            <button
                type="button"
                class="btn btn-primary"
                :disabled="!configured || parsing || applying || !message.trim()"
                @click="parseMessage"
            >
                {{ parsing ? 'Разбор…' : 'Разобрать через AI' }}
            </button>
        </div>

        <template v-if="preview">
            <div v-if="preview.summary" class="admin-ai-summary">
                {{ preview.summary }}
            </div>

            <ul v-if="preview.network_notes?.length" class="admin-ai-notes">
                <li v-for="(note, index) in preview.network_notes" :key="index">{{ note }}</li>
            </ul>

            <p v-if="!rows.length" class="hint">AI не нашёл АЗС в тексте</p>

            <div v-else class="admin-ai-preview">
                <div class="admin-ai-preview-head">
                    <strong>Новый разбор</strong>
                    <span class="hint">{{ selectedCount }} АЗС · {{ reportCount }} отчётов</span>
                </div>

                <p class="hint admin-ai-preview-hint">
                    Все строки сохранены в очередь. Можно применить сейчас или вернуться позже без AI.
                </p>

                <div class="admin-ai-rows">
                    <article
                        v-for="row in rows"
                        :key="row.queue_id || row.index"
                        class="admin-ai-row"
                        :class="{
                            'admin-ai-row--low': selectedCandidate(row)?.score != null && selectedCandidate(row).score < 50,
                        }"
                    >
                        <label class="admin-ai-row-check">
                            <input
                                v-model="row.selected"
                                type="checkbox"
                                :disabled="!row.station_id || applying"
                            />
                        </label>

                        <div class="admin-ai-row-body">
                            <p class="admin-ai-row-source">{{ row.raw }}</p>
                            <p class="admin-ai-row-fuels">{{ fuelsLabel(row.fuels) }}</p>
                            <p class="admin-ai-row-meta hint">Продажа: {{ saleTypesLabel(row.fuels) }}</p>

                            <label v-if="row.candidates?.length" class="field admin-ai-row-select">
                                АЗС в базе
                                <select v-model.number="row.station_id" class="field-input" :disabled="applying">
                                    <option :value="null">— выберите —</option>
                                    <option
                                        v-for="candidate in row.candidates"
                                        :key="candidate.station_id"
                                        :value="candidate.station_id"
                                    >
                                        {{ candidate.label }} ({{ candidate.score }}%)
                                    </option>
                                </select>
                            </label>

                            <div v-if="selectedCandidate(row)" class="admin-ai-match-card">
                                <p class="admin-ai-row-match">
                                    {{ selectedCandidate(row).label }}
                                    <span
                                        class="admin-ai-confidence"
                                        :class="confidenceClass(selectedCandidate(row).score)"
                                    >
                                        {{ selectedCandidate(row).score }}%
                                    </span>
                                </p>
                                <p v-if="selectedCandidate(row).address" class="admin-ai-row-address hint">
                                    {{ selectedCandidate(row).address }}
                                </p>
                                <a
                                    :href="selectedCandidate(row).map_url || mapUrl(row.station_id)"
                                    class="admin-ai-map-link"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    На карте ↗
                                </a>
                            </div>

                            <p v-else class="admin-ai-row-warn">Не найдено совпадение в базе</p>
                        </div>
                    </article>
                </div>

                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="applying || selectedCount === 0"
                    @click="applyRows(rows, { clearPreview: true })"
                >
                    {{ applying ? 'Применение…' : `Применить (${reportCount})` }}
                </button>
            </div>
        </template>
    </section>
</template>
