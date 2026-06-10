<script setup>
import { onMounted, ref } from 'vue';
import { apiUrl } from '../api';

const props = defineProps({
    authHeaders: { type: Function, required: true },
});

const emit = defineEmits(['error', 'saved']);

const SETTINGS_TABS = [
    { id: 'region', label: 'Регион' },
    { id: 'freshness', label: 'Свежесть' },
    { id: 'moderation', label: 'Модерация' },
    { id: 'networks', label: 'Сети' },
    { id: 'qr_schedule', label: 'Расписание QR' },
];

const settingsTab = ref('region');
const form = ref(null);
const saving = ref(false);
const loading = ref(false);

const emptyForm = () => ({
    geo_bbox: { south: 44.48, west: 33.38, north: 44.72, east: 33.78 },
    map_center: { lat: 44.605, lng: 33.522 },
    network_priority_text: '',
    freshness_fresh_minutes: 15,
    freshness_stale_minutes: 60,
    closure_reports_required: 5,
    correction_confirmations_required: 5,
    duplicate_radius_m: 80,
    qr_reminders: [],
});

async function load() {
    loading.value = true;

    try {
        const res = await fetch(apiUrl('/api/admin/settings'), { headers: props.authHeaders() });
        const json = await res.json();

        if (!res.ok) throw new Error(json.message || 'Ошибка загрузки');

        const d = json.data;
        form.value = {
            geo_bbox: { ...d.geo_bbox },
            map_center: { ...d.map_center },
            network_priority_text: (d.network_priority || []).join('\n'),
            freshness_fresh_minutes: d.freshness_fresh_minutes,
            freshness_stale_minutes: d.freshness_stale_minutes,
            closure_reports_required: d.closure_reports_required,
            correction_confirmations_required: d.correction_confirmations_required,
            duplicate_radius_m: d.duplicate_radius_m,
            qr_reminders: (d.qr_reminders || []).map((r) => ({
                time: r.time,
                title: r.title,
                body: r.body,
                url: r.url || '',
            })),
        };
    } catch (e) {
        emit('error', e.message);
        form.value = emptyForm();
    } finally {
        loading.value = false;
    }
}

function addReminder() {
    form.value.qr_reminders.push({ time: '21:00', title: '', body: '', url: '' });
}

function removeReminder(index) {
    form.value.qr_reminders.splice(index, 1);
}

