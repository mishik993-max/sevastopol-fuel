import { computed, onMounted, ref } from 'vue';

const DISMISS_KEY = 'pwa_install_dismissed';

function isRunningAsApp() {
    if (typeof window === 'undefined') {
        return false;
    }

    if (window.navigator.standalone === true) {
        return true;
    }

    return ['standalone', 'fullscreen', 'minimal-ui'].some(
        (mode) => window.matchMedia(`(display-mode: ${mode})`).matches,
    );
}

function isIos() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isAndroid() {
    return /android/i.test(navigator.userAgent);
}

const deferredPrompt = ref(null);
const installed = ref(isRunningAsApp());
const showHint = ref(false);
const hintMode = ref('ios');
const dismissed = ref(false);
let listenersBound = false;

const canInstall = computed(() => {
    if (isRunningAsApp() || !window.isSecureContext) {
        return false;
    }

    if (deferredPrompt.value) {
        return true;
    }

    return isIos() || isAndroid();
});

const promptVisible = computed(() => canInstall.value && !dismissed.value);

const showReopenTrigger = computed(() => canInstall.value && dismissed.value);

function bindListeners() {
    if (listenersBound || typeof window === 'undefined') {
        return;
    }

    listenersBound = true;
    installed.value = isRunningAsApp();
    dismissed.value = !!localStorage.getItem(DISMISS_KEY);

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt.value = event;
    });

    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferredPrompt.value = null;
        showHint.value = false;
        dismissed.value = false;
        localStorage.removeItem(DISMISS_KEY);
    });
}

export function usePwaInstall() {
    onMounted(bindListeners);

    async function install() {
        if (deferredPrompt.value) {
            deferredPrompt.value.prompt();
            const { outcome } = await deferredPrompt.value.userChoice;
            deferredPrompt.value = null;

            if (outcome === 'accepted') {
                installed.value = true;
            }

            return;
        }

        if (isIos()) {
            hintMode.value = 'ios';
            showHint.value = true;
            return;
        }

        if (isAndroid()) {
            hintMode.value = 'android';
            showHint.value = true;
        }
    }

    function dismissPrompt() {
        dismissed.value = true;
        localStorage.setItem(DISMISS_KEY, '1');
    }

    function reopenPrompt() {
        dismissed.value = false;
        localStorage.removeItem(DISMISS_KEY);
    }

    function closeHint() {
        showHint.value = false;
    }

    return {
        canInstall,
        promptVisible,
        showReopenTrigger,
        showHint,
        hintMode,
        install,
        dismissPrompt,
        reopenPrompt,
        closeHint,
    };
}
