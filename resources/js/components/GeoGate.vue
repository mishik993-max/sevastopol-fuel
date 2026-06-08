<script setup>
import { ref } from 'vue';
import { useGeolocation } from '../composables/useGeolocation';
import { isInBbox } from '../composables/useAppSettings';
import LegalLinks from './LegalLinks.vue';
import { geoGateCookieDays } from '../composables/useGeoGate';

const emit = defineEmits(['granted', 'open-legal']);

const { locate, loading, error } = useGeolocation();
const status = ref('idle');
const showDetails = ref(false);

async function requestAccess() {
    status.value = 'idle';
    error.value = null;

    if (!window.isSecureContext) {
        status.value = 'insecure';
        return;
    }

    try {
        const pos = await locate({ userRequested: true });

        if (isInBbox(pos.lat, pos.lng)) {
            emit('granted');
            return;
        }

        status.value = 'outside';
    } catch {
        status.value = 'denied';
    }
}
</script>

<template>
    <div class="geo-gate">
        <div class="geo-gate-card">
            <div class="geo-gate-icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M12 21s7-4.35 7-11a7 7 0 1 0-14 0c0 6.65 7 11 7 11z" />
                    <circle cx="12" cy="10" r="2.5" />
                </svg>
            </div>

            <h1 class="geo-gate-title">Топливо Севастополь</h1>
            <p class="geo-gate-lead">
                Карта топлива для жителей города. Сервис работает только в Севастополе и окрестностях.
            </p>

            <ul class="geo-gate-list">
                <li>
                    <strong>Зачем геолокация?</strong>
                    Чтобы убедиться, что вы в регионе, и показать ближайшие АЗС с расстоянием.
                </li>
                <li>
                    <strong>Личных данных нет</strong>
                    Без регистрации, без имени и телефона. GPS на сервер не отправляем.
                </li>
                <li>
                    <strong>Что может попасть на сервер</strong>
                    Только то, что вы сами отправите: отчёт о топливе, обратная связь, push (если включите). Подробнее — в политике конфиденциальности.
                </li>
            </ul>

            <p v-if="status === 'outside'" class="geo-gate-error">
                Похоже, вы вне Севастополя. Сервис предназначен для региона — за пределами он недоступен.
            </p>
            <p v-else-if="status === 'denied' && error" class="geo-gate-error">
                {{ error }}
                <span class="geo-gate-error-hint">
                    В Chrome: замок слева от адреса → «Местоположение» → «Разрешить», затем обновите страницу.
                </span>
            </p>
            <p v-else-if="status === 'insecure'" class="geo-gate-error">
                Геолокация работает только по HTTPS. Откройте сайт по защищённому адресу
                (например, <strong>https://sevastopol-fuel.test</strong>), а не по IP в локальной сети.
            </p>

            <button
                v-if="status !== 'insecure'"
                type="button"
                class="btn btn-primary btn-block geo-gate-btn"
                :disabled="loading"
                @click="requestAccess"
            >
                {{ loading ? 'Определяем местоположение…' : 'Разрешить геолокацию и войти' }}
            </button>

            <button
                type="button"
                class="geo-gate-details-toggle"
                :aria-expanded="showDetails"
                @click="showDetails = !showDetails"
            >
                {{ showDetails ? 'Скрыть подробности' : 'Подробнее о приватности' }}
            </button>

            <div v-if="showDetails" class="geo-gate-details">
                <p><strong>Когда снова спросим?</strong> Экран входа не показываем {{ geoGateCookieDays }} дней после успешной проверки (cookie в браузере). Кнопка «Рядом» — GPS только по вашему нажатию.</p>
                <p><strong>Узнать, кто вы?</strong> Нет. Отчёты анонимны. Для защиты от накрутки хранится только необратимый технический код, не имя и не адрес.</p>
                <p><strong>Что видят другие?</strong> Статус топлива на АЗС, который вы сообщили, без привязки к личности.</p>
            </div>

            <p class="geo-gate-hint">
                Входя в сервис, вы соглашаетесь с условиями и политикой конфиденциальности.
                Подтверждение запоминается в cookie на {{ geoGateCookieDays }} дней.
            </p>

            <LegalLinks class="geo-gate-legal" @open="emit('open-legal', $event)" />
        </div>
    </div>
</template>
