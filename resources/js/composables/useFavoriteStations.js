import { computed, ref } from 'vue';

const STORAGE_KEY = 'favorite_station_ids';
const MAX_FAVORITES = 7;

function loadIds() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        if (!Array.isArray(parsed)) {
            return [];
        }

        return [...new Set(parsed.map(Number).filter((id) => id > 0))].slice(0, MAX_FAVORITES);
    } catch {
        return [];
    }
}

const favoriteIds = ref(loadIds());

function persist() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(favoriteIds.value));
}

export function useFavoriteStations() {
    const count = computed(() => favoriteIds.value.length);

    function isFavorite(stationId) {
        return favoriteIds.value.includes(Number(stationId));
    }

    function toggle(stationId) {
        const id = Number(stationId);

        if (favoriteIds.value.includes(id)) {
            favoriteIds.value = favoriteIds.value.filter((item) => item !== id);
            persist();

            return false;
        }

        if (favoriteIds.value.length >= MAX_FAVORITES) {
            throw new Error(`Можно сохранить не больше ${MAX_FAVORITES} АЗС`);
        }

        favoriteIds.value = [...favoriteIds.value, id];
        persist();

        return true;
    }

    return {
        favoriteIds,
        count,
        isFavorite,
        toggle,
        maxFavorites: MAX_FAVORITES,
    };
}
