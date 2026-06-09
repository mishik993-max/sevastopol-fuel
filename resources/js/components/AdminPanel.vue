<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminSettingsPanel from './AdminSettingsPanel.vue';
import AdminOsmImportPanel from './AdminOsmImportPanel.vue';
import AdminPushPanel from './AdminPushPanel.vue';
import { apiUrl } from '../api';

const STORAGE_KEY = 'admin_token';

const loggedIn = ref(false);
const password = ref('');
const tab = ref('corrections');
const corrections = ref([]);
const feedback = ref([]);
const reports = ref([]);
const summary = ref({ pending_corrections: 0, new_feedback: 0, visible_reports: 0, hidden_reports: 0 });
const loading = ref(false);
const error = ref(null);
const loginError = ref(null);
const saveNotice = ref(null);
const noteEdits = ref({});

function token() {
    return sessionStorage.getItem(STORAGE_KEY) || '';
}

function authHeaders() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Admin-Token': token(),
    };
}

async function apiFetch(url, options = {}) {
    const res = await fetch(apiUrl(url), {
        ...options,
        headers: { ...authHeaders(), ...options.headers },
    });
    const json = await res.json().catch(() => ({}));

    if (res.status === 401) {
        logout();
        throw new Error('Сессия истекла- войдите снова');
    }

    if (!res.ok) throw new Error(json.message || 'Ошибка запроса');

    return json;
}

