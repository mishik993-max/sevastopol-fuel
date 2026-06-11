import { computed, ref } from 'vue';

const STORAGE_KEY = 'theme';

export function readStoredTheme() {
    const stored = localStorage.getItem(STORAGE_KEY);

    return stored === 'light' || stored === 'dark' ? stored : 'dark';
}

export function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;

    const meta = document.querySelector('meta[name="theme-color"]');

    if (meta) {
        meta.setAttribute('content', theme === 'light' ? '#f4f1eb' : '#0a0807');
    }
}

export function initTheme() {
    applyTheme(readStoredTheme());
}

export function useTheme() {
    const theme = ref(document.documentElement.dataset.theme === 'light' ? 'light' : readStoredTheme());

    function setTheme(next) {
        theme.value = next;
        localStorage.setItem(STORAGE_KEY, next);
        applyTheme(next);
    }

    function toggleTheme() {
        setTheme(theme.value === 'dark' ? 'light' : 'dark');
    }

    const isDark = computed(() => theme.value === 'dark');

    return {
        theme,
        isDark,
        setTheme,
        toggleTheme,
    };
}
