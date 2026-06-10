import { ref } from 'vue';
import { apiUrl } from '../api';

const stations = ref([]);
const loading = ref(false);
const error = ref(null);

export function useStations() {
    async function fetchStations(fuel = 'a95') {
        loading.value = true;
        error.value = null;
        try {
            const res = await fetch(apiUrl(`/api/stations?fuel=${fuel}`));
            if (!res.ok) throw new Error('Не удалось загрузить заправки');
            const json = await res.json();
            stations.value = json.data;
        } catch (e) {
            error.value = e.message;
        } finally {
            loading.value = false;
        }
    }

    async function fetchNearby(lat, lng, fuel = 'a95', limit = 20) {
        loading.value = true;
        error.value = null;
        try {
            const params = new URLSearchParams({ lat, lng, fuel, limit });
            const res = await fetch(apiUrl(`/api/stations/nearby?${params}`));
            if (!res.ok) throw new Error('Не удалось загрузить заправки');
            const json = await res.json();
            stations.value = json.data;
        } catch (e) {
            error.value = e.message;
        } finally {
            loading.value = false;
        }
    }

    async function fetchStation(id, fuel = 'a95') {
        const res = await fetch(apiUrl(`/api/stations/${id}?fuel=${fuel}`));
        if (!res.ok) throw new Error('Заправка не найдена');
        const json = await res.json();
        return json.data;
    }

    return { stations, loading, error, fetchStations, fetchNearby, fetchStation };
}
