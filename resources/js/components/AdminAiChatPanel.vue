<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';
import AdminAiImportRow from './AdminAiImportRow.vue';

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
const lastParse = ref(null);
const aiDebugOpen = ref(true);
const aiDebugTab = ref('request');
const queueRows = ref([]);
const highlightedQueueIds = ref([]);

const queueSelectedCount = computed(() => queueRows.value.filter((row) => row.selected && row.station_id).length);
const queueReportCount = computed(() => queueRows.value
    .filter((row) => row.selected && row.station_id)
    .reduce((sum, row) => sum + row.fuels.length, 0));

const queueMatchedCount = computed(() => queueRows.value.filter((row) => row.station_id).length);

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
    lastParse.value = null;
    highlightedQueueIds.value = [];
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/ai-chat/parse'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({ message: message.value.trim() }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка разбора');

        lastParse.value = json.data;
        aiDebugOpen.value = true;
        aiDebugTab.value = 'request';

        highlightedQueueIds.value = [
            ...(json.data.items || []),
            ...(json.data.unmatched || []),
        ].map((item) => item.queue_id).filter(Boolean);

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

function formatJson(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value ?? '');
    }
}

async function applyQueue() {
    const selected = queueRows.value.filter((row) => row.selected && row.station_id);

    const items = selected.map((row) => ({
        station_id: row.station_id,
        fuels: row.fuels.map((fuel) => ({
            fuel_type: fuel.fuel_type,
            statuses: fuel.statuses,
            sale_types: fuel.sale_types,
            queue_size: fuel.queue_size,
            comment: fuel.comment,
        })),
    }));

    if (!items.length) {
        emit('error', 'Выберите хотя бы одну сопоставленную АЗС');

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

        lastParse.value = null;
        highlightedQueueIds.value = [];
        message.value = '';
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

        highlightedQueueIds.value = highlightedQueueIds.value.filter((id) => id !== queueId);
        await loadQueue();
    } catch (e) {
        emit('error', e.message);
    } finally {
        applying.value = false;
    }
}

function isHighlighted(queueId) {
    return highlightedQueueIds.value.includes(queueId);
}

onMounted(async () => {
    await loadStatus();
    await loadQueue();
});
</script>

