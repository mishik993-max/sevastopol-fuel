let loadPromise = null;

export function useYandexMaps() {
    async function load() {
        const apiKey = import.meta.env.VITE_YANDEX_MAPS_API_KEY;

        if (!apiKey) {
            throw new Error('Не задан VITE_YANDEX_MAPS_API_KEY в .env. После добавления выполните: npm run build');
        }

        if (!loadPromise) {
            loadPromise = new Promise((resolve, reject) => {
                if (window.ymaps?.Map) {
                    window.ymaps.ready(() => resolve(window.ymaps));
                    return;
                }

                const script = document.createElement('script');
                // API 2.1- совместим с ключами «JavaScript API» из кабинета Яндекса
                script.src = `https://api-maps.yandex.ru/2.1/?apikey=${apiKey}&lang=ru_RU`;
                script.async = true;

                script.onload = () => {
                    if (!window.ymaps) {
                        reject(new Error('Яндекс.Карты загрузились, но ymaps недоступен. Проверьте ключ и ограничения домена.'));
                        return;
                    }

                    window.ymaps.ready(
                        () => resolve(window.ymaps),
                        (err) => reject(new Error(err?.message || 'Ошибка инициализации Яндекс.Карт')),
                    );
                };

                script.onerror = () => {
                    reject(new Error(
                        'Не удалось загрузить скрипт Яндекс.Карт. Проверьте: '
                        + '1) ключ JavaScript API активен (до 15 мин после создания); '
                        + '2) в кабинете указан домен sevastopol-fuel.test; '
                        + '3) выполнен npm run build после правки .env',
                    ));
                };

                document.head.appendChild(script);
            });
        }

        return loadPromise;
    }

    return { load };
}
