import { precacheAndRoute } from 'workbox-precaching';

precacheAndRoute(self.__WB_MANIFEST);

function assetUrl(path) {
    return new URL(path, self.location.origin).href;
}

function resolveNotificationUrl(url) {
    if (!url || typeof url !== 'string') {
        return assetUrl('/');
    }

    const trimmed = url.trim();

    if (trimmed.startsWith('https://') || trimmed.startsWith('http://')) {
        return trimmed;
    }

    if (trimmed.startsWith('/')) {
        return assetUrl(trimmed);
    }

    return assetUrl('/');
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

    const targetUrl = resolveNotificationUrl(data.url);

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon || NOTIFICATION_ICON,
            badge: NOTIFICATION_BADGE,
            tag: 'sevazs-qr',
            data: { url: targetUrl },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data?.url || assetUrl('/');

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            if (targetUrl.startsWith(self.location.origin)) {
                for (const client of windowClients) {
                    if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                        if ('navigate' in client) {
                            return client.navigate(targetUrl).then((focused) => focused?.focus() ?? client.focus());
                        }

                        return client.focus();
                    }
                }
            }

            return clients.openWindow(targetUrl);
        }),
    );
});
