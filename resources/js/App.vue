<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import MapView from './components/MapView.vue';
import StationList from './components/StationList.vue';
import StationCard from './components/StationCard.vue';
import ReportForm from './components/ReportForm.vue';
import ConfirmButton from './components/ConfirmButton.vue';
import AddStationForm from './components/AddStationForm.vue';
import EditStationForm from './components/EditStationForm.vue';
import PushPrompt from './components/PushPrompt.vue';
import CommunityBanner from './components/CommunityBanner.vue';
import PwaInstallButton from './components/PwaInstallButton.vue';
import GeoGate from './components/GeoGate.vue';
import OnboardingTour from './components/OnboardingTour.vue';
import HelpGuide from './components/HelpGuide.vue';
import StatsPanel from './components/StatsPanel.vue';
import FeedbackForm from './components/FeedbackForm.vue';
import LegalDocs from './components/LegalDocs.vue';
import LegalLinks from './components/LegalLinks.vue';
import AdminPanel from './components/AdminPanel.vue';
import CookieBanner from './components/CookieBanner.vue';
import UiIcon from './components/UiIcon.vue';
import { FUEL_TYPES } from './constants';
import { apiUrl } from './api';
import { useStations } from './composables/useStations';
import { useGeolocation } from './composables/useGeolocation';
import { readGeoGate, saveGeoGate } from './composables/useGeoGate';
import { readCookieConsent, saveCookieConsent } from './composables/useCookieConsent';
import { isInBbox, loadAppSettings, useAppSettings } from './composables/useAppSettings';
import { useShare } from './composables/useShare';
import { useFavoriteStations } from './composables/useFavoriteStations';
import { syncFavoritePushWatches, PUSH_READY_EVENT } from './composables/useFavoritePushWatches';
import { usePushNotifications } from './composables/usePushNotifications';
import { recordVisitOnce } from './composables/useVisitorPing';
import { useTheme } from './composables/useTheme';

const { stations, loading, error, fetchStations, fetchNearby, fetchStation } = useStations();
const { position, locate, loading: geoLoading, resolved: geoResolved, error: geoError } = useGeolocation();
const isAdminRoute = ref(window.location.pathname.replace(/\/$/, '') === '/admin');
const geoAccessGranted = ref(readGeoGate());
const geoNotice = ref(null);
const showOnboarding = ref(false);
const showHelp = ref(false);
const showStats = ref(false);
const showFeedback = ref(false);
const showLegal = ref(false);
const legalDocId = ref('privacy');
const settingsReady = ref(false);
const { networkPriority, mapCenter } = useAppSettings();
const { canShare, share } = useShare();
const { favoriteIds, count: favoriteCount, isFavorite } = useFavoriteStations();
const { subscribed: pushSubscribed } = usePushNotifications();
const { isDark, toggleTheme } = useTheme();
const shareLoading = ref(false);
const pendingDeepLinkStationId = ref(null);

const selectedFuel = ref('a95');
const selectedNetwork = ref(null);
const selectedSaleType = ref(null);
const canisterOnly = ref(false);
const favoritesOnly = ref(false);
const filtersOpen = ref(false);
const cookieConsentGranted = ref(readCookieConsent());
const selectedStation = ref(null);
const showReport = ref(false);
const showConfirm = ref(false);
const showAddStation = ref(false);
const showEditStation = ref(false);
const mapPickMode = ref(false);
const pickCoords = ref(null);
const mode = ref('all');
const viewMode = ref('map');
const mapLayer = ref('fuel');
const mapViewRef = ref(null);
const bottomSheetEl = ref(null);
const sheetHeightPx = ref(0);

const COOKIE_BANNER_LIFT_PX = 118;

const cookieBannerLift = computed(() => (
    cookieConsentGranted.value ? 0 : COOKIE_BANNER_LIFT_PX
));

const mapFabBottomStyle = computed(() => {
    const lift = cookieBannerLift.value;

    if (sheetHeightPx.value > 0) {
        return { bottom: `calc(${sheetHeightPx.value + 16 + lift}px + var(--safe-bottom))` };
    }

    if (lift > 0) {
        return { bottom: `calc(${12 + lift}px + var(--safe-bottom))` };
    }

    return undefined;
});

