<script setup>
import { computed } from 'vue';
import { distanceM, FUEL_TYPES, MARKER_COLORS } from '../constants';

const props = defineProps({
    stations: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
    selectedFuel: { type: String, default: 'a95' },
    userPosition: { type: Object, default: null },
    sortByDistance: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

const sortedStations = computed(() => {
    const list = props.stations.map((station) => {
        let dist = station.distance_m ?? null;

        if (dist == null && props.userPosition) {
            dist = Math.round(distanceM(
                Number(props.userPosition.lat),
                Number(props.userPosition.lng),
                Number(station.latitude),
                Number(station.longitude),
            ));
        }

        return { ...station, _sortDist: dist };
    });

    if (props.sortByDistance && list.some((s) => s._sortDist != null)) {
        return list.sort((a, b) => (a._sortDist ?? Infinity) - (b._sortDist ?? Infinity));
    }

    if (list.some((s) => s.distance_m != null)) {
        return list.sort((a, b) => (a.distance_m ?? 0) - (b.distance_m ?? 0));
    }

    return list.sort((a, b) => {
        const net = a.network.localeCompare(b.network, 'ru');

        return net !== 0 ? net : a.name.localeCompare(b.name, 'ru');
    });
});

function fuelLabel(value) {
    return FUEL_TYPES.find((f) => f.value === value)?.label ?? value;
}

function fuelFor(station) {
    return station.fuels?.find((f) => f.fuel_type === props.selectedFuel);
}

function displayTitle(station) {
    const name = (station.name || '').trim();
    const network = (station.network || '').trim();

    if (!name || name.toLowerCase() === network.toLowerCase()) {
        return network || name || 'АЗС';
    }

    return name;
}

function statusClass(station) {
    const fuel = fuelFor(station);

    return fuel ? `station-card--${fuel.status}` : 'station-card--unknown';
}

function formatDistance(meters) {
    if (meters == null) return null;

    return `${Math.round(meters / 100) / 10} км`;
}

function metaTags(station) {
    const fuel = fuelFor(station);
    const tags = [];

    for (const label of fuel?.sale_type_labels ?? []) {
        if (label === 'Нужен QR') {
            tags.push({ text: label, kind: 'qr' });
        } else if (label === 'По талонам') {
            tags.push({ text: label, kind: 'voucher' });
        } else if (label !== 'Обычный') {
            tags.push({ text: label, kind: 'meta' });
        }
    }

    if (fuel?.fill_volume_label && fuel.status !== 'none') {
        tags.push({ text: fuel.fill_volume_label, kind: 'volume' });
    }

    if (fuel?.freshness === 'fresh') {
        tags.push({ text: 'Свежее', kind: 'fresh' });
    }

    return tags;
}
</script>

<template>
    <div class="station-list-wrap">
        <p v-if="sortByDistance" class="station-list-hint">Сортировка: ближайшие сверху</p>
        <div v-if="sortedStations.length" class="station-card-grid">
            <button
                v-for="station in sortedStations"
                :key="station.id"
                type="button"
                class="station-card"
                :class="[
                    statusClass(station),
                    { 'station-card--selected': station.id === selectedId },
                ]"
                @click="emit('select', station)"
            >
                <div class="station-card-accent" />
                <div class="station-card-inner">
                    <div class="station-card-head">
                        <span class="station-card-network">{{ station.network }}</span>
                        <span
                            v-if="fuelFor(station)"
                            class="station-card-status"
                            :class="`station-card-status--${fuelFor(station).status}`"
                        >
                            {{ fuelFor(station).status_label }}
                        </span>
                    </div>

                    <h3 class="station-card-title">{{ displayTitle(station) }}</h3>
                    <p class="station-card-address">{{ station.address }}</p>

                    <div class="station-card-footer">
                        <div v-if="metaTags(station).length" class="station-card-tags">
                            <span
                                v-for="(tag, i) in metaTags(station)"
                                :key="i"
                                class="station-card-tag"
                                :class="`station-card-tag--${tag.kind}`"
                            >
                                {{ tag.text }}
                            </span>
                        </div>
                        <span v-if="formatDistance(station._sortDist)" class="station-card-distance">
                            {{ formatDistance(station._sortDist) }}
                        </span>
                        <span v-else class="station-card-fuel">{{ fuelLabel(selectedFuel) }}</span>
                    </div>
                </div>
            </button>
        </div>
        <p v-else class="station-list-empty">Нет АЗС по выбранным фильтрам</p>
    </div>
</template>
