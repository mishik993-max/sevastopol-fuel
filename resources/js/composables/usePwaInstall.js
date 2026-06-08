import { computed, onMounted, onUnmounted, ref } from 'vue';

function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
}

function isIos() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isAndroid() {
    return /android/i.test(navigator.userAgent);
}

export function usePwaInstall() {
    const deferredPrompt = ref(null);
    const installed = ref(isStandalone());
    const showHint = ref(false);
    const hintMode = ref('ios');

    let promptHandler = null;
    let installedHandler = null;

    const canInstall = computed(() => {
        if (installed.value || !window.isSecureContext) {
            return false;
        }

        if (deferredPrompt.value) {
            return true;
        }

        return isIos() || isAndroid();
    });

    const usesNativePrompt = computed(() => !!deferredPrompt.value);

    onMounted(() => {
        installed.value = isStandalone();

        promptHandler = (event) => {
            event.preventDefault();
            deferredPrompt.value = event;
        };

        installedHandler = () => {
            installed.value = true;
            deferredPrompt.value = null;
            showHint.value = false;
        };

        window.addEventListener('beforeinstallprompt', promptHandler);
        window.addEventListener('appinstalled', installedHandler);
    });

    onUnmounted(() => {
        if (promptHandler) {
            window.removeEventListener('beforeinstallprompt', promptHandler);
        }

        if (installedHandler) {
            window.removeEventListener('appinstalled', installedHandler);
        }
    });

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

    function closeHint() {
        showHint.value = false;
    }

    return {
        canInstall,
        usesNativePrompt,
        showHint,
        hintMode,
        install,
        closeHint,
    };
}
