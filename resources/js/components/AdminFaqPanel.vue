<script setup>
import { onMounted, ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'saved']);

const items = ref([]);
const loading = ref(false);
const saving = ref(false);
const editingId = ref(null);
const form = ref(emptyForm());

function emptyForm() {
    return {
        question: '',
        answer: '',
        is_published: true,
    };
}

async function load() {
    loading.value = true;
    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/faq'), { headers: props.authHeaders() });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        items.value = json.data || [];
    } catch (e) {
        emit('error', e.message);
        items.value = [];
    } finally {
        loading.value = false;
    }
}

function startCreate() {
    editingId.value = 'new';
    form.value = emptyForm();
}

function startEdit(item) {
    editingId.value = item.id;
    form.value = {
        question: item.question,
        answer: item.answer,
        is_published: item.is_published,
    };
}

function cancelEdit() {
    editingId.value = null;
    form.value = emptyForm();
}

async function save() {
    saving.value = true;
    emit('error', null);

    const isNew = editingId.value === 'new';
    const url = isNew ? apiUrl('/api/admin/faq') : apiUrl(`/api/admin/faq/${editingId.value}`);
    const method = isNew ? 'POST' : 'PATCH';

    try {
        const res = await fetch(url, {
            method,
            headers: props.authHeaders(),
            body: JSON.stringify(form.value),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка сохранения');

        items.value = json.data || [];
        emit('saved', json.message || 'Сохранено');
        cancelEdit();
    } catch (e) {
        emit('error', e.message);
    } finally {
        saving.value = false;
    }
}

async function removeItem(item) {
    if (!window.confirm(`Удалить вопрос «${item.question}»?`)) {
        return;
    }

    emit('error', null);

    try {
        const res = await fetch(apiUrl(`/api/admin/faq/${item.id}`), {
            method: 'DELETE',
            headers: props.authHeaders(),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка удаления');

        items.value = json.data || [];
        emit('saved', json.message || 'Удалено');

        if (editingId.value === item.id) {
            cancelEdit();
        }
    } catch (e) {
        emit('error', e.message);
    }
}

async function togglePublished(item) {
    emit('error', null);

    try {
        const res = await fetch(apiUrl(`/api/admin/faq/${item.id}`), {
            method: 'PATCH',
            headers: props.authHeaders(),
            body: JSON.stringify({ is_published: !item.is_published }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка обновления');

        items.value = json.data || [];
    } catch (e) {
        emit('error', e.message);
    }
}

async function moveItem(index, direction) {
    const targetIndex = index + direction;

    if (targetIndex < 0 || targetIndex >= items.value.length) {
        return;
    }

    const reordered = [...items.value];
    const [moved] = reordered.splice(index, 1);
    reordered.splice(targetIndex, 0, moved);

    emit('error', null);

    try {
        const res = await fetch(apiUrl('/api/admin/faq/reorder'), {
            method: 'PATCH',
            headers: props.authHeaders(),
            body: JSON.stringify({ ids: reordered.map((item) => item.id) }),
        });
        const json = await parseApiResponse(res);

        if (!res.ok) throw new Error(json.message || 'Ошибка сортировки');

        items.value = json.data || [];
        emit('saved', 'Порядок сохранён');
    } catch (e) {
        emit('error', e.message);
    }
}

onMounted(load);
</script>

<template>
    <section class="admin-section">
        <div class="admin-section-head">
            <h2>Частые вопросы</h2>
            <button type="button" class="btn btn-accent btn-sm" @click="startCreate">
                + Добавить
            </button>
        </div>

        <p class="hint admin-faq-lead">
            Вопросы показываются в справочнике на сайте. Неопубликованные видны только здесь.
        </p>

        <form
            v-if="editingId !== null"
            class="admin-faq-form"
            @submit.prevent="save"
        >
            <label class="field">
                <span class="field-label">Вопрос</span>
                <input v-model="form.question" class="field-input" maxlength="300" required />
            </label>

            <label class="field">
                <span class="field-label">Ответ</span>
                <textarea
                    v-model="form.answer"
                    class="field-textarea"
                    rows="5"
                    maxlength="5000"
                    required
                />
            </label>

            <label class="field field--checkbox">
                <input v-model="form.is_published" type="checkbox" />
                <span>Показывать на сайте</span>
            </label>

            <div class="admin-item-actions">
                <button type="submit" class="btn btn-accent" :disabled="saving">
                    {{ saving ? 'Сохранение…' : (editingId === 'new' ? 'Добавить' : 'Сохранить') }}
                </button>
                <button type="button" class="btn btn-secondary" @click="cancelEdit">
                    Отмена
                </button>
            </div>
        </form>

        <p v-if="loading" class="hint">Загрузка…</p>
        <p v-else-if="!items.length" class="hint">Вопросов пока нет</p>

        <article
            v-for="(item, index) in items"
            :key="item.id"
            class="admin-item admin-item--compact"
            :class="{ 'admin-item--muted': !item.is_published }"
        >
            <div class="admin-item-head">
                <strong class="admin-item-title">{{ item.question }}</strong>
                <span class="admin-item-date">{{ item.updated_at }}</span>
            </div>

            <p class="admin-item-text admin-item-text--message">{{ item.answer }}</p>

            <p class="admin-item-meta">
                {{ item.is_published ? 'На сайте' : 'Скрыт' }}
            </p>

            <div class="admin-item-actions">
                <button type="button" class="btn btn-secondary btn-sm" @click="startEdit(item)">
                    Изменить
                </button>
                <button type="button" class="btn btn-secondary btn-sm" @click="togglePublished(item)">
                    {{ item.is_published ? 'Скрыть' : 'Показать' }}
                </button>
                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :disabled="index === 0"
                    @click="moveItem(index, -1)"
                >
                    ↑
                </button>
                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :disabled="index === items.length - 1"
                    @click="moveItem(index, 1)"
                >
                    ↓
                </button>
                <button type="button" class="btn btn-danger btn-sm" @click="removeItem(item)">
                    Удалить
                </button>
            </div>
        </article>
    </section>
</template>
