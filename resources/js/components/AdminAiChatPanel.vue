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
const preview = ref(null);
const rows = ref([]);

const selectedCount = computed(() => rows.value.filter((row) => row.selected && row.station_id).length);
const reportCount = computed(() => rows.value
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

function fuelsLabel(fuels) {
    return fuels.map((fuel) => `${fuel.fuel_label} (${fuel.status_label})`).join(', ');
}

function saleTypesLabel(fuels) {
    const labels = [...new Set(fuels.flatMap((fuel) => fuel.sale_types_labels || []))];

    return labels.join(', ') || '—';
}

async function applyPreview() {
    const items = rows.value
        .filter((row) => row.selected && row.station_id)
        .map((row) => ({
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

    const ok = window.confirm(`Создать ${reportCount.value} отчётов для ${items.length} АЗС?`);

    if (!ok) return;

    applying.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/ai-chat/apply'), {
            method: 'POST',
            headers: props.authHeaders(),
            body: JSON.stringify({ items }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка применения');

        preview.value = null;
        rows.value = [];
        message.value = '';
        emit('saved', json.message);
    } catch (e) {
        emit('error', e.message);
    } finally {
        applying.value = false;
    }
}

onMounted(loadStatus);
</script>

<template>
    <section class="admin-section admin-ai-section">
        <div class="admin-section-head">
            <div>
                <h2>AI-импорт топлива</h2>
                <p class="hint admin-analytics-lead">
                    Вставьте текст из Telegram или чата — нейросеть разберёт список АЗС,
                    сопоставит с базой, вы создадите отчёты одним нажатием.
                </p>
            </div>
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
                {{ parsing ? 'Разбор…' : 'Разобрать' }}
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
                    <strong>Результат сопоставления</strong>
                    <span class="hint">{{ selectedCount }} АЗС · {{ reportCount }} отчётов</span>
                </div>

                <div class="admin-ai-rows">
                    <article
                        v-for="row in rows"
                        :key="row.index"
                        class="admin-ai-row"
                        :class="{ 'admin-ai-row--unmatched': !row.station_id || row.confidence === null }"
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

                            <p v-else-if="row.station_label" class="admin-ai-row-match">
                                {{ row.station_label }}
                                <span v-if="row.confidence" class="admin-ai-confidence">{{ row.confidence }}%</span>
                            </p>
                            <p v-else class="admin-ai-row-warn">Не найдено совпадение в базе</p>
                        </div>
                    </article>
                </div>

                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="applying || selectedCount === 0"
                    @click="applyPreview"
                >
                    {{ applying ? 'Применение…' : `Применить (${reportCount})` }}
                </button>
            </div>
        </template>
    </section>
</template>
