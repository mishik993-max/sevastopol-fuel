import { precacheAndRoute } from 'workbox-precaching';

precacheAndRoute(self.__WB_MANIFEST);

function assetUrl(path) {
    return new URL(path, self.location.origin).href;
}

const NOTIFICATION_ICON = assetUrl('/icons/icon-192.png');
const NOTIFICATION_BADGE = assetUrl('/icons/notification-badge.png');

self.addEventListener('push', (event) => {
    let data = { title: 'Севастополь Топливо', body: 'Новое уведомление' };

    try {
        if (event.data) {
            data = { ...data, ...event.data.json() };
        }
    } catch {
        // use defaults
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon || NOTIFICATION_ICON,
            badge: NOTIFICATION_BADGE,
            tag: 'sevazs-qr',
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(clients.openWindow('/'));
});
