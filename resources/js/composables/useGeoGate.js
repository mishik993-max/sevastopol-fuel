const COOKIE_KEY = 'sevastopol_geo_ok';
const LEGACY_SESSION_KEY = 'sevastopol_geo_gate';
/** Сколько не показывать экран входа после успешной проверки */
const COOKIE_DAYS = 90;

function readCookie() {
    if (typeof document === 'undefined') {
        return false;
    }

    return document.cookie.split(';').some((part) => part.trim().startsWith(`${COOKIE_KEY}=1`));
}

function writeCookie() {
    const maxAge = COOKIE_DAYS * 24 * 60 * 60;
    const secure = typeof location !== 'undefined' && location.protocol === 'https:' ? '; Secure' : '';

    document.cookie = `${COOKIE_KEY}=1; path=/; max-age=${maxAge}; SameSite=Lax${secure}`;
}

function readLocalFallback() {
    try {
        return localStorage.getItem(COOKIE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeLocalFallback() {
    try {
        localStorage.setItem(COOKIE_KEY, '1');
    } catch {
        // localStorage недоступен
    }
}

function migrateLegacySession() {
    try {
        if (sessionStorage.getItem(LEGACY_SESSION_KEY) !== '1') {
            return false;
        }

        sessionStorage.removeItem(LEGACY_SESSION_KEY);
        writeCookie();
        writeLocalFallback();

        return true;
    } catch {
        return false;
    }
}

export function readGeoGate() {
    if (readCookie() || readLocalFallback() || migrateLegacySession()) {
        return true;
    }

    return false;
}

export function saveGeoGate() {
    writeCookie();
    writeLocalFallback();
}

export function clearGeoGate() {
    try {
        document.cookie = `${COOKIE_KEY}=; path=/; max-age=0; SameSite=Lax`;
        localStorage.removeItem(COOKIE_KEY);
        sessionStorage.removeItem(LEGACY_SESSION_KEY);
    } catch {
        // ignore
    }
}

export const geoGateCookieDays = COOKIE_DAYS;
