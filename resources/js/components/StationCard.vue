<script setup>
import { computed, ref } from 'vue';
import { FUEL_TYPES } from '../constants';
import { useFavoriteStations } from '../composables/useFavoriteStations';
import { usePushNotifications } from '../composables/usePushNotifications';
import PhotoLightbox from './PhotoLightbox.vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    station: { type: Object, required: true },
    selectedFuel: { type: String, default: 'a95' },
    selectedSaleType: { type: String, default: null },
    canisterOnly: { type: Boolean, default: false },
});

const emit = defineEmits([
    'report',
    'confirm',
    'close',
    'closed',
    'edit',
    'confirm-correction',
    'select-fuel',
    'select-sale-type',
    'select-canister-only',
]);

const lightboxSrc = ref(null);
const favoriteError = ref(null);
const { isFavorite, toggle } = useFavoriteStations();
const { subscribed: pushSubscribed } = usePushNotifications();

const favorite = computed(() => isFavorite(props.station.id));
const favoritePop = ref(false);
let favoritePopTimer = null;

const displayTitle = computed(() => {
    const name = (props.station.name || '').trim();
    const network = (props.station.network || '').trim();

    if (!name || name.toLowerCase() === network.toLowerCase()) {
        return network || name || 'АЗС';
    }

    return name;
});

const showNetwork = computed(() => {
    const name = (props.station.name || '').trim();
    const network = (props.station.network || '').trim();

    return network && name.toLowerCase() !== network.toLowerCase();
});

const activeFuel = computed(() => (
    props.station.fuels?.find((f) => f.fuel_type === props.selectedFuel)
    ?? props.station.fuels?.[0]
));

const fuelReports = computed(() => {
    const history = props.station.history?.[props.selectedFuel] ?? [];

    if (history.length > 0) {
        return history;
    }

    if (!activeFuel.value || activeFuel.value.freshness === 'unknown') {
        return [];
    }

    return [{
        time: formatReportTime(activeFuel.value.reported_at),
        status: activeFuel.value.status,
        status_label: activeFuel.value.status_label,
        sale_type_labels: activeFuel.value.sale_type_labels ?? [],
        fill_volume_label: activeFuel.value.fill_volume_label,
        canister_policy_label: activeFuel.value.canister_policy_label,
        queue_label: activeFuel.value.queue_label,
        comment: activeFuel.value.comment,
        photo_url: activeFuel.value.photo_url,
        is_confirmation: activeFuel.value.is_confirmation,
    }];
});

const pendingCorrections = computed(() => props.station.pending_corrections ?? []);

const closureButtonLabel = computed(() => {
    const count = props.station.closure_reports_count ?? 0;
    const required = props.station.closure_reports_required ?? 5;

    return `Заправка не работает (${count} из ${required})`;
});

function fuelLabel(value) {
    return FUEL_TYPES.find((f) => f.value === value)?.label ?? value;
}

function statusTitle(fuel) {
    if (!fuel || fuel.freshness === 'unknown' || fuel.status === 'unknown') {
        return 'Пока нет данных';
    }

    return fuel.status_label;
}

function statusSub(fuel) {
    if (!fuel) {
        return '';
    }

    if (fuel.freshness === 'unknown') {
        return `Про ${fuelLabel(fuel.fuel_type)} ещё никто не писал. Расскажите первым!`;
    }

    return fuel.freshness_label;
}

function formatReportTime(iso) {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    } catch {
        return '';
    }
}

function reportMeta(item) {
    const parts = [];

    if (item.is_confirmation) {
        parts.push('Подтверждение');
    }

    const sales = (item.sale_type_labels ?? []).filter((l) => l !== 'Обычный');

    if (sales.length) {
        parts.push(...sales);
    }

    if (item.fill_volume_label) {
        parts.push(item.fill_volume_label);
    }

    if (item.canister_policy_label) {
        parts.push(item.canister_policy_label);
    }

    return parts;
}

function showQueue(item) {
    return item.queue_label
        && item.queue_label !== 'Очереди нет'
        && item.queue_label !== 'Не знаю';
}

function correctionSummary(item) {
    if (item.field === 'location') {
        return 'Предлагают передвинуть точку на карте';
    }

    return `${item.field_label}: «${item.current_value}» меняют на «${item.proposed_value}»`;
}

function confirmCorrectionLabel(item) {
    return `Всё верно (${item.confirmations_count} из ${item.confirmations_required})`;
}