let sheetObserver = null;
let sheetDebounceTimer = null;

function debounceSheetHeight(fn, wait) {
    return () => {
        clearTimeout(sheetDebounceTimer);
        sheetDebounceTimer = setTimeout(fn, wait);
    };
}

function teardownSheetObserver() {
    clearTimeout(sheetDebounceTimer);
    sheetObserver?.disconnect();
    sheetObserver = null;
}

function observeSheet(el) {
    teardownSheetObserver();
    if (!el) {
        sheetHeightPx.value = 0;
        return;
    }

    const applyHeight = () => {
        sheetHeightPx.value = el.offsetHeight;
    };

    applyHeight();
    const debouncedApply = debounceSheetHeight(applyHeight, 100);
    sheetObserver = new ResizeObserver(debouncedApply);
    sheetObserver.observe(el);
}

watch(
    [selectedStation, showReport, showConfirm, showEditStation],
    async ([station, report, confirm, edit]) => {
        teardownSheetObserver();
        if (!station || report || confirm || edit) {
            sheetHeightPx.value = 0;
            return;
        }
        await nextTick();
        observeSheet(bottomSheetEl.value);
    },
    { flush: 'post' },
);

watch(viewMode, async (mode) => {
    if (mode !== 'map') {
        return;
    }

    await nextTick();
    mapViewRef.value?.invalidateSize?.();
});

watch(filtersOpen, async () => {
    if (viewMode.value !== 'map') {
        return;
    }

    await nextTick();
    requestAnimationFrame(() => mapViewRef.value?.invalidateSize?.());
});

onUnmounted(() => {
    window.removeEventListener(PUSH_READY_EVENT, onPushReady);
    teardownSheetObserver();
});

const availableNetworks = computed(() => {
    const counts = new Map();

    for (const station of stations.value) {
        counts.set(station.network, (counts.get(station.network) || 0) + 1);
    }

    return [...counts.entries()]
        .sort((a, b) => {
            const ai = networkPriority.value.indexOf(a[0]);
            const bi = networkPriority.value.indexOf(b[0]);
            const ap = ai === -1 ? 999 : ai;
            const bp = bi === -1 ? 999 : bi;

            if (ap !== bp) return ap - bp;

            return b[1] - a[1];
        })
        .map(([network, count]) => ({ value: network, label: network, count }));
});

const filteredStations = computed(() => {
    let list = stations.value;

    if (selectedNetwork.value) {
        list = list.filter((s) => s.network === selectedNetwork.value);
    }

    if (selectedSaleType.value) {
        list = list.filter((s) => {
            const fuel = s.fuels?.find((f) => f.fuel_type === selectedFuel.value) ?? s.fuels?.[0];

            return fuel?.sale_types?.includes(selectedSaleType.value);
        });
    }

    if (canisterOnly.value) {
        list = list.filter((s) => {
            const fuel = s.fuels?.find((f) => f.fuel_type === selectedFuel.value) ?? s.fuels?.[0];

            return fuel?.canister_policy === 'allowed';
        });
    }

    if (favoritesOnly.value) {
        list = list.filter((s) => isFavorite(s.id));
    }

    return list;
});

function initApp() {
    applyQueryFuelFromUrl();
    fetchStations(selectedFuel.value);
    recordVisitOnce();
    locate().catch(() => {
        // геолокация для карты необязательна после прохождения геозоны
    });
}

function applyQueryFuelFromUrl() {
    const fuel = new URLSearchParams(window.location.search).get('fuel');

    if (fuel && FUEL_TYPES.some((item) => item.value === fuel)) {
        selectedFuel.value = fuel;
    }
}

function readDeepLinkStationId() {
    const stationId = Number(new URLSearchParams(window.location.search).get('station'));

    return stationId > 0 ? stationId : null;
}