async function login() {
    loginError.value = null;
    loading.value = true;

    try {
        const res = await fetch(apiUrl('/api/admin/login'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password.value }),
        });
        const json = await res.json().catch(() => ({}));

        if (res.status === 429) {
            const retryAfter = res.headers.get('Retry-After');
            throw new Error(
                json.message
                || (retryAfter
                    ? `Слишком много попыток. Подождите ${retryAfter} сек.`
                    : 'Слишком много попыток. Подождите несколько минут.'),
            );
        }

        if (!res.ok) throw new Error(json.message || 'Ошибка входа');

        sessionStorage.setItem(STORAGE_KEY, password.value);
        loggedIn.value = true;
        await loadAll();
    } catch (e) {
        loginError.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function loadSummary() {
    const json = await apiFetch('/api/admin/summary');
    summary.value = json.data;
}

async function loadCorrections() {
    const json = await apiFetch('/api/admin/corrections');
    corrections.value = json.data;
}

async function loadFeedback() {
    const json = await apiFetch('/api/admin/feedback');
    feedback.value = json.data;
    for (const item of json.data) {
        noteEdits.value[item.id] = item.admin_note || '';
    }
}

async function loadReports() {
    const json = await apiFetch('/api/admin/reports');
    reports.value = json.data;
}

async function loadAll() {
    loading.value = true;
    error.value = null;

    try {
        await Promise.all([loadSummary(), loadCorrections(), loadFeedback(), loadReports()]);
        loggedIn.value = true;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function actCorrection(id, action) {
    loading.value = true;
    error.value = null;

    try {
        const json = await apiFetch(`/api/admin/corrections/${id}/${action}`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        corrections.value = json.data;
        await loadSummary();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function updateFeedback(id, status) {
    loading.value = true;
    error.value = null;

    try {
        const json = await apiFetch(`/api/admin/feedback/${id}`, {
            method: 'PATCH',
            body: JSON.stringify({
                status,
                admin_note: noteEdits.value[id] || null,
            }),
        });
        feedback.value = json.data;
        await loadSummary();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function toggleReport(id, hide) {
    loading.value = true;
    error.value = null;

    try {
        const json = await apiFetch(`/api/admin/reports/${id}/${hide ? 'hide' : 'unhide'}`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        reports.value = json.data;
        await loadSummary();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function logout() {
    sessionStorage.removeItem(STORAGE_KEY);
    loggedIn.value = false;
    password.value = '';
    corrections.value = [];
    feedback.value = [];
    reports.value = [];
    summary.value = { pending_corrections: 0, new_feedback: 0, visible_reports: 0, hidden_reports: 0 };
}

function correctionSummary(item) {
    if (item.field === 'location') {
        return `Перенос: ${item.current_value} → ${item.proposed_value}`;
    }

    return `${item.field_label}: «${item.current_value}» → «${item.proposed_value}»`;
}

const feedbackNew = computed(() => feedback.value.filter((f) => f.status === 'new'));
const reportsVisible = computed(() => reports.value.filter((r) => !r.is_hidden));
const reportsHidden = computed(() => reports.value.filter((r) => r.is_hidden));

onMounted(() => {
    if (token()) {
        loadAll();
    }
});
</script>

<template>
    <div class="admin-layout">
        <header class="admin-topbar">
            <div class="admin-topbar-left">
                <h1>Админка</h1>
                <span v-if="loggedIn" class="admin-topbar-sub">Топливо Севастополь</span>
            </div>
            <div class="admin-topbar-right">
                <a href="/" class="admin-back">← На карту</a>
                <button v-if="loggedIn" type="button" class="btn btn-ghost btn-sm" @click="logout">
                    Выйти
                </button>
            </div>
        </header>

        <div v-if="!loggedIn" class="admin-login-wrap">
            <div class="admin-card admin-card--login">
                <h2>Вход администратора</h2>
                <p class="hint">Пароль задаётся в <code>ADMIN_PASSWORD</code> (.env)</p>
                <form @submit.prevent="login">
                    <label class="field">
                        Пароль
                        <input v-model="password" class="field-input" type="password" autocomplete="current-password" />
                    </label>
                    <p v-if="loginError" class="error">{{ loginError }}</p>
                    <button type="submit" class="btn btn-primary btn-block" :disabled="loading">
                        {{ loading ? '…' : 'Войти' }}
                    </button>
                </form>
            </div>
        </div>

        <div v-else class="admin-body">
            <aside class="admin-nav">
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'overview' }"
                    @click="tab = 'overview'"
                >
                    Обзор
                </button>
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'corrections' }"
                    @click="tab = 'corrections'"
                >
                    Исправления АЗС
                    <span v-if="summary.pending_corrections" class="admin-badge">{{ summary.pending_corrections }}</span>
                </button>
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'feedback' }"
                    @click="tab = 'feedback'"
                >
                    Обратная связь
                    <span v-if="summary.new_feedback" class="admin-badge admin-badge--accent">{{ summary.new_feedback }}</span>
                </button>
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'reports' }"
                    @click="tab = 'reports'"
                >
                    Отчёты
                </button>
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'osm' }"
                    @click="tab = 'osm'"
                >
                    Импорт OSM
                </button>
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'push' }"
                    @click="tab = 'push'"
                >
                    Срочный push
                </button>
                <button
                    type="button"
                    class="admin-nav-item"
                    :class="{ 'admin-nav-item--active': tab === 'settings' }"
                    @click="tab = 'settings'"
                >
                    Настройки
                </button>
            </aside>

            <main class="admin-main" :class="{ 'admin-main--settings': tab === 'settings' }">
                <p v-if="saveNotice" class="admin-notice">{{ saveNotice }}</p>
                <p v-if="error" class="error admin-error">{{ error }}</p>
                <p v-if="loading" class="hint admin-loading">Загрузка…</p>

                <section v-if="tab === 'overview'" class="admin-section">
                    <h2>Обзор</h2>
                    <div class="admin-stats-grid">
                        <button type="button" class="admin-stat-card" @click="tab = 'corrections'">
                            <span class="admin-stat-value">{{ summary.pending_corrections }}</span>
                            <span class="admin-stat-label">Ожидают исправления</span>
                        </button>
                        <button type="button" class="admin-stat-card" @click="tab = 'feedback'">
                            <span class="admin-stat-value">{{ summary.new_feedback }}</span>
                            <span class="admin-stat-label">Новых сообщений</span>
                        </button>
                        <button type="button" class="admin-stat-card" @click="tab = 'reports'">
                            <span class="admin-stat-value">{{ summary.visible_reports }}</span>
                            <span class="admin-stat-label">Видимых отчётов</span>
                        </button>
                    </div>
                    <p class="hint">
                        Модерация отчётов, исправлений АЗС, обратной связи и ручной импорт из OSM.
                        Скрыто отчётов: {{ summary.hidden_reports }}.
                    </p>
                </section>

                <section v-if="tab === 'corrections'" class="admin-section">
                    <h2>Исправления АЗС <span class="admin-count">{{ corrections.length }}</span></h2>
                    <p v-if="!loading && !corrections.length" class="hint">Нет ожидающих исправлений</p>

                    <article v-for="item in corrections" :key="item.id" class="admin-item">
                        <div class="admin-item-head">
                            <span class="admin-item-title">{{ item.station_network }}- {{ item.station_name }}</span>
                            <span class="admin-item-date">{{ item.created_at }}</span>
                        </div>
                        <p class="admin-item-text">{{ correctionSummary(item) }}</p>
                        <p class="admin-item-meta">
                            {{ item.confirmations_count }}/{{ item.confirmations_required }} подтверждений от пользователей
                        </p>
                        <div class="admin-item-actions">
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="loading"
                                @click="actCorrection(item.id, 'apply')"
                            >
                                Применить
                            </button>
                            <button
                                type="button"
                                class="btn btn-secondary btn-sm"
                                :disabled="loading"
                                @click="actCorrection(item.id, 'reject')"
                            >
                                Отклонить
                            </button>
                        </div>
                    </article>
                </section>

                <section v-if="tab === 'feedback'" class="admin-section">
                    <h2>
                        Обратная связь
                        <span v-if="feedbackNew.length" class="admin-count">{{ feedbackNew.length }} новых</span>
                    </h2>
                    <p v-if="!loading && !feedback.length" class="hint">Сообщений пока нет</p>

                    <article
                        v-for="item in feedback"
                        :key="item.id"
                        class="admin-item"
                        :class="`admin-item--${item.status}`"
                    >
                        <div class="admin-item-head">
                            <span class="admin-item-title">{{ item.type_label }}</span>
                            <span class="admin-status-pill" :class="`admin-status-pill--${item.status}`">
                                {{ item.status_label }}
                            </span>
                        </div>
                        <p class="admin-item-date">{{ item.created_at }}</p>
                        <p class="admin-item-text admin-item-text--message">{{ item.message }}</p>
                        <p v-if="item.contact" class="admin-item-meta">Контакт: {{ item.contact }}</p>

                        <label class="field admin-note-field">
                            Заметка администратора
                            <textarea
                                v-model="noteEdits[item.id]"
                                class="field-textarea"
                                rows="2"
                                maxlength="1000"
                                placeholder="Внутренняя заметка…"
                            />
                        </label>

                        <div class="admin-item-actions">
                            <button
                                v-if="item.status === 'new'"
                                type="button"
                                class="btn btn-secondary btn-sm"
                                :disabled="loading"
                                @click="updateFeedback(item.id, 'read')"
                            >
                                Прочитано
                            </button>
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="loading"
                                @click="updateFeedback(item.id, 'done')"
                            >
                                Обработано
                            </button>
                        </div>
                    </article>
                </section>

                <section v-if="tab === 'reports'" class="admin-section">
                    <h2>
                        Отчёты пользователей
                        <span class="admin-count">{{ reportsVisible.length }} видимых</span>
                    </h2>
                    <p class="hint">
                        Скрытые отчёты не влияют на статус АЗС на карте. Фото можно открыть в новой вкладке.
                    </p>
                    <p v-if="!loading && !reports.length" class="hint">Отчётов пока нет</p>

                    <article
                        v-for="item in reports"
                        :key="item.id"
                        class="admin-item"
                        :class="{ 'admin-item--muted': item.is_hidden }"
                    >
                        <div class="admin-item-head">
                            <span class="admin-item-title">
                                {{ item.station_network }} - {{ item.station_name }}
                            </span>
                            <span class="admin-item-date">{{ item.created_at }}</span>
                        </div>
                        <p class="admin-item-text">
                            {{ item.fuel_label }}: {{ item.status_label }}
                            <span v-if="item.is_confirmation" class="history-confirm">✓</span>
                        </p>
                        <p v-if="item.sale_type_labels?.length" class="admin-item-meta">
                            {{ item.sale_type_labels.join(' · ') }}
                        </p>
                        <p v-if="item.comment" class="admin-item-text admin-item-text--message">{{ item.comment }}</p>
                        <a
                            v-if="item.photo_url"
                            :href="item.photo_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="admin-report-photo-link"
                        >
                            <img :src="item.photo_url" alt="Фото отчёта" class="admin-report-photo" loading="lazy" />
                        </a>
                        <div class="admin-item-actions">
                            <button
                                v-if="!item.is_hidden"
                                type="button"
                                class="btn btn-secondary btn-sm"
                                :disabled="loading"
                                @click="toggleReport(item.id, true)"
                            >
                                Скрыть
                            </button>
                            <button
                                v-else
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="loading"
                                @click="toggleReport(item.id, false)"
                            >
                                Вернуть
                            </button>
                        </div>
                    </article>

                    <div v-if="reportsHidden.length" class="admin-osm-block">
                        <h3>Скрытые ({{ reportsHidden.length }})</h3>
                    </div>
                </section>

                <AdminOsmImportPanel
                    v-if="tab === 'osm'"
                    :auth-headers="authHeaders"
                    @error="error = $event"
                    @done="(msg) => { saveNotice = msg; error = null; }"
                />

                <AdminPushPanel
                    v-if="tab === 'push'"
                    :auth-headers="authHeaders"
                    @error="error = $event"
                    @saved="(msg) => { saveNotice = msg; error = null; }"
                />

                <AdminSettingsPanel
                    v-if="tab === 'settings'"
                    :auth-headers="authHeaders"
                    @error="error = $event"
                    @saved="(msg) => { saveNotice = msg; error = null; }"
                />
            </main>
        </div>
    </div>
</template>
