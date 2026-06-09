import { apiUrl } from '../api';
import { waitForServiceWorker } from '../swRegister';
import { getPushClientId } from './usePushClientId';

let syncTimer = null;

export const PUSH_READY_EVENT = 'sevazs-push-ready';

export function notifyPushReady() {
    window.dispatchEvent(new CustomEvent(PUSH_READY_EVENT));
}

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

export async function syncFavoritePushWatchesNow(stationIds, fuelType, attempt = 0) {
    if (localStorage.getItem('push_subscribed') !== '1') {
        return false;
    }

    const endpoint = await getPushEndpoint();

    if (!endpoint) {
        return false;
    }

    const ids = [...new Set(stationIds.map(Number).filter((id) => id > 0))].slice(0, 7);

    try {
        const res = await fetch(apiUrl('/api/push/watches'), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                endpoint,
                client_id: getPushClientId(),
                station_ids: ids,
                fuel_type: fuelType,
            }),
        });

        if (res.status === 404 && attempt < 4) {
            await new Promise((resolve) => setTimeout(resolve, 800 * (attempt + 1)));

            return syncFavoritePushWatchesNow(stationIds, fuelType, attempt + 1);
        }

        if (!res.ok) {
            console.warn('[fuel-push] sync watches failed', res.status, await res.text());

            return false;
        }

        return true;
    } catch (error) {
        console.warn('[fuel-push] sync watches error', error);

        return false;
    }
}
