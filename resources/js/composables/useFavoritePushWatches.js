import { apiUrl } from '../api';
import { waitForServiceWorker } from '../swRegister';
import { getPushClientId } from './usePushClientId';

let syncTimer = null;

export async function getPushEndpoint() {
    if (!('serviceWorker' in navigator) || Notification.permission !== 'granted') {
        return null;
    }

    try {
        const registration = await waitForServiceWorker();
        const subscription = await registration?.pushManager?.getSubscription();

        return subscription?.endpoint ?? null;
    } catch {
        return null;
    }
}

export function syncFavoritePushWatches(stationIds, fuelType) {
    clearTimeout(syncTimer);

    syncTimer = setTimeout(() => {
        syncFavoritePushWatchesNow(stationIds, fuelType);
    }, 500);
}

async function syncFavoritePushWatchesNow(stationIds, fuelType) {
    if (localStorage.getItem('push_subscribed') !== '1') {
        return;
    }

    const endpoint = await getPushEndpoint();

    if (!endpoint) {
        return;
    }

    const ids = [...new Set(stationIds.map(Number).filter((id) => id > 0))].slice(0, 7);

    try {
        await fetch(apiUrl('/api/push/watches'), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                endpoint,
                client_id: getPushClientId(),
                station_ids: ids,
                fuel_type: fuelType,
            }),
        });
    } catch {
        // фоновая синхронизация — ошибки не показываем
    }
}
