import { computed, ref } from 'vue';
import { apiUrl } from '../api';
import {
    NETWORK_PRIORITY as DEFAULT_NETWORKS,
    SEVASTOPOL_BBOX as DEFAULT_BBOX,
    SEVASTOPOL_CENTER as DEFAULT_CENTER,
} from '../constants';

const loaded = ref(false);
const settings = ref({});

export async function loadAppSettings() {
    if (loaded.value) {
        return settings.value;
    }

    try {
        const res = await fetch(apiUrl('/api/settings'));
        const json = await res.json();
        settings.value = json.data || {};
    } catch {
        settings.value = {};
    }

    loaded.value = true;

    return settings.value;
}

export function useAppSettings() {
    const geoBbox = computed(() => settings.value.geo_bbox ?? DEFAULT_BBOX);

    const mapCenter = computed(() => {
        const c = settings.value.map_center;

        return c ? [Number(c.lat), Number(c.lng)] : DEFAULT_CENTER;
    });

    const networkPriority = computed(() => (
        settings.value.network_priority?.length
            ? settings.value.network_priority
            : DEFAULT_NETWORKS
    ));

    const qrReminders = computed(() => settings.value.qr_reminders ?? []);

    const qrReminderLabel = computed(() => {
        const times = qrReminders.value.map((r) => r.time).filter(Boolean);

        return times.length ? `Напоминания в ${times.join(', ')}` : 'Напоминания о QR';
    });

    const freshnessFreshMinutes = computed(() => settings.value.freshness_fresh_minutes ?? 15);

    return {
        settings,
        loaded,
        loadAppSettings,
        geoBbox,
        mapCenter,
        networkPriority,
        qrReminders,
        qrReminderLabel,
        freshnessFreshMinutes,
    };
}

export function isInBbox(lat, lng, bbox) {
    const b = bbox ?? settings.value.geo_bbox ?? DEFAULT_BBOX;
    const la = Number(lat);
    const ln = Number(lng);

    return la >= b.south
        && la <= b.north
        && ln >= b.west
        && ln <= b.east;
}
