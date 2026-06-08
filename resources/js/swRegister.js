export function serviceWorkerScope() {
    const base = document.querySelector('meta[name="app-base"]')?.getAttribute('content') || '';

    if (!base) {
        return '/';
    }

    try {
        const pathname = new URL(base).pathname;

        return pathname.endsWith('/') ? pathname : `${pathname}/`;
    } catch {
        return '/';
    }
}

function scopeUrl() {
    return new URL(serviceWorkerScope(), window.location.origin).href;
}

async function findRegistration() {
    const registrations = await navigator.serviceWorker.getRegistrations();
    const target = scopeUrl();

    return registrations.find((reg) => reg.scope === target) ?? null;
}

async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return null;
    }

    const existing = await findRegistration();
    if (existing) {
        return existing;
    }

    const scope = serviceWorkerScope();
    const candidates = ['/sw.js', '/build/sw.js'];

    for (const url of candidates) {
        try {
            return await navigator.serviceWorker.register(url, {
                scope,
                type: 'module',
            });
        } catch (error) {
            console.warn(`Service Worker: не удалось зарегистрировать ${url}`, error);
        }
    }

    return null;
}

export const swRegistrationReady = registerServiceWorker();

export async function waitForServiceWorker(timeoutMs = 20000) {
    const deadline = Date.now() + timeoutMs;
    let registration = null;

    try {
        registration = await swRegistrationReady;
    } catch {
        registration = null;
    }

    while (!registration && Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 250));
        registration = await findRegistration();
    }

    if (!registration) {
        throw new Error(
            'Service Worker не загружен. Выполните npm run build, обновите страницу (Ctrl+Shift+R) '
            + 'и подождите несколько секунд.',
        );
    }

    await Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, reject) => {
            setTimeout(
                () => reject(new Error(
                    'Service Worker не успел активироваться. Подождите 5 сек и нажмите «Включить» снова.',
                )),
                Math.max(1000, deadline - Date.now()),
            );
        }),
    ]);

    return registration;
}
