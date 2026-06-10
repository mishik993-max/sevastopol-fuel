import { ref } from 'vue';
import { apiUrl } from '../api';
import { waitForServiceWorker } from '../swRegister';
import { getPushClientId } from './usePushClientId';
import { notifyPushReady } from './useFavoritePushWatches';

const subscribed = ref(false);
const permissionState = ref(
    typeof Notification !== 'undefined' ? Notification.permission : 'default',
);

const supported = ref(
    window.isSecureContext
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window,
);

export function usePushNotifications() {
    async function subscribe() {
        if (!supported.value) {
            if (!window.isSecureContext) {
                throw new Error('Уведомления работают только на защищённом сайте (адрес начинается с https)');
            }

            throw new Error('Этот браузер не умеет показывать уведомления');
        }

        permissionState.value = Notification.permission;

        if (Notification.permission === 'denied') {
            throw new Error(
                'Уведомления отключены. Нажмите на значок замка слева от адреса сайта, '
                + 'выберите «Уведомления» и «Разрешить», затем обновите страницу.',
            );
        }

        if (Notification.permission !== 'granted') {
            const permission = await Notification.requestPermission();
            permissionState.value = permission;

            if (permission === 'denied') {
                throw new Error(
                    'Вы отказались от уведомлений. Разрешить их можно через значок замка '
                    + 'слева от адреса сайта, потом обновите страницу.',
                );
            }

            if (permission !== 'granted') {
                throw new Error('Чтобы получать уведомления, нажмите «Разрешить» в окне браузера');
            }
        }

        const registration = await waitForServiceWorker();

        const keyRes = await fetch(apiUrl('/api/push/vapid-public-key'));
        if (!keyRes.ok) {
            throw new Error('Не удалось включить уведомления. Попробуйте позже.');
        }

        const { public_key: publicKey } = await keyRes.json();

        if (!publicKey) {
            throw new Error('VAPID ключ не настроен на сервере (php artisan webpush:vapid)');
        }

        let subscription;
        try {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey),
            });
        } catch (e) {
            throw new Error(
                'Не удалось включить уведомления. Обновите страницу и попробуйте снова.',
            );
        }

        const json = subscription.toJSON();
        const saveRes = await fetch(apiUrl('/api/push/subscribe'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                endpoint: json.endpoint,
                client_id: getPushClientId(),
                keys: json.keys,
            }),
        });

        if (!saveRes.ok) {
            throw new Error('Не удалось сохранить. Попробуйте позже.');
        }

        subscribed.value = true;
        localStorage.setItem('push_subscribed', '1');
        localStorage.removeItem('push_dismissed');
        notifyPushReady();
    }

    async function syncExistingSubscription() {
        if (!supported.value || Notification.permission !== 'granted') {
            return;
        }

        try {
            const registration = await waitForServiceWorker();
            const subscription = await registration.pushManager.getSubscription();

            if (!subscription) {
                if (localStorage.getItem('push_subscribed')) {
                    localStorage.removeItem('push_subscribed');
                }
                subscribed.value = false;

                return;
            }

            const json = subscription.toJSON();
            const saveRes = await fetch(apiUrl('/api/push/subscribe'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    endpoint: json.endpoint,
                    client_id: getPushClientId(),
                    keys: json.keys,
                }),
            });

            if (saveRes.ok) {
                subscribed.value = true;
                localStorage.setItem('push_subscribed', '1');
                notifyPushReady();
            }
        } catch {
            // ignore background sync errors
        }
    }

    return { subscribed, supported, permissionState, subscribe, syncExistingSubscription };
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}
