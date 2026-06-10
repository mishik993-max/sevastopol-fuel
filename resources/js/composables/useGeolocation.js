import { ref } from 'vue';

const position = ref(null);
const error = ref(null);
const loading = ref(false);
const resolved = ref(false);

function geoErrorMessage(err) {
    if (!window.isSecureContext) {
        return 'Определить местоположение можно только на защищённом сайте (адрес начинается с https). Пока покажем весь Севастополь.';
    }

    switch (err?.code) {
        case 1:
            return 'Доступ к местоположению запрещён. Разрешите его в настройках браузера.';
        case 2:
            return 'Не удалось определить местоположение. Включите геолокацию в настройках телефона.';
        case 3:
            return 'Местоположение не определилось вовремя. Выйдите на улицу и попробуйте снова.';
        default:
            return 'Не удалось определить местоположение';
    }
}

export function useGeolocation() {
    function locate({ userRequested = false } = {}) {
        loading.value = true;
        error.value = null;
        resolved.value = false;

        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                const msg = 'Этот браузер не умеет определять местоположение';
                if (userRequested) error.value = msg;
                loading.value = false;
                resolved.value = true;
                reject(new Error(msg));
                return;
            }

            if (!window.isSecureContext) {
                const msg = geoErrorMessage();
                if (userRequested) error.value = msg;
                loading.value = false;
                resolved.value = true;
                reject(new Error(msg));
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    position.value = {
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                    };
                    loading.value = false;
                    resolved.value = true;
                    resolve(position.value);
                },
                (err) => {
                    const msg = geoErrorMessage(err);
                    if (userRequested) error.value = msg;
                    loading.value = false;
                    resolved.value = true;
                    reject(err);
                },
                {
                    enableHighAccuracy: userRequested,
                    timeout: userRequested ? 20000 : 12000,
                    maximumAge: 120000,
                },
            );
        });
    }

    return { position, error, loading, resolved, locate };
}
