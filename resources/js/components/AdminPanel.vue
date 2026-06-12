<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminAiChatPanel from './AdminAiChatPanel.vue';
import AdminAnalyticsPanel from './AdminAnalyticsPanel.vue';
import AdminSystemPanel from './AdminSystemPanel.vue';
import AdminSettingsPanel from './AdminSettingsPanel.vue';
import AdminFaqPanel from './AdminFaqPanel.vue';
import AdminOsmImportPanel from './AdminOsmImportPanel.vue';
import AdminPushPanel from './AdminPushPanel.vue';
import AdminReportsPanel from './AdminReportsPanel.vue';
import { apiUrl } from '../api';
import { useTheme } from '../composables/useTheme';
import UiIcon from './UiIcon.vue';

const STORAGE_KEY = 'admin_token';

const PAGE_META = {
    overview: { title: 'Обзор', desc: 'Сводка по модерации и посещаемости' },
    corrections: { title: 'Исправления АЗС', desc: 'Предложения пользователей по данным заправок' },
    feedback: { title: 'Обратная связь', desc: 'Сообщения и предложения с сайта' },
    reports: { title: 'Отчёты', desc: 'Модерация пользовательских отчётов о наличии топлива' },
    osm: { title: 'Импорт OSM', desc: 'Загрузка и синхронизация заправок из OpenStreetMap' },
    push: { title: 'Push-уведомления', desc: 'Рассылка срочных сообщений подписчикам' },
    ai: { title: 'AI-импорт', desc: 'Разбор сообщений о топливе и создание отчётов' },
    analytics: { title: 'Посетители', desc: 'Уникальные заходы на сайт по дням' },
    system: { title: 'Система', desc: 'Память, диск, очередь и настройки сервера' },
    faq: { title: 'FAQ', desc: 'Вопросы и ответы в разделе помощи' },
    settings: { title: 'Настройки', desc: 'Параметры приложения и напоминания' },
};

const loggedIn = ref(false);
const password = ref('');
const tab = ref('overview');
const corrections = ref([]);
const feedback = ref([]);
const summary = ref({
    pending_corrections: 0,
    new_feedback: 0,
    visible_reports: 0,
    hidden_reports: 0,
    push_subscriptions: 0,
    visitors_today: 0,
    visitors_yesterday: 0,
});
const loading = ref(false);
const error = ref(null);
const loginError = ref(null);
const saveNotice = ref(null);
const noteEdits = ref({});

const { isDark, toggleTheme } = useTheme();

const pageMeta = computed(() => PAGE_META[tab.value] || PAGE_META.overview);

