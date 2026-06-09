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

/** @param {Response} res @param {Record<string, unknown>} [json] */
export function apiErrorMessage(res, json = {}) {
    if (res.status === 429) {
        const retry = res.headers.get('Retry-After');

        return retry
            ? `Слишком много запросов. Подождите ${retry} сек.`
            : 'Слишком много запросов. Подождите минуту и попробуйте снова.';
    }

    const errors = json.errors;

    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];

        if (first) {
            return String(first);
        }
    }

    if (json.message) {
        return String(json.message);
    }

    return `Ошибка запроса (${res.status})`;
}
