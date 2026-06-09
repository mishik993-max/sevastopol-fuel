<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import UiIcon from './UiIcon.vue';
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
    <div class="geo-gate geo-gate--figma">
        <svg class="geo-gate-bg" viewBox="0 0 393 852" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <rect width="393" height="852" fill="#0A0807" />
            <path d="M0 520 C80 490 160 500 240 510 C310 518 360 505 393 498 L393 852 L0 852 Z" fill="#060D18" opacity="0.55" />
            <path d="M20 560 Q100 550 196 560 Q290 570 373 560" stroke="rgba(80,130,200,0.08)" stroke-width="1.2" fill="none" />
            <path d="M25 590 Q105 580 196 590 Q287 600 368 590" stroke="rgba(80,130,200,0.1)" stroke-width="1.2" fill="none" />
            <path d="M30 620 Q110 610 196 620 Q282 630 363 620" stroke="rgba(80,130,200,0.12)" stroke-width="1.2" fill="none" />
            <circle cx="60" cy="180" r="10" fill="#22C55E" opacity="0.25" />
            <circle cx="300" cy="140" r="10" fill="#EAB308" opacity="0.25" />
            <circle cx="180" cy="240" r="10" fill="#22C55E" opacity="0.25" />
            <circle cx="330" cy="290" r="10" fill="#EF4444" opacity="0.25" />
        </svg>

        <div class="geo-gate-content">
            <div class="geo-gate-logo-wrap">
                <div class="geo-gate-logo">
                    <AppIcon :size="88" />
                </div>
            </div>

            <h1 class="geo-gate-title">Топливо</h1>
            <p class="geo-gate-subtitle">Севастополь · sevazs.ru</p>

            <div class="geo-gate-card">
                <div class="geo-gate-card-head">
                    <div class="geo-gate-card-icon" aria-hidden="true">
                        <UiIcon name="map-pin" :size="18" color="#E8B84B" />
                    </div>
                    <div>
                        <div class="geo-gate-card-title">Нужен доступ к геолокации</div>
                        <p class="geo-gate-card-text">
                            Приложение показывает только АЗС Севастополя. Геолокация помогает найти ближайшие станции и сортировать их по расстоянию.
                        </p>
                    </div>
                </div>
                <div class="geo-gate-card-row">
                    <UiIcon name="shield" :size="14" color="#22C55E" />
                    Не передаём данные третьим лицам
                </div>
                <div class="geo-gate-card-row">
                    <UiIcon name="anchor" :size="14" color="#E8B84B" />
                    Только для пользователей Севастополя
                </div>
            </div>

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
                Геолокация работает только по HTTPS. Откройте сайт по защищённому адресу, а не по IP в локальной сети.
            </p>

            <button
                v-if="status !== 'insecure'"
                type="button"
                class="geo-gate-cta"
                :class="{ 'geo-gate-cta--loading': loading }"
                :disabled="loading"
                @click="requestAccess"
            >
                <UiIcon name="map-pin" :size="16" :color="loading ? '#22C55E' : '#0A0807'" />
                {{ loading ? 'Определяем местоположение…' : 'Разрешить геолокацию' }}
            </button>

            <p class="geo-gate-hint">Можно изменить в любой момент в настройках браузера</p>

            <button
                type="button"
                class="geo-gate-details-toggle"
                :aria-expanded="showDetails"
                @click="showDetails = !showDetails"
            >
                {{ showDetails ? 'Скрыть подробности' : 'Подробнее о приватности' }}
            </button>

            <div v-if="showDetails" class="geo-gate-details">
                <p><strong>Когда снова спросим?</strong> Экран входа не показываем {{ geoGateCookieDays }} дней после успешной проверки (cookie в браузере).</p>
                <p><strong>Что видят другие?</strong> Статус топлива на АЗС без привязки к личности.</p>
            </div>

            <LegalLinks class="geo-gate-legal" @open="emit('open-legal', $event)" />
        </div>
    </div>
</template>