function clearDeepLinkFromUrl() {
    const url = new URL(window.location.href);

    if (!url.searchParams.has('station') && !url.searchParams.has('fuel')) {
        return;
    }

    url.searchParams.delete('station');
    url.searchParams.delete('fuel');
    const next = url.pathname + (url.search || '') + url.hash;
    window.history.replaceState({}, '', next);
}

async function openDeepLinkStation(stationId) {
    viewMode.value = 'map';

    let station = stations.value.find((item) => item.id === stationId);

    if (!station) {
        try {
            station = await fetchStation(stationId, selectedFuel.value);
        } catch {
            return;
        }
    }

    await selectStation(station);
    clearDeepLinkFromUrl();
}

function syncPushWatches() {
    syncFavoritePushWatches(favoriteIds.value, selectedFuel.value);
}

function onGeoGateGranted() {
    saveGeoGate();
    geoAccessGranted.value = true;
    initApp();
    syncPushWatches();

    if (!localStorage.getItem('onboarding_done')) {
        showOnboarding.value = true;
    }
}

function finishOnboarding() {
    showOnboarding.value = false;
}

function onTourPrepareStep(step) {
    if (step.expandFilters) {
        filtersOpen.value = true;
    }

    if (step.viewMode) {
        viewMode.value = step.viewMode;
    }
}

function startTour() {
    showHelp.value = false;
    showOnboarding.value = true;
    localStorage.removeItem('onboarding_done');
}

onMounted(async () => {
    window.addEventListener(PUSH_READY_EVENT, onPushReady);
    pendingDeepLinkStationId.value = readDeepLinkStationId();

    if (!isAdminRoute.value) {
        await loadAppSettings();
        settingsReady.value = true;
    }

    if (geoAccessGranted.value && !isAdminRoute.value) {
        initApp();
        syncPushWatches();

        if (!localStorage.getItem('onboarding_done')) {
            showOnboarding.value = true;
        }
    }
});

onUnmounted(() => {
    window.removeEventListener(PUSH_READY_EVENT, onPushReady);
});

watch(stations, async (list) => {
    if (!pendingDeepLinkStationId.value || list.length === 0) {
        return;
    }

    const stationId = pendingDeepLinkStationId.value;
    pendingDeepLinkStationId.value = null;
    await openDeepLinkStation(stationId);
});

watch([favoriteIds, selectedFuel], syncPushWatches, { deep: true });

watch(pushSubscribed, (active) => {
    if (active) {
        syncPushWatches();
    }
});

function onPushReady() {
    syncPushWatches();
}

watch([selectedNetwork, selectedSaleType, canisterOnly], () => {
    if (
        selectedStation.value
        && !filteredStations.value.some((s) => s.id === selectedStation.value.id)
    ) {
        selectedStation.value = null;
    }
});

watch(favoriteCount, (count) => {
    if (count === 0) {
        favoritesOnly.value = false;
    }
});

function onCookieAccept() {
    saveCookieConsent();
    cookieConsentGranted.value = true;
}

function onCookieDecline() {
    cookieConsentGranted.value = true;
}

watch(selectedFuel, () => {
    if (mode.value === 'nearby' && position.value) {
        fetchNearby(position.value.lat, position.value.lng, selectedFuel.value);
    } else {
        fetchStations(selectedFuel.value);
    }
});

async function selectStation(station) {
    selectedStation.value = station;
    try {
        selectedStation.value = await fetchStation(station.id, selectedFuel.value);
    } catch {
        // оставляем данные из списка
    }
}

async function nearby() {
    geoNotice.value = null;
    try {
        await locate({ userRequested: true });

        if (!position.value || !isInBbox(position.value.lat, position.value.lng)) {
            geoNotice.value = 'Вы не в Севастополе, показаны все заправки';
            mode.value = 'all';
            await fetchStations(selectedFuel.value);
            return;
        }

        mode.value = 'nearby';
        await fetchNearby(position.value.lat, position.value.lng, selectedFuel.value);
    } catch {
        // error shown via geo
    }
}

function showAll() {
    mode.value = 'all';
    fetchStations(selectedFuel.value);
}

