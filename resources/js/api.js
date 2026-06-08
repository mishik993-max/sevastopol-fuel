/** Базовый URL приложения (учитывает /public/ в Laragon) */
export function apiUrl(path) {
    const base = document.querySelector('meta[name="app-base"]')?.getAttribute('content') || '';
    const normalized = path.startsWith('/') ? path : `/${path}`;

    return `${base}${normalized}`;
}
