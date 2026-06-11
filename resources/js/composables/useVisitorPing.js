import { apiUrl } from '../api';
import { getPushClientId } from './usePushClientId';

const SESSION_KEY = 'visit_recorded';

export function recordVisitOnce() {
    if (sessionStorage.getItem(SESSION_KEY)) {
        return;
    }

    const visitorId = getPushClientId();

    fetch(apiUrl('/api/visit'), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ visitor_id: visitorId }),
    })
        .then((res) => {
            if (res.ok) {
                sessionStorage.setItem(SESSION_KEY, '1');
            }
        })
        .catch(() => {
            // не мешаем работе приложения
        });
}