async function onReportDone(data) {
    showReport.value = false;
    selectedStation.value = data;
    refreshList();
}

async function onConfirmDone(data) {
    showConfirm.value = false;
    selectedStation.value = data;
    refreshList();
}

function refreshList() {
    if (mode.value === 'nearby' && position.value) {
        fetchNearby(position.value.lat, position.value.lng, selectedFuel.value);
    } else {
        fetchStations(selectedFuel.value);
    }
}

function reloadPage() {
    window.location.reload();
}

function toggleMapLayer() {
    mapLayer.value = mapLayer.value === 'fuel' ? 'queue' : 'fuel';
    nextTick(() => mapViewRef.value?.invalidateSize?.());
}

async function shareApp() {
    if (shareLoading.value) {
        return;
    }

    shareLoading.value = true;

    try {
        const result = await share();

        if (result.ok && result.method === 'clipboard') {
            window.alert('Ссылка скопирована');
        }
    } catch (error) {
        window.alert(error.message || 'Не удалось поделиться');
    } finally {
        shareLoading.value = false;
    }
}

function openAddStation() {
    selectedStation.value = null;
    showReport.value = false;
    showConfirm.value = false;
    pickCoords.value = null;
    mapPickMode.value = false;
    showAddStation.value = true;
    viewMode.value = 'map';
}

function closeAddStation() {
    showAddStation.value = false;
    mapPickMode.value = false;
    pickCoords.value = null;
}

function onPickStart() {
    mapPickMode.value = true;
}

function onPickStop() {
    mapPickMode.value = false;
}

function onMapPick(coords) {
    pickCoords.value = { lat: coords.lat, lng: coords.lng };
}

async function onStationAdded(station) {
    closeAddStation();
    await refreshList();
    selectedStation.value = station;
}

function openEditStation() {
    showReport.value = false;
    showConfirm.value = false;
    showAddStation.value = false;
    pickCoords.value = null;
    mapPickMode.value = false;
    showEditStation.value = true;
    viewMode.value = 'map';
}

function closeEditStation() {
    showEditStation.value = false;
    mapPickMode.value = false;
    pickCoords.value = null;
}

async function onEditProposed(station) {
    closeEditStation();
    selectedStation.value = station;
    await refreshList();
}

async function onConfirmCorrection(correction) {
    if (!selectedStation.value) return;

    const ok = window.confirm(
        `Подтвердить исправление?\n\n${correction.field_label}: «${correction.proposed_value}»`,
    );

    if (!ok) return;

    try {
        const res = await fetch(
            apiUrl(`/api/stations/${selectedStation.value.id}/corrections/${correction.id}/confirm?fuel=${selectedFuel.value}`),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            },
        );
        const json = await res.json();

        if (!res.ok) throw new Error(json.message || 'Ошибка');

        window.alert(json.message);
        selectedStation.value = json.data.station;
        await refreshList();
    } catch (e) {
        window.alert(e.message || 'Не удалось подтвердить');
    }
}

async function onStationClosed() {
    if (!selectedStation.value) return;

    const count = selectedStation.value.closure_reports_count ?? 0;
    const required = selectedStation.value.closure_reports_required ?? 5;

    const ok = window.confirm(
        'Сообщить, что эта заправка больше не работает?\n\n'
        + `Сейчас ${count} из ${required} пометок. После ${required} сообщений от разных людей АЗС исчезнет с карты.`,
    );

    if (!ok) return;

    const stationId = selectedStation.value.id;

    try {
        const res = await fetch(apiUrl(`/api/stations/${stationId}/close`), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({}),
        });
        const json = await res.json();

        if (!res.ok) throw new Error(json.message || 'Ошибка');

        window.alert(json.message);

        if (json.data?.deactivated) {
            selectedStation.value = null;
            refreshList();
        } else {
            try {
                selectedStation.value = await fetchStation(stationId, selectedFuel.value);
            } catch {
                refreshList();
            }
        }
    } catch (e) {
        window.alert(e.message || 'Не удалось отправить');
    }
}
</script>