const navGroups = computed(() => [
    {
        label: null,
        items: [{ id: 'overview', label: 'Обзор', icon: 'gauge' }],
    },
    {
        label: 'Модерация',
        items: [
            { id: 'corrections', label: 'Исправления', icon: 'alert-triangle', badge: summary.value.pending_corrections },
            { id: 'feedback', label: 'Обратная связь', icon: 'message-square', badge: summary.value.new_feedback, badgeAccent: true },
            { id: 'reports', label: 'Отчёты', icon: 'list' },
        ],
    },
    {
        label: 'Контент',
        items: [
            { id: 'faq', label: 'FAQ', icon: 'help-circle' },
            { id: 'osm', label: 'Импорт OSM', icon: 'navigation' },
            { id: 'ai', label: 'AI-импорт', icon: 'star' },
            { id: 'push', label: 'Push', icon: 'bell' },
        ],
    },
    {
        label: 'Система',
        items: [
            { id: 'analytics', label: 'Посетители', icon: 'activity' },
            { id: 'system', label: 'Система', icon: 'gauge' },
            { id: 'settings', label: 'Настройки', icon: 'settings' },
        ],
    },
]);

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
        throw new Error('Сессия истекла — войдите снова');
    }

    if (res.status === 429) {
        const retryAfter = res.headers.get('Retry-After');
        throw new Error(
            json.message
            || (retryAfter
                ? `Слишком много запросов. Подождите ${retryAfter} сек.`
                : 'Слишком много запросов. Подождите немного.'),
        );
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
    summary.value = {
        pending_corrections: 0,
        new_feedback: 0,
        visible_reports: 0,
        hidden_reports: 0,
        push_subscriptions: 0,
        visitors_today: 0,
        visitors_yesterday: 0,
        ...json.data,
    };
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

async function loadAll() {
    loading.value = true;
    error.value = null;

    try {
        await Promise.all([loadSummary(), loadCorrections(), loadFeedback()]);
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

function logout() {
    sessionStorage.removeItem(STORAGE_KEY);
    loggedIn.value = false;
    password.value = '';
    corrections.value = [];
    feedback.value = [];
    summary.value = {
        pending_corrections: 0,
        new_feedback: 0,
        visible_reports: 0,
        hidden_reports: 0,
        push_subscriptions: 0,
        visitors_today: 0,
        visitors_yesterday: 0,
    };
}

function correctionSummary(item) {
    if (item.field === 'location') {
        return `Перенос: ${item.current_value} → ${item.proposed_value}`;
    }

    return `${item.field_label}: «${item.current_value}» → «${item.proposed_value}»`;
}

function selectTab(id) {
    tab.value = id;
    if (id === 'push') {
        loadSummary();
    }
}

const feedbackNew = computed(() => feedback.value.filter((f) => f.status === 'new'));

watch(saveNotice, (msg) => {
    if (!msg) return;
    setTimeout(() => {
        if (saveNotice.value === msg) {
            saveNotice.value = null;
        }
    }, 4500);
});

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
                <span class="admin-topbar-mark">АЗС</span>
                <div>
                    <h1>Админ-панель</h1>
                    <span v-if="loggedIn" class="admin-topbar-sub">Топливо Севастополь</span>
                </div>
            </div>
            <div class="admin-topbar-right">
                <button
                    type="button"
                    class="topbar-icon-btn topbar-icon-btn--theme"
                    :title="isDark ? 'Светлая тема' : 'Тёмная тема'"
                    @click="toggleTheme"
                >
                    <UiIcon :name="isDark ? 'sun' : 'moon'" :size="16" color="currentColor" />
                </button>
                <a href="/" class="admin-back">
                    <UiIcon name="navigation" :size="14" color="currentColor" />
                    На карту
                </a>
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
                        {{ loading ? 'Вход…' : 'Войти' }}
                    </button>
                </form>
            </div>
        </div>

        <div v-else class="admin-body">
            <aside class="admin-sidebar">
                <nav class="admin-sidebar-nav">
                    <div v-for="(group, gi) in navGroups" :key="gi" class="admin-nav-group">
                        <span v-if="group.label" class="admin-nav-group-label">{{ group.label }}</span>
                        <button
                            v-for="item in group.items"
                            :key="item.id"
                            type="button"
                            class="admin-nav-item"
                            :class="{ 'admin-nav-item--active': tab === item.id }"
                            @click="selectTab(item.id)"
                        >
                            <span class="admin-nav-item-icon">
                                <UiIcon :name="item.icon" :size="15" color="currentColor" />
                            </span>
                            <span class="admin-nav-item-label">{{ item.label }}</span>
                            <span
                                v-if="item.badge"
                                class="admin-badge"
                                :class="{ 'admin-badge--accent': item.badgeAccent }"
                            >{{ item.badge }}</span>
                        </button>
                    </div>
                </nav>
            </aside>

            <main class="admin-main" :class="{ 'admin-main--settings': tab === 'settings' }">
                <div class="admin-main-inner">
                    <header v-if="['overview', 'corrections', 'feedback'].includes(tab)" class="admin-page-head">
                        <h2>
                            {{ pageMeta.title }}
                            <span
                                v-if="tab === 'feedback' && feedbackNew.length"
                                class="admin-count"
                            >{{ feedbackNew.length }} новых</span>
                        </h2>
                        <p class="admin-page-desc">{{ pageMeta.desc }}</p>
                    </header>

                    <div v-if="saveNotice" class="admin-toast" role="status">
                        <UiIcon name="check" :size="16" color="currentColor" />
                        {{ saveNotice }}
                    </div>

                    <p v-if="error" class="error admin-alert">{{ error }}</p>
                    <div v-if="loading" class="admin-loading-bar" aria-hidden="true" />

                    <section v-if="tab === 'overview'" class="admin-section">
                        <div class="admin-stats-grid">
                            <button type="button" class="admin-stat-card" @click="selectTab('corrections')">
                                <span class="admin-stat-value">{{ summary.pending_corrections }}</span>
                                <span class="admin-stat-label">Ожидают исправления</span>
                            </button>
                            <button type="button" class="admin-stat-card" @click="selectTab('feedback')">
                                <span class="admin-stat-value">{{ summary.new_feedback }}</span>
                                <span class="admin-stat-label">Новых сообщений</span>
                            </button>
                            <button type="button" class="admin-stat-card" @click="selectTab('reports')">
                                <span class="admin-stat-value">{{ summary.visible_reports }}</span>
                                <span class="admin-stat-label">Видимых отчётов</span>
                            </button>
                            <button type="button" class="admin-stat-card" @click="selectTab('analytics')">
                                <span class="admin-stat-value">{{ summary.visitors_today }}</span>
                                <span class="admin-stat-label">Посетителей сегодня</span>
                            </button>
                        </div>
                        <p class="admin-overview-note">
                            Скрыто отчётов: {{ summary.hidden_reports }} ·
                            Вчера заходило: {{ summary.visitors_yesterday }} ·
                            Push-подписчиков: {{ summary.push_subscriptions }}
                        </p>
                    </section>

                    <AdminAnalyticsPanel
                        v-if="tab === 'analytics'"
                        :auth-headers="authHeaders"
                        @error="error = $event"
                    />

                    <AdminSystemPanel
                        v-if="tab === 'system'"
                        :auth-headers="authHeaders"
                        @error="error = $event"
                    />

                    <section v-if="tab === 'corrections'" class="admin-section">
                        <div v-if="!loading && !corrections.length" class="admin-empty">
                            <UiIcon name="check" :size="28" color="currentColor" />
                            <p>Нет ожидающих исправлений</p>
                        </div>

                        <div v-else class="admin-item-list">
                            <article v-for="item in corrections" :key="item.id" class="admin-item">
                                <div class="admin-item-head">
                                    <span class="admin-item-title">{{ item.station_network }} — {{ item.station_name }}</span>
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
                        </div>
                    </section>

                    <section v-if="tab === 'feedback'" class="admin-section">
                        <div v-if="!loading && !feedback.length" class="admin-empty">
                            <UiIcon name="message-square" :size="28" color="currentColor" />
                            <p>Сообщений пока нет</p>
                        </div>

                        <div v-else class="admin-item-list">
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
                        </div>
                    </section>

                    <AdminReportsPanel
                        v-if="tab === 'reports'"
                        :auth-headers="authHeaders"
                        @error="error = $event"
                        @saved="(msg) => { saveNotice = msg; error = null; }"
                        @refresh="loadSummary()"
                    />

                    <AdminOsmImportPanel
                        v-if="tab === 'osm'"
                        :auth-headers="authHeaders"
                        @error="error = $event"
                        @done="(msg) => { saveNotice = msg; error = null; }"
                    />

                    <AdminAiChatPanel
                        v-if="tab === 'ai'"
                        :auth-headers="authHeaders"
                        @error="error = $event"
                        @saved="(msg) => { saveNotice = msg; error = null; }"
                    />

                    <AdminPushPanel
                        v-if="tab === 'push'"
                        :auth-headers="authHeaders"
                        :subscription-count="summary.push_subscriptions"
                        @error="error = $event"
                        @saved="(msg) => { saveNotice = msg; error = null; }"
                        @refresh="loadSummary()"
                    />

                    <AdminFaqPanel
                        v-if="tab === 'faq'"
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
                </div>
            </main>
        </div>
    </div>
</template>
