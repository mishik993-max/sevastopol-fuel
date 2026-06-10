<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { queueMarkerColor } from '../constants';
import { isInBbox } from '../composables/useAppSettings';
import { useYandexMaps } from '../composables/useYandexMaps';
import { markerFillColor, markerIconLayoutOptions } from '../map/stationMarkerIcon';

const props = defineProps({
    stations: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
    userPosition: { type: Object, default: null },
    geoResolved: { type: Boolean, default: false },
    sheetHeight: { type: Number, default: 0 },
    pickMode: { type: Boolean, default: false },
    pickCoords: { type: Object, default: null },
    mapCenter: { type: Array, default: () => [44.605, 33.522] },
    mapLayer: { type: String, default: 'fuel' },
    favoriteIds: { type: Array, default: () => [] },
});

const emit = defineEmits(['select', 'pick']);

const mapRootEl = ref(null);
const mapEl = ref(null);
const mapError = ref(null);
const mapLoading = ref(true);

let ymaps = null;
let map = null;
let resizeObserver = null;
let resizeScheduled = false;
const stationPlacemarks = [];
let userPlacemark = null;
let pickPlacemark = null;
let mapClickHandler = null;
let queueCircles = [];
let selectedPulseCircles = [];
const marginAccessors = [];

const cityCenter = () => props.mapCenter;
const CITY_ZOOM = 12;
const USER_ZOOM = 14;
const FOCUS_ZOOM = 15;
const TOP_CONTROLS_PX = 56;

let initialCenterDone = false;
let mapReady = false;

const { load } = useYandexMaps();

function scheduleInvalidateSize() {
    if (resizeScheduled) {
        return;
    }

    resizeScheduled = true;
    requestAnimationFrame(() => {
        resizeScheduled = false;
        invalidateSize();
    });
}

function onOrientationChange() {
    scheduleInvalidateSize();
}

function onVisualViewportResize() {
    scheduleInvalidateSize();
}

function onVisibilityChange() {
    if (document.visibilityState === 'visible') {
        scheduleInvalidateSize();
    }
}

function setupResizeObserver() {
    const el = mapRootEl.value;
    if (!el || typeof ResizeObserver === 'undefined') {
        return;
    }

    resizeObserver = new ResizeObserver(() => {
        if (mapReady) {
            scheduleInvalidateSize();
        }
    });
    resizeObserver.observe(el);
}

function setupViewportListeners() {
    window.addEventListener('orientationchange', onOrientationChange);
    window.visualViewport?.addEventListener('resize', onVisualViewportResize);
    document.addEventListener('visibilitychange', onVisibilityChange);
}

function teardownViewportListeners() {
    window.removeEventListener('orientationchange', onOrientationChange);
    window.visualViewport?.removeEventListener('resize', onVisualViewportResize);
    document.removeEventListener('visibilitychange', onVisibilityChange);
}

onMounted(async () => {
    setupResizeObserver();
    setupViewportListeners();

    try {
        ymaps = await load();

        map = new ymaps.Map(mapEl.value, {
            center: cityCenter(),
            zoom: 12,
            controls: ['zoomControl'],
        }, {
            suppressMapOpenBlock: false,
        });

        mapLoading.value = false;
        updateMapMargins();
        renderMapLayers();
        mapReady = true;
        setupMapClick(props.pickMode);
        tryInitialCenter();
        await nextTick();
        scheduleInvalidateSize();
        setTimeout(scheduleInvalidateSize, 150);
        setTimeout(scheduleInvalidateSize, 500);
    } catch (e) {
        mapError.value = e.message;
        mapLoading.value = false;
    }
});

onUnmounted(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
    teardownViewportListeners();
    clearMapMargins();
    clearQueueCircles();
    clearSelectedPulse();
    setupMapClick(false);
    map?.destroy();
    map = null;
});

watch(() => props.stations, renderMapLayers, { deep: true });
watch(() => props.mapLayer, renderMapLayers);
watch(() => props.favoriteIds, renderMapLayers);
watch(() => props.selectedId, renderMapLayers);
watch(() => [props.selectedId, props.sheetHeight], focusSelected);
watch(() => props.sheetHeight, updateMapMargins);
watch(() => props.userPosition, () => {
    renderUserMarker();
    tryInitialCenter();
});
watch(() => props.geoResolved, tryInitialCenter);
watch(() => props.pickMode, setupMapClick);
watch(() => props.pickCoords, renderPickMarker, { deep: true });

function clearMapMargins() {
    marginAccessors.forEach((accessor) => accessor.remove());
    marginAccessors.length = 0;
}

function updateMapMargins() {
    if (!map?.margin?.addArea) return;

    clearMapMargins();

    marginAccessors.push(map.margin.addArea({
        top: 0,
        left: 0,
        width: '100%',
        height: TOP_CONTROLS_PX,
    }));

    if (props.sheetHeight > 0 && mapEl.value) {
        const mapHeight = mapEl.value.offsetHeight || 1;
        const bottomPct = Math.min(75, (props.sheetHeight / mapHeight) * 100);

        marginAccessors.push(map.margin.addArea({
            bottom: 0,
            left: 0,
            width: '100%',
            height: `${bottomPct}%`,
        }));
    }
}