function openPhoto(url) {
    lightboxSrc.value = url;
}

function onToggleFavorite() {
    favoriteError.value = null;

    try {
        toggle(props.station.id);
        favoritePop.value = true;
        clearTimeout(favoritePopTimer);
        favoritePopTimer = setTimeout(() => {
            favoritePop.value = false;
        }, 360);
    } catch (e) {
        favoriteError.value = e.message;
    }
}
</script>

<template>
    <div class="station-sheet">
        <div class="sheet-handle" aria-hidden="true" />

        <div class="sheet-toolbar">
            <button
                type="button"
                class="sheet-toolbar-btn sheet-toolbar-btn--favorite"
                :class="{ 'is-active': favorite, 'is-pop': favoritePop }"
                :aria-pressed="favorite"
                :aria-label="favorite ? 'Убрать из избранного' : 'В избранное'"
                @click.stop="onToggleFavorite"
            >
                <UiIcon
                    name="star"
                    :size="16"
                    :color="favorite ? '#E8B84B' : '#7A7570'"
                    :fill="favorite ? '#E8B84B' : 'none'"
                />
            </button>
            <button
                type="button"
                class="sheet-toolbar-btn"
                aria-label="Закрыть"
                @click="emit('close')"
            >
                <UiIcon name="x" :size="16" color="#7A7570" />
            </button>
        </div>

        <div class="station-sheet-body">
            <header class="station-head station-head--figma">
                <h2 class="station-title">{{ displayTitle }}</h2>
                <div class="station-head-meta">
                    <span v-if="showNetwork" class="station-network-badge">{{ station.network }}</span>
                    <span class="station-address-inline">{{ station.address }}</span>
                    <button
                        v-if="station.distance_m"
                        type="button"
                        class="station-nav-btn station-nav-btn--inline"
                        @click.stop
                    >
                        <UiIcon name="navigation" :size="11" color="currentColor" />
                        {{ Math.round(station.distance_m / 100) / 10 }} км
                    </button>
                </div>
                <p v-if="favoriteError" class="station-favorite-error">{{ favoriteError }}</p>
                <p v-if="favorite && pushSubscribed" class="station-favorite-hint">
                    Сообщим на телефон, когда на этой заправке появится {{ fuelLabel(selectedFuel) }}
                </p>
                <p v-else-if="favorite" class="station-favorite-hint">
                    Заправка в списке «Мои АЗС». Разрешите уведомления, и мы сообщим, когда здесь появится {{ fuelLabel(selectedFuel) }}
                </p>
            </header>

            <section v-if="activeFuel" class="status-card" :class="`status-card--${activeFuel.status}`">
                <div class="status-card-row">
                    <span class="status-card-dot" aria-hidden="true" />
                    <div class="status-card-text">
                        <p class="status-card-title">{{ statusTitle(activeFuel) }}</p>
                        <p class="status-card-sub">{{ statusSub(activeFuel) }}</p>
                    </div>
                </div>
                <div
                    v-if="activeFuel.sale_type_labels?.length || activeFuel.fill_volume_label || activeFuel.canister_policy_label"
                    class="status-card-pills"
                >
                    <span
                        v-for="label in activeFuel.sale_type_labels"
                        :key="label"
                        class="status-pill"
                        :class="{
                            'status-pill--qr': label === 'Нужен QR',
                            'status-pill--voucher': label === 'По талонам',
                        }"
                    >
                        {{ label }}
                    </span>
                    <span v-if="activeFuel.fill_volume_label" class="status-pill status-pill--volume">
                        {{ activeFuel.fill_volume_label }}
                    </span>
                    <span
                        v-if="activeFuel.canister_policy_label"
                        class="status-pill"
                        :class="{
                            'status-pill--canister-allowed': activeFuel.canister_policy === 'allowed',
                            'status-pill--canister-forbidden': activeFuel.canister_policy === 'forbidden',
                        }"
                    >
                        {{ activeFuel.canister_policy_label }}
                    </span>
                </div>
            </section>

            <section class="fuel-grid-section">
                <div class="section-label">Всё топливо</div>
                <div class="fuel-grid">
                    <button
                        v-for="fuel in station.fuels"
                        :key="fuel.fuel_type"
                        type="button"
                        class="fuel-chip"
                        :class="[
                            `fuel-chip--${fuel.status}`,
                            { 'fuel-chip--selected': fuel.fuel_type === selectedFuel },
                        ]"
                        @click="emit('select-fuel', fuel.fuel_type)"
                    >
                        <span class="fuel-chip-label">{{ fuelLabel(fuel.fuel_type) }}</span>
                        <span class="fuel-chip-dot" />
                    </button>
                </div>
                <div class="sheet-sale-filter">
                    <button
                        type="button"
                        class="sheet-sale-btn"
                        :class="{ active: selectedSaleType === null }"
                        @click="emit('select-sale-type', null)"
                    >
                        Все
                    </button>
                    <button
                        type="button"
                        class="sheet-sale-btn sheet-sale-btn--voucher"
                        :class="{ active: selectedSaleType === 'voucher' }"
                        @click="emit('select-sale-type', 'voucher')"
                    >
                        Талоны
                    </button>
                    <button
                        type="button"
                        class="sheet-sale-btn sheet-sale-btn--qr"
                        :class="{ active: selectedSaleType === 'qr' }"
                        @click="emit('select-sale-type', 'qr')"
                    >
                        QR
                    </button>
                    <button
                        type="button"
                        class="sheet-sale-btn sheet-sale-btn--canister"
                        :class="{ active: canisterOnly }"
                        @click="emit('select-canister-only', !canisterOnly)"
                    >
                        Канистра
                    </button>
                </div>
            </section>

            <section v-if="fuelReports.length" class="report-feed">
                <div class="section-label">
                    Что пишут о топливе {{ fuelLabel(selectedFuel) }}
                </div>

                <article
                    v-for="(item, index) in fuelReports"
                    :key="index"
                    class="report-card"
                    :class="{
                        'report-card--confirm': item.is_confirmation,
                        [`report-card--${item.status}`]: !item.is_confirmation,
                    }"
                >
                    <div class="report-card-main">
                        <div class="report-card-head">
                            <span
                                v-if="item.is_confirmation"
                                class="report-badge report-badge--confirm"
                            >
                                Подтверждение
                            </span>
                            <span
                                v-else
                                class="report-badge"
                                :class="`report-badge--${item.status}`"
                            >
                                {{ item.status_label }}
                            </span>
                            <time class="report-card-time">{{ item.time }}</time>
                        </div>

                        <p v-if="reportMeta(item).length" class="report-card-meta">
                            {{ reportMeta(item).join(' · ') }}
                        </p>

                        <p v-if="showQueue(item)" class="report-card-queue">
                            Очередь: {{ item.queue_label }}
                        </p>

                        <p v-if="item.comment" class="report-card-comment">
                            {{ item.comment }}
                        </p>
                    </div>

                    <button
                        v-if="item.photo_url"
                        type="button"
                        class="report-card-photo"
                        @click="openPhoto(item.photo_url)"
                    >
                        <img :src="item.photo_url" alt="Фото отчёта" loading="lazy" />
                    </button>
                </article>
            </section>

            <section v-if="pendingCorrections.length" class="corrections-section">
                <div class="section-label">Кто-то предложил исправления</div>
                <div v-for="item in pendingCorrections" :key="item.id" class="correction-card">
                    <p class="correction-card-text">{{ correctionSummary(item) }}</p>
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm btn-block correction-card-btn"
                        @click="emit('confirm-correction', item)"
                    >
                        {{ confirmCorrectionLabel(item) }}
                    </button>
                </div>
            </section>
        </div>

        <footer class="station-sheet-footer station-sheet-footer--figma">
            <button type="button" class="action-btn action-btn--report" @click="emit('report')">
                <UiIcon name="message-square" :size="13" color="currentColor" />
                Сообщить
            </button>
            <button type="button" class="action-btn action-btn--confirm" @click="emit('confirm')">
                <UiIcon name="thumbs-up" :size="13" color="currentColor" />
                Подтверждаю
            </button>
            <button type="button" class="action-btn action-btn--edit" @click="emit('edit')">
                <UiIcon name="alert-triangle" :size="13" color="currentColor" />
                Исправить
            </button>
            <button type="button" class="action-btn action-btn--muted" @click="emit('closed')">
                {{ closureButtonLabel }}
            </button>
        </footer>

        <PhotoLightbox
            v-if="lightboxSrc"
            :src="lightboxSrc"
            alt="Фото отчёта"
            @close="lightboxSrc = null"
        />
    </div>
</template>
