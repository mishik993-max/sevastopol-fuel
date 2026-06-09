/** Базовый URL приложения (учитывает /public/ в Laragon) */
export function apiUrl(path) {
    const base = document.querySelector('meta[name="app-base"]')?.getAttribute('content') || '';
    const normalized = path.startsWith('/') ? path : `/${path}`;

    return `${base}${normalized}`;
}

/** @returns {Promise<Record<string, unknown>>} */
export async function parseApiResponse(res) {
    const contentType = res.headers.get('content-type') || '';
    const text = await res.text();

    if (contentType.includes('application/json') || text.startsWith('{') || text.startsWith('[')) {
        try {
            return JSON.parse(text);
        } catch {
            throw new Error('Сервер вернул некорректный JSON');
        }
    }

    if (text.startsWith('<!DOCTYPE') || text.startsWith('<html')) {
        throw new Error(
            'API не обновлён на сервере. Выполните: git pull && php artisan route:clear && php artisan route:cache',
        );
    }

    throw new Error(text.slice(0, 160) || `Ошибка запроса (${res.status})`);
}