function clearQueueCircles() {
    if (!map) {
        queueCircles = [];
        return;
    }

    queueCircles.forEach((circle) => map.geoObjects.remove(circle));
    queueCircles = [];
}

function queueCircleRadius(queueSize) {
    switch (queueSize) {
        case 'up_to_10':
            return 350;
        case '10_30':
            return 650;
        case '30_plus':
            return 950;
        default:
            return 0;
    }
}

function renderQueueCircles() {
    clearQueueCircles();

    if (!map || !ymaps || props.mapLayer !== 'queue') {
        return;
    }

    props.stations.forEach((station) => {
        const radius = queueCircleRadius(station.queue_size);
        if (radius <= 0) {
            return;
        }

        const color = queueMarkerColor(station.queue_size);
        const circle = new ymaps.Circle(
            [stationCoords(station), radius],
            {},
            {
                fillColor: color,
                fillOpacity: 0.22,
                strokeColor: color,
                strokeOpacity: 0.45,
                strokeWidth: 2,
                zIndex: 50,
            },
        );

        circle.events.add('click', () => emit('select', station));
        map.geoObjects.add(circle);
        queueCircles.push(circle);
    });
}

function renderMapLayers() {
    if (!map || !ymaps) {
        return;
    }

    renderQueueCircles();
    renderSelectedPulse();
    renderMarkers();
}

function clearSelectedPulse() {
    if (!map) {
        selectedPulseCircles = [];
        return;
    }

    selectedPulseCircles.forEach((circle) => map.geoObjects.remove(circle));
    selectedPulseCircles = [];
}

function renderSelectedPulse() {
    clearSelectedPulse();

    if (!map || !ymaps || !props.selectedId || props.mapLayer === 'queue') {
        return;
    }

    const station = props.stations.find((s) => s.id === props.selectedId);
    if (!station) {
        return;
    }

    const coords = stationCoords(station);
    const color = markerFillColor(station.marker_color);

    [
        [28, 0.4, 2],
        [40, 0.2, 1],
    ].forEach(([radius, strokeOpacity, strokeWidth]) => {
        const circle = new ymaps.Circle(
            [coords, radius],
            {},
            {
                fillColor: color,
                fillOpacity: 0,
                strokeColor: color,
                strokeOpacity,
                strokeWidth,
                zIndex: 90,
            },
        );

        circle.events.add('click', () => emit('select', station));
        map.geoObjects.add(circle);
        selectedPulseCircles.push(circle);
    });
}

function invalidateSize() {
    if (!map?.container?.fitToViewport) {
        return;
    }

    try {
        map.container.fitToViewport();
    } catch {
        // ignore resize errors on destroyed/hidden map
    }
}

function stationCoords(station) {
    return [Number(station.latitude), Number(station.longitude)];
}

function clearStationMarkers() {
    if (!map) return;
    stationPlacemarks.forEach((p) => map.geoObjects.remove(p));
    stationPlacemarks.length = 0;
}

function renderMarkers() {
    if (!map || !ymaps) return;

    clearStationMarkers();

    props.stations.forEach((station) => {
        const selected = station.id === props.selectedId;
        const isFavorite = props.favoriteIds.includes(station.id);
        const placemark = new ymaps.Placemark(
            stationCoords(station),
            {},
            {
                ...markerIconLayoutOptions(station, {
                    favorite: isFavorite,
                    mapLayer: props.mapLayer,
                }),
                zIndex: selected ? 1000 : (isFavorite ? 650 : 100),
            },
        );

        placemark.events.add('click', () => emit('select', station));
        map.geoObjects.add(placemark);
        stationPlacemarks.push(placemark);
    });
}

function tryInitialCenter() {
    if (!mapReady || !map || initialCenterDone || props.selectedId) {
        return;
    }

    if (!props.geoResolved) {
        return;
    }

    const user = localUserPosition();

    if (user) {
        map.setCenter([user.lat, user.lng], USER_ZOOM, { duration: 300, checkZoomRange: true });
    } else {
        map.setCenter(cityCenter(), CITY_ZOOM, { duration: 200, checkZoomRange: true });
    }

    initialCenterDone = true;
}

function focusSelected() {
    if (!props.selectedId) {
        return;
    }

    initialCenterDone = true;
    const station = props.stations.find((s) => s.id === props.selectedId);
    if (!station || !map) return;

    updateMapMargins();

    const coords = stationCoords(station);
    const options = {
        duration: 300,
        checkZoomRange: true,
        useMapMargin: true,
    };

    const applyCenter = () => {
        map.setCenter(coords, FOCUS_ZOOM, options);
    };

    if (props.sheetHeight > 0) {
        let applied = false;
        const centerOnce = () => {
            if (applied) return;
            applied = true;
            applyCenter();
        };

        map.events.once('marginchange', centerOnce);
        requestAnimationFrame(() => requestAnimationFrame(centerOnce));
    } else {
        applyCenter();
    }
}

