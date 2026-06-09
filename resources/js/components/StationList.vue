<script setup>
import { computed } from 'vue';
import { distanceM, FUEL_TYPES } from '../constants';
import { useFavoriteStations } from '../composables/useFavoriteStations';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    stations: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
    selectedFuel: { type: String, default: 'a95' },
    userPosition: { type: Object, default: null },
    sortByDistance: { type: Boolean, default: false },
    favoritesOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

const { isFavorite } = useFavoriteStations();

const STATUS_COLORS = {
    available: '#22C55E',
    low: '#EAB308',
    none: '#EF4444',
    unknown: '#6B7280',
};

const STATUS_BG = {
    available: 'rgba(34,197,94,0.12)',
    low: 'rgba(234,179,8,0.12)',
    none: 'rgba(239,68,68,0.12)',
    unknown: 'rgba(107,114,128,0.12)',
};

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

function displayTitle(station) {
    const name = (station.name || '').trim();
    const network = (station.network || '').trim();

    if (!name || name.toLowerCase() === network.toLowerCase()) {
        return network || name || 'АЗС';
    }

    if (name.toLowerCase().startsWith(network.toLowerCase())) {
        return name.slice(network.length).trim() || name;
    }

    return name;
}

function formatDistance(meters) {
    if (meters == null) return null;

    return `${Math.round(meters / 100) / 10} км`;
}

function fuelBadgeStyle(status) {
    const key = status in STATUS_COLORS ? status : 'unknown';

    return {
        color: STATUS_COLORS[key],
        backgroundColor: STATUS_BG[key],
    };
}

function freshnessShort(station) {
    const fuel = station.fuels?.find((f) => f.fuel_type === props.selectedFuel) ?? station.fuels?.[0];
    const label = fuel?.freshness_label ?? '';

    if (!label || label === 'Нет данных') {
        return '';
    }

    return label.replace('Подтверждено ', '').replace('Вероятно актуально, ', '');
}
</script>

<template>
    <div class="station-list-wrap">
        <p v-if="sortByDistance" class="station-list-hint">Сортировка: ближайшие сверху</p>
        <div v-if="sortedStations.length" class="station-list-cards">
            <button
                v-for="station in sortedStations"
                :key="station.id"
                type="button"
                class="station-list-card"
                :class="{ 'station-list-card--selected': station.id === selectedId }"
                @click="emit('select', station)"
            >
                <div class="station-list-card-head">
                    <span class="station-list-network">
                        <UiIcon
                            v-if="isFavorite(station.id)"
                            name="star"
                            :size="11"
                            color="#E8B84B"
                            fill="#E8B84B"
                            class="station-list-favorite-star"
                        />
                        {{ station.network }}
                    </span>
                    <div class="station-list-card-main">
                        <div class="station-list-title">{{ displayTitle(station) }}</div>
                        <div class="station-list-address">{{ station.address }}</div>
                    </div>
                    <div class="station-list-card-side">
                        <div v-if="formatDistance(station._sortDist)" class="station-list-distance">
                            <UiIcon name="navigation" :size="11" color="currentColor" />
                            {{ formatDistance(station._sortDist) }}
                        </div>
                        <div v-if="freshnessShort(station)" class="station-list-updated">
                            {{ freshnessShort(station) }}
                        </div>
                    </div>
                </div>
                <div class="station-list-fuels">
                    <span
                        v-for="fuel in station.fuels"
                        :key="fuel.fuel_type"
                        class="station-list-fuel-badge"
                        :style="fuelBadgeStyle(fuel.status)"
                    >
                        <i class="station-list-fuel-dot" :style="{ backgroundColor: STATUS_COLORS[fuel.status] ?? STATUS_COLORS.unknown }" />
                        {{ fuelLabel(fuel.fuel_type) }}
                    </span>
                </div>
            </button>
        </div>
        <p v-else class="station-list-empty">
            {{ favoritesOnly ? 'Нет избранных АЗС. Нажмите ★ на карточке заправки.' : 'Нет АЗС по выбранным фильтрам' }}
        </p>
        <p v-if="sortedStations.length" class="station-list-footer">
            Показано {{ sortedStations.length }} АЗС
        </p>
    </div>
</template>