<template>
    <section class="admin-section admin-ai-section">
        <div class="admin-ai-hero">
            <div>
                <h2>AI-импорт топлива</h2>
                <p class="admin-ai-lead">
                    Вставьте текст из Telegram — нейросеть разберёт список один раз.
                    Строки сохраняются в очереди и сопоставляются без повторного запроса к AI.
                </p>
            </div>
            <div class="admin-ai-hero-badges">
                <span v-if="model" class="admin-ai-pill">{{ model }}</span>
                <span v-if="queueRows.length" class="admin-ai-pill admin-ai-pill--accent">
                    Очередь: {{ queueRows.length }}
                </span>
            </div>
        </div>

        <p v-if="!configured" class="admin-alert">
            Задайте <code>TIMEWEB_AI_API_KEY</code> в <code>.env</code> и перезапустите приложение.
        </p>

        <div class="admin-ai-layout">
            <div class="admin-ai-col admin-ai-col--input">
                <div class="admin-ai-panel">
                    <div class="admin-ai-panel-head">
                        <strong>1. Текст сообщения</strong>
                    </div>

                    <textarea
                        v-model="message"
                        class="field-textarea admin-ai-textarea"
                        rows="12"
                        placeholder="Вставьте сообщение о продаже топлива из Telegram…"
                        :disabled="!configured || parsing || applying"
                    />

                    <div class="admin-ai-panel-actions">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!configured || parsing || applying || !message.trim()"
                            @click="parseMessage"
                        >
                            {{ parsing ? 'Разбор…' : 'Разобрать через AI' }}
                        </button>
                    </div>
                </div>

                <div v-if="lastParse" class="admin-ai-panel admin-ai-panel--result">
                    <div class="admin-ai-panel-head">
                        <strong>2. Результат разбора</strong>
                        <span v-if="lastParse.parse_stats" class="admin-ai-panel-meta">
                            {{ lastParse.parse_stats.matched }} сопоставлено ·
                            {{ lastParse.parse_stats.unmatched }} без совпадения
                        </span>
                    </div>

                    <p v-if="lastParse.summary" class="admin-ai-summary">{{ lastParse.summary }}</p>

                    <ul v-if="lastParse.network_notes?.length" class="admin-ai-notes">
                        <li v-for="(note, index) in lastParse.network_notes" :key="index">{{ note }}</li>
                    </ul>

                    <p class="admin-ai-result-hint">
                        Все строки добавлены в очередь справа. Проверьте сопоставление и нажмите «Применить».
                    </p>
                </div>

                <div v-if="lastParse?.ai_debug" class="admin-ai-panel admin-ai-panel--debug">
                    <button
                        type="button"
                        class="admin-ai-debug-toggle"
                        :aria-expanded="aiDebugOpen"
                        @click="aiDebugOpen = !aiDebugOpen"
                    >
                        <strong>Журнал нейросети</strong>
                        <span class="admin-ai-panel-meta">
                            {{ lastParse.ai_debug.duration_ms }} мс
                        </span>
                        <span class="admin-ai-debug-chevron">{{ aiDebugOpen ? '▾' : '▸' }}</span>
                    </button>

                    <div v-show="aiDebugOpen" class="admin-ai-debug-body">
                        <div class="admin-ai-debug-tabs" role="tablist">
                            <button
                                type="button"
                                class="admin-ai-debug-tab"
                                :class="{ active: aiDebugTab === 'request' }"
                                @click="aiDebugTab = 'request'"
                            >
                                Запрос
                            </button>
                            <button
                                type="button"
                                class="admin-ai-debug-tab"
                                :class="{ active: aiDebugTab === 'response' }"
                                @click="aiDebugTab = 'response'"
                            >
                                Ответ JSON
                            </button>
                            <button
                                type="button"
                                class="admin-ai-debug-tab"
                                :class="{ active: aiDebugTab === 'parsed' }"
                                @click="aiDebugTab = 'parsed'"
                            >
                                Разбор
                            </button>
                        </div>

                        <div v-if="aiDebugTab === 'request'" class="admin-ai-debug-pane">
                            <p class="admin-ai-debug-label">Модель</p>
                            <pre class="admin-ai-code">{{ lastParse.ai_debug.model }}</pre>

                            <p class="admin-ai-debug-label">System prompt</p>
                            <pre class="admin-ai-code admin-ai-code--scroll">{{ lastParse.ai_debug.system_prompt }}</pre>

                            <p class="admin-ai-debug-label">User message</p>
                            <pre class="admin-ai-code admin-ai-code--scroll">{{ lastParse.ai_debug.user_message }}</pre>
                        </div>

                        <div v-else-if="aiDebugTab === 'response'" class="admin-ai-debug-pane">
                            <pre class="admin-ai-code admin-ai-code--scroll">{{ lastParse.ai_debug.response_raw }}</pre>
                        </div>

                        <div v-else class="admin-ai-debug-pane">
                            <pre class="admin-ai-code admin-ai-code--scroll">{{ formatJson(lastParse.ai_debug.response_parsed) }}</pre>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-ai-col admin-ai-col--queue">
                <div class="admin-ai-panel admin-ai-panel--queue">
                    <div class="admin-ai-panel-head">
                        <strong>Очередь импорта</strong>
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            :disabled="loadingQueue || applying"
                            @click="loadQueue"
                        >
                            {{ loadingQueue ? '…' : 'Обновить' }}
                        </button>
                    </div>

                    <p class="admin-ai-queue-meta">
                        {{ queueMatchedCount }} из {{ queueRows.length }} сопоставлено ·
                        {{ queueReportCount }} отчётов выбрано
                    </p>

                    <p v-if="!queueRows.length && !loadingQueue" class="admin-ai-queue-empty">
                        Очередь пуста. Разберите сообщение слева — строки появятся здесь.
                    </p>

                    <div v-else class="admin-ai-card-list">
                        <AdminAiImportRow
                            v-for="row in queueRows"
                            :key="row.queue_id"
                            :row="row"
                            :applying="applying"
                            :highlight="isHighlighted(row.queue_id)"
                            show-remove
                            @remove="removeFromQueue"
                        />
                    </div>
                </div>

                <div v-if="queueRows.length" class="admin-ai-apply-bar">
                    <div class="admin-ai-apply-meta">
                        <strong>{{ queueSelectedCount }} АЗС</strong>
                        <span>{{ queueReportCount }} отчётов</span>
                    </div>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="applying || queueSelectedCount === 0"
                        @click="applyQueue"
                    >
                        {{ applying ? 'Применение…' : 'Применить выбранное' }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
