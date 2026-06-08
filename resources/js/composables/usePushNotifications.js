import { ref } from 'vue';
import { apiUrl } from '../api';
import { waitForServiceWorker } from '../swRegister';

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
                throw new Error('Уведомления работают только по HTTPS (или localhost)');
            }

            throw new Error('Push не поддерживается в этом браузере');
        }

        permissionState.value = Notification.permission;

        if (Notification.permission === 'denied') {
            throw new Error(
                'Уведомления заблокированы. Нажмите на замок в адресной строке → '
                + '«Уведомления» → «Разрешить», затем обновите страницу.',
            );
        }

        if (Notification.permission !== 'granted') {
            const permission = await Notification.requestPermission();
            permissionState.value = permission;

            if (permission === 'denied') {
                throw new Error(
                    'Вы отклонили уведомления. Разрешите их в настройках сайта '
                    + '(замок в адресной строке) и обновите страницу.',
                );
            }

            if (permission !== 'granted') {
                throw new Error('Разрешение не получено- нажмите «Разрешить» в запросе браузера');
            }
        }

        const registration = await waitForServiceWorker();

        const keyRes = await fetch(apiUrl('/api/push/vapid-public-key'));
        if (!keyRes.ok) {
            throw new Error('Не удалось получить ключ уведомлений с сервера');
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
                'Не удалось подписаться на push. Обновите страницу (Ctrl+Shift+R) и попробуйте снова.',
            );
        }

        const json = subscription.toJSON();
        const saveRes = await fetch(apiUrl('/api/push/subscribe'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                endpoint: json.endpoint,
                keys: json.keys,
            }),
        });

        if (!saveRes.ok) {
            throw new Error('Сервер не сохранил подписку');
        }

        subscribed.value = true;
        localStorage.setItem('push_subscribed', '1');
        localStorage.removeItem('push_dismissed');
    }

    return { subscribed, supported, permissionState, subscribe };
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