async function save() {
    saving.value = true;
    emit('error', null);

    const networks = form.value.network_priority_text
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean);

    try {
        const res = await fetch(apiUrl('/api/admin/settings'), {
            method: 'PATCH',
            headers: props.authHeaders(),
            body: JSON.stringify({
                geo_bbox: form.value.geo_bbox,
                map_center: form.value.map_center,
                network_priority: networks,
                freshness_fresh_minutes: Number(form.value.freshness_fresh_minutes),
                freshness_stale_minutes: Number(form.value.freshness_stale_minutes),
                closure_reports_required: Number(form.value.closure_reports_required),
                correction_confirmations_required: Number(form.value.correction_confirmations_required),
                duplicate_radius_m: Number(form.value.duplicate_radius_m),
                qr_reminders: form.value.qr_reminders,
            }),
        });
        const json = await res.json();

        if (!res.ok) throw new Error(json.message || 'Ошибка сохранения');

        emit('saved', 'Настройки сохранены');
        await load();
    } catch (e) {
        emit('error', e.message);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section v-if="form" class="admin-settings">
        <header class="admin-settings-head">
            <h2>Настройки приложения</h2>
            <p class="hint">После сохранения применяются без перезапуска сервера.</p>
        </header>

        <nav class="admin-settings-tabs" aria-label="Разделы настроек">
            <button
                v-for="t in SETTINGS_TABS"
                :key="t.id"
                type="button"
                class="admin-settings-tab"
                :class="{ 'admin-settings-tab--active': settingsTab === t.id }"
                @click="settingsTab = t.id"
            >
                {{ t.label }}
            </button>
        </nav>

        <div class="admin-settings-scroll">
            <div v-show="settingsTab === 'region'" class="admin-settings-pane">
                <p class="admin-settings-desc">Границы региона для геозоны и центр карты при открытии.</p>
                <div class="admin-settings-grid">
                    <label class="field">Юг (lat)
                        <input v-model.number="form.geo_bbox.south" class="field-input" type="number" step="0.001" />
                    </label>
                    <label class="field">Север (lat)
                        <input v-model.number="form.geo_bbox.north" class="field-input" type="number" step="0.001" />
                    </label>
                    <label class="field">Запад (lng)
                        <input v-model.number="form.geo_bbox.west" class="field-input" type="number" step="0.001" />
                    </label>
                    <label class="field">Восток (lng)
                        <input v-model.number="form.geo_bbox.east" class="field-input" type="number" step="0.001" />
                    </label>
                    <label class="field">Центр карты (lat)
                        <input v-model.number="form.map_center.lat" class="field-input" type="number" step="0.001" />
                    </label>
                    <label class="field">Центр карты (lng)
                        <input v-model.number="form.map_center.lng" class="field-input" type="number" step="0.001" />
                    </label>
                </div>
            </div>

            <div v-show="settingsTab === 'freshness'" class="admin-settings-pane">
                <p class="admin-settings-desc">Как долго отчёт считается свежим (цвет маркера на карте).</p>
                <div class="admin-settings-grid">
                    <label class="field">«Свежий» до (мин)
                        <input v-model.number="form.freshness_fresh_minutes" class="field-input" type="number" min="1" />
                    </label>
                    <label class="field">«Вероятно актуально» до (мин)
                        <input v-model.number="form.freshness_stale_minutes" class="field-input" type="number" min="2" />
                    </label>
                </div>
            </div>

            <div v-show="settingsTab === 'moderation'" class="admin-settings-pane">
                <p class="admin-settings-desc">Пороги для скрытия АЗС, применения исправлений и проверки дубликатов.</p>
                <div class="admin-settings-grid">
                    <label class="field">Сообщений «АЗС закрыта»
                        <input v-model.number="form.closure_reports_required" class="field-input" type="number" min="1" />
                    </label>
                    <label class="field">Подтверждений исправления
                        <input v-model.number="form.correction_confirmations_required" class="field-input" type="number" min="1" />
                    </label>
                    <label class="field">Радиус дубликата АЗС (м)
                        <input v-model.number="form.duplicate_radius_m" class="field-input" type="number" min="10" />
                    </label>
                </div>
            </div>

            <div v-show="settingsTab === 'networks'" class="admin-settings-pane">
                <p class="admin-settings-desc">Порядок сетей в фильтре шапки. По одной на строку.</p>
                <label class="field">
                    Список сетей
                    <textarea v-model="form.network_priority_text" class="field-textarea" rows="12" />
                </label>
            </div>

            <div v-show="settingsTab === 'qr_schedule'" class="admin-settings-pane">
                <p class="admin-settings-desc">
                    Автоматические напоминания по расписанию (cron каждую минуту).
                    Срочные разовые сообщения - в разделе «Срочный push» в меню слева.
                </p>
                <div class="admin-reminder-list">
                <div v-for="(reminder, i) in form.qr_reminders" :key="i" class="admin-reminder-card">
                    <div class="admin-reminder-card-head">
                        <span class="admin-reminder-num">#{{ i + 1 }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="removeReminder(i)">Удалить</button>
                    </div>
                    <label class="field">Время
                        <input v-model="reminder.time" class="field-input" type="time" />
                    </label>
                    <label class="field">Заголовок
                        <input v-model="reminder.title" class="field-input" type="text" maxlength="120" />
                    </label>
                    <label class="field">Текст уведомления
                        <input v-model="reminder.body" class="field-input" type="text" maxlength="300" />
                    </label>
                    <label class="field">Ссылка при клике (https)
                        <input
                            v-model="reminder.url"
                            class="field-input"
                            type="url"
                            inputmode="url"
                            placeholder="https://t.me/your_bot"
                            maxlength="500"
                        />
                    </label>
                </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" @click="addReminder">+ Напоминание</button>
            </div>
        </div>

        <footer class="admin-settings-footer">
            <button type="button" class="btn btn-primary" :disabled="saving || loading" @click="save">
                {{ saving ? 'Сохранение…' : 'Сохранить все настройки' }}
            </button>
        </footer>
    </section>
    <p v-else-if="loading" class="hint">Загрузка настроек…</p>
</template>
