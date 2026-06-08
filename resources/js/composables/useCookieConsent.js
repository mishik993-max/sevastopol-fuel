const COOKIE_KEY = 'sevastopol_cookie_ok';
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

export function readCookieConsent() {
    return readCookie() || readLocalFallback();
}

export function saveCookieConsent() {
    writeCookie();
    writeLocalFallback();
}

export const cookieConsentDays = COOKIE_DAYS;