<template>
    <AdminPanel v-if="isAdminRoute" />

    <div v-else-if="!settingsReady" class="app-boot">Загрузка…</div>

    <GeoGate
        v-else-if="!geoAccessGranted"
        @granted="onGeoGateGranted"
        @open-legal="(id) => { legalDocId = id; showLegal = true; }"
    />

    <div v-else class="app" :class="{ 'app--cookie-pending': !cookieConsentGranted }">
        <header class="topbar">
            <div class="topbar-row topbar-row--title">
                <h1>Топливо</h1>
                <div class="topbar-actions">
                    <button
                        type="button"
                        class="topbar-icon-btn topbar-icon-btn--theme"
                        :title="isDark ? 'Светлая тема' : 'Тёмная тема'"
                        :aria-label="isDark ? 'Включить светлую тему' : 'Включить тёмную тему'"
                        @click="toggleTheme"
                    >
                        <UiIcon :name="isDark ? 'sun' : 'moon'" :size="16" color="currentColor" />
                    </button>
                    <button type="button" class="topbar-icon-btn" data-tour="help" title="Справочник" @click="showHelp = true">
                        <UiIcon name="help-circle" :size="16" color="currentColor" />
                    </button>
                    <button type="button" class="topbar-icon-btn" title="Статистика" @click="showStats = true">
                        <UiIcon name="gauge" :size="16" color="currentColor" />
                    </button>
                    <button type="button" class="topbar-icon-btn" title="Написать нам" @click="showFeedback = true">
                        <UiIcon name="message-square" :size="16" color="currentColor" />
                    </button>
                    <button
                        v-if="canShare"
                        type="button"
                        class="topbar-icon-btn"
                        title="Поделиться"
                        aria-label="Поделиться"
                        :disabled="shareLoading"
                        @click="shareApp"
                    >
                        <UiIcon name="navigation" :size="16" color="currentColor" />
                    </button>
                </div>
            </div>

            <div class="topbar-row topbar-row--meta">
                <div v-if="filteredStations.length" class="station-count-pill">
                    <UiIcon name="map-pin" :size="10" color="currentColor" />
                    {{ filteredStations.length }} АЗС
                    <template v-if="favoritesOnly"> · мои</template>
                </div>

                <div class="view-toggle" data-tour="view">
                    <button
                        type="button"
                        class="view-toggle-btn"
                        :class="{ active: viewMode === 'map' }"
                        @click="viewMode = 'map'"
                    >
                        <UiIcon name="map-pin" :size="10" color="currentColor" />
                        Карта
                    </button>
                    <button
                        type="button"
                        class="view-toggle-btn"
                        :class="{ active: viewMode === 'list' }"
                        @click="viewMode = 'list'"
                    >
                        <UiIcon name="list" :size="10" color="currentColor" />
                        Список
                    </button>
                </div>

                <button
                    type="button"
                    class="filter-toggle-btn"
                    :class="{ 'filter-toggle-btn--open': filtersOpen }"
                    :aria-expanded="filtersOpen"
                    @click="filtersOpen = !filtersOpen"
                >
                    Фильтры
                    <UiIcon
                        :name="filtersOpen ? 'chevron-up' : 'chevron-down'"
                        :size="12"
                        color="currentColor"
                    />
                </button>
            </div>

            <div class="filter-panel" :class="{ 'filter-panel--open': filtersOpen }">
                <div class="filter-row filter-row--fuel" data-tour="fuel">
                    <button
                        v-for="f in FUEL_TYPES"
                        :key="f.value"
                        type="button"
                        class="fuel-btn fuel-btn--compact"
                        :class="{ active: selectedFuel === f.value }"
                        @click="selectedFuel = f.value"
                    >
                        {{ f.label }}
                    </button>
                </div>
                <div class="filter-row filter-row--sale">
                    <button
                        type="button"
                        class="network-btn network-btn--compact"
                        :class="{ active: selectedSaleType === null }"
                        @click="selectedSaleType = null"
                    >
                        Все
                    </button>
                    <button
                        type="button"
                        class="network-btn network-btn--compact sale-btn--voucher"
                        :class="{ active: selectedSaleType === 'voucher' }"
                        @click="selectedSaleType = 'voucher'"
                    >
                        Талоны
                    </button>
                    <button
                        type="button"
                        class="network-btn network-btn--compact sale-btn--qr"
                        :class="{ active: selectedSaleType === 'qr' }"
                        @click="selectedSaleType = 'qr'"
                    >
                        QR
                    </button>
                    <button
                        type="button"
                        class="network-btn network-btn--compact sale-btn--canister"
                        :class="{ active: canisterOnly }"
                        @click="canisterOnly = !canisterOnly"
                    >
                        Канистра
                    </button>
                    <button
                        v-if="viewMode === 'map'"
                        type="button"
                        class="network-btn network-btn--compact"
                        :class="{ active: mapLayer === 'queue' }"
                        @click="toggleMapLayer"
                    >
                        {{ mapLayer === 'queue' ? 'Топливо' : 'Очереди' }}
                    </button>
                    <span class="filter-row-divider" aria-hidden="true"></span>
                    <button
                        type="button"
                        class="network-btn network-btn--compact favorites-filter-btn"
                        :class="{ active: favoritesOnly }"
                        :disabled="favoriteCount === 0 && !favoritesOnly"
                        @click="favoritesOnly = !favoritesOnly"
                    >
                        <UiIcon
                            name="star"
                            :size="10"
                            :color="favoritesOnly ? '#0A0807' : '#E8B84B'"
                            :fill="favoritesOnly ? '#0A0807' : '#E8B84B'"
                        />
                        Мои АЗС
                        <span v-if="favoriteCount" class="network-count">{{ favoriteCount }}</span>
                    </button>
                    <button
                        type="button"
                        class="network-btn network-btn--compact"
                        data-tour="network"
                        :class="{ active: selectedNetwork === null }"
                        @click="selectedNetwork = null"
                    >
                        Все сети
                    </button>
                    <button
                        v-for="n in availableNetworks"
                        :key="n.value"
                        type="button"
                        class="network-btn network-btn--compact"
                        :class="{ active: selectedNetwork === n.value }"
                        @click="selectedNetwork = n.value"
                    >
                        {{ n.label }}
                        <span class="network-count">{{ n.count }}</span>
                    </button>
                </div>
            </div>
        </header>

        <PushPrompt />

        <CommunityBanner />

        <div class="map-wrap" :class="{ 'map-wrap--list': viewMode === 'list' }" data-tour="map">
            <MapView
                ref="mapViewRef"
                v-show="viewMode === 'map'"
                :stations="filteredStations"
                :favorite-ids="favoriteIds"
                :selected-id="selectedStation?.id"
                :user-position="position"
                :geo-resolved="geoResolved"
                :map-center="mapCenter"
                :map-layer="mapLayer"
                :sheet-height="viewMode === 'map' ? sheetHeightPx : 0"
                :pick-mode="(showAddStation || showEditStation) && mapPickMode"
                :pick-coords="pickCoords"
                @select="selectStation"
                @pick="onMapPick"
            />

            <StationList
                v-show="viewMode === 'list'"
                :stations="filteredStations"
                :selected-id="selectedStation?.id"
                :selected-fuel="selectedFuel"
                :user-position="position"
                :sort-by-distance="viewMode === 'list' && !!position"
                :favorites-only="favoritesOnly"
                @select="selectStation"
            />

            <div
                v-show="viewMode === 'map' && !showAddStation && !showEditStation"
                class="map-fab-bar"
                :class="{ 'map-fab-bar--sheet': !!selectedStation && !showReport && !showConfirm && !showEditStation }"
                :style="mapFabBottomStyle"
            >
                <button
                    type="button"
                    class="map-fab-circle"
                    title="Обновить страницу"
                    aria-label="Обновить страницу"
                    @click="reloadPage"
                >
                    ↻
                </button>

                <div class="map-fab-center">
                    <button
                        type="button"
                        class="map-fab-nearby"
                        data-tour="nearby"
                        :disabled="geoLoading"
                        @click="nearby"
                    >
                        {{ geoLoading ? '…' : 'Рядом' }}
                    </button>
                    <button v-if="mode === 'nearby'" type="button" class="map-fab-secondary" @click="showAll">
                        Все
                    </button>
                </div>

                <button
                    type="button"
                    class="map-fab-circle map-fab-circle--action"
                    title="Добавить заправку"
                    aria-label="Добавить заправку"
                    @click="openAddStation"
                >
                    +
                </button>
            </div>

            <PwaInstallButton v-show="viewMode === 'map'" />

            <div v-if="loading" class="loading-badge">Загрузка…</div>
            <div v-if="geoNotice" class="notice-badge">{{ geoNotice }}</div>
            <div v-else-if="geoError" class="notice-badge">{{ geoError }}</div>
            <div v-if="error" class="error-badge">{{ error }}</div>
        </div>

        <div
            v-if="viewMode === 'list' && selectedStation && !showReport && !showConfirm && !showEditStation"
            class="list-detail-backdrop"
            @click="selectedStation = null"
        />

        <div
            v-if="selectedStation && !showReport && !showConfirm && !showEditStation"
            ref="bottomSheetEl"
            class="bottom-sheet"
            :class="{ 'bottom-sheet--list-detail': viewMode === 'list' }"
        >
            <StationCard
                :station="selectedStation"
                :selected-fuel="selectedFuel"
                :selected-sale-type="selectedSaleType"
                :canister-only="canisterOnly"
                @report="showReport = true"
                @confirm="showConfirm = true"
                @closed="onStationClosed"
                @edit="openEditStation"
                @confirm-correction="onConfirmCorrection"
                @select-fuel="selectedFuel = $event"
                @select-sale-type="selectedSaleType = $event"
                @select-canister-only="canisterOnly = $event"
                @close="selectedStation = null"
            />
        </div>

        <ReportForm
            v-if="showReport && selectedStation"
            :station="selectedStation"
            :selected-fuel="selectedFuel"
            @submit="onReportDone"
            @close="showReport = false"
        />

        <ConfirmButton
            v-if="showConfirm && selectedStation"
            :station="selectedStation"
            :selected-fuel="selectedFuel"
            @done="onConfirmDone"
            @close="showConfirm = false"
        />

        <AddStationForm
            v-if="showAddStation"
            :selected-fuel="selectedFuel"
            :pick-coords="pickCoords"
            :user-position="position"
            @close="closeAddStation"
            @submit="onStationAdded"
            @start-pick="onPickStart"
            @stop-pick="onPickStop"
        />

        <EditStationForm
            v-if="showEditStation && selectedStation"
            :station="selectedStation"
            :selected-fuel="selectedFuel"
            :pick-coords="pickCoords"
            :user-position="position"
            @close="closeEditStation"
            @submit="onEditProposed"
            @start-pick="onPickStart"
            @stop-pick="onPickStop"
        />

        <OnboardingTour
            v-if="showOnboarding"
            @prepare-step="onTourPrepareStep"
            @finish="finishOnboarding"
            @open-guide="showHelp = true"
        />

        <HelpGuide
            v-if="showHelp"
            @close="showHelp = false"
            @start-tour="startTour"
            @open-legal="(id) => { legalDocId = id; showLegal = true; }"
        />

        <StatsPanel
            v-if="showStats"
            :selected-fuel="selectedFuel"
            @close="showStats = false"
        />

        <FeedbackForm
            v-if="showFeedback"
            @close="showFeedback = false"
        />

        <CookieBanner
            v-if="!cookieConsentGranted"
            @accept="onCookieAccept"
            @decline="onCookieDecline"
            @open-legal="(id) => { legalDocId = id; showLegal = true; }"
        />

        <footer class="app-legal-footer">
            <LegalLinks @open="(id) => { legalDocId = id; showLegal = true; }" />
        </footer>
    </div>

    <LegalDocs
        v-if="showLegal && !isAdminRoute"
        :doc-id="legalDocId"
        @close="showLegal = false"
    />
</template>
