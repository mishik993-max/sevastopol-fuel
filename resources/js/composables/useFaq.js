import { ref } from 'vue';
import { apiUrl, parseApiResponse } from '../api';

const items = ref([]);
const loading = ref(false);
const loaded = ref(false);
const error = ref(null);

export function useFaq() {
    async function loadFaq({ force = false } = {}) {
        if (loaded.value && !force) {
            return items.value;
        }

        loading.value = true;
        error.value = null;

        try {
            const res = await fetch(apiUrl('/api/faq'));
            const json = await parseApiResponse(res);

            if (!res.ok) throw new Error(json.message || 'Не удалось загрузить FAQ');

            items.value = json.data || [];
            loaded.value = true;

            return items.value;
        } catch (e) {
            error.value = e.message;
            items.value = [];

            return [];
        } finally {
            loading.value = false;
        }
    }

    return {
        items,
        loading,
        error,
        loadFaq,
    };
}