function localUserPosition() {
    if (!props.userPosition) {
        return null;
    }

    const lat = Number(props.userPosition.lat);
    const lng = Number(props.userPosition.lng);

    if (!isInBbox(lat, lng)) {
        return null;
    }

    return { lat, lng };
}

function setupMapClick(enabled) {
    if (!map) return;

    if (mapClickHandler) {
        map.events.remove('click', mapClickHandler);
        mapClickHandler = null;
    }

    if (!enabled) return;

    mapClickHandler = (event) => {
        const coords = event.get('coords');
        emit('pick', { lat: coords[0], lng: coords[1] });
    };

    map.events.add('click', mapClickHandler);
}

function renderPickMarker() {
    if (!map || !ymaps) return;

    if (pickPlacemark) {
        map.geoObjects.remove(pickPlacemark);
        pickPlacemark = null;
    }

    if (!props.pickCoords) return;

    pickPlacemark = new ymaps.Placemark(
        [Number(props.pickCoords.lat), Number(props.pickCoords.lng)],
        {},
        {
            preset: 'islands#redCircleDotIcon',
            zIndex: 3000,
        },
    );
    map.geoObjects.add(pickPlacemark);
}

function renderUserMarker() {
    const user = localUserPosition();

    if (!map || !ymaps) return;

    if (userPlacemark) {
        map.geoObjects.remove(userPlacemark);
        userPlacemark = null;
    }

    if (!user) return;

    userPlacemark = new ymaps.Placemark(
        [user.lat, user.lng],
        {},
        {
            preset: 'islands#darkOrangeCircleDotIcon',
            zIndex: 2000,
        },
    );
    map.geoObjects.add(userPlacemark);
}

defineExpose({ invalidateSize });
</script>

<template>
    <div ref="mapRootEl" class="map-wrap-inner">
        <div v-if="mapLoading" class="map-status map-status--loading">Загрузка карты…</div>
        <div v-if="mapError" class="map-status map-error">
            <p>{{ mapError }}</p>
            <p class="hint">
                В кабинете
                <a href="https://developer.tech.yandex.ru/" target="_blank" rel="noopener">developer.tech.yandex.ru</a>
                для ключа JavaScript API добавьте домен:<br />
                <code>sevastopol-fuel.test</code><br />
                Затем: <code>npm run build</code> и обновите страницу.
            </p>
        </div>
        <div v-if="pickMode" class="map-pick-hint">Нажмите на карту - где заправка</div>
        <div v-if="mapLayer === 'queue' && !pickMode" class="map-queue-legend" aria-hidden="true">
            <span class="map-queue-legend-title">Очереди</span>
            <span class="map-queue-legend-item"><i class="map-queue-dot map-queue-dot--low"></i> до 10</span>
            <span class="map-queue-legend-item"><i class="map-queue-dot map-queue-dot--mid"></i> 10–30</span>
            <span class="map-queue-legend-item"><i class="map-queue-dot map-queue-dot--high"></i> 30+</span>
        </div>
        <div ref="mapEl" class="map" :class="{ 'map--picking': pickMode }"></div>
    </div>
</template>

<style scoped>
.map {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.map-status {
    position: absolute;
    inset: 0;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    text-align: center;
    font-size: 0.9rem;
    pointer-events: none;
}

.map-status--loading {
    background: rgba(10, 8, 7, 0.65);
}

.map-error {
    pointer-events: auto;
    background: rgba(10, 8, 7, 0.94);
    color: #fca5a5;
}

.map-error .hint {
    margin-top: 12px;
    color: var(--muted);
    font-size: 0.8rem;
    line-height: 1.5;
}

.map-error a {
    color: var(--primary);
}

.map-error code {
    font-size: 0.75rem;
    background: var(--surface2);
    padding: 2px 6px;
    border-radius: 4px;
}

.map--picking {
    cursor: crosshair;
}

.map-pick-hint {
    position: absolute;
    top: 12px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 20;
    padding: 8px 14px;
    border-radius: 20px;
    background: rgba(239, 68, 68, 0.92);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 600;
    pointer-events: none;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
}

.map-queue-legend {
    position: absolute;
    right: 12px;
    bottom: calc(132px + var(--safe-bottom));
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    border-radius: 12px;
    background: rgba(20, 16, 14, 0.88);
    border: 1px solid var(--border);
    font-size: 0.72rem;
    color: var(--muted);
    pointer-events: none;
    backdrop-filter: blur(8px);
}

.map-queue-legend-title {
    font-weight: 700;
    color: var(--text);
    margin-bottom: 2px;
}

.map-queue-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.map-queue-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.map-queue-dot--low {
    background: #eab308;
}

.map-queue-dot--mid {
    background: #f97316;
}

.map-queue-dot--high {
    background: #ef4444;
}
</style>
