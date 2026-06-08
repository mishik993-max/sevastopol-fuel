<script setup>
import { computed, ref } from 'vue';
import { FUEL_TYPES } from '../constants';
import PhotoLightbox from './PhotoLightbox.vue';

const props = defineProps({
    station: { type: Object, required: true },
    selectedFuel: { type: String, default: 'a95' },
    selectedSaleType: { type: String, default: null },
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
]);

const lightboxSrc = ref(null);

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

    return `АЗС больше не работает (${count}/${required})`;
});

function fuelLabel(value) {
    return FUEL_TYPES.find((f) => f.value === value)?.label ?? value;
}

function statusTitle(fuel) {
    if (!fuel || fuel.freshness === 'unknown' || fuel.status === 'unknown') {
        return 'Нет отчётов';
    }

    return fuel.status_label;
}

function statusSub(fuel) {
    if (!fuel) {
        return '';
    }

    if (fuel.freshness === 'unknown') {
        return `По ${fuelLabel(fuel.fuel_type)} — сообщите, будьте первым`;
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

    return parts;
}

function showQueue(item) {
    return item.queue_label && item.queue_label !== 'Нет';
}

function correctionSummary(item) {
    if (item.field === 'location') {
        return 'Перенос маркера на карте';
    }

    return `${item.field_label}: «${item.current_value}» → «${item.proposed_value}»`;
}

function confirmCorrectionLabel(item) {
    return `Данные верны (${item.confirmations_count}/${item.confirmations_required})`;
}

function openPhoto(url) {
    lightboxSrc.value = url;
}
</script>

<template>
    <div class="station-sheet">
        <div class="sheet-handle" aria-hidden="true" />

        <button class="sheet-close" type="button" aria-label="Закрыть" @click="emit('close')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>

        <div class="station-sheet-body">
            <header class="station-head">
                <div v-if="showNetwork" class="station-brand">{{ station.network }}</div>
                <h2 class="station-title">{{ displayTitle }}</h2>
                <p class="station-address">{{ station.address }}</p>
                <p v-if="station.distance_m" class="station-distance">
                    {{ Math.round(station.distance_m / 100) / 10 }} км от вас
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
                    v-if="activeFuel.sale_type_labels?.length || activeFuel.fill_volume_label"
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
                </div>
            </section>

            <section class="fuel-grid-section">
                <div class="section-label">Все виды</div>
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
                </div>
            </section>

            <section v-if="fuelReports.length" class="report-feed">
                <div class="section-label">
                    Сообщения · {{ fuelLabel(selectedFuel) }}
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
                <div class="section-label">Ожидают подтверждения</div>
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

        <footer class="station-sheet-footer">
            <button type="button" class="action-btn action-btn--primary" @click="emit('report')">
                Сообщить
            </button>
            <button type="button" class="action-btn action-btn--secondary" @click="emit('confirm')">
                Подтверждаю
            </button>
            <button type="button" class="action-btn action-btn--text" @click="emit('edit')">
                Исправить название или место
            </button>
            <button type="button" class="action-btn action-btn--text" @click="emit('closed')">
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
