<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { MARKER_COLORS } from '../constants';
import { isInBbox } from '../composables/useAppSettings';
import { useYandexMaps } from '../composables/useYandexMaps';

const props = defineProps({
    stations: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
    userPosition: { type: Object, default: null },
    geoResolved: { type: Boolean, default: false },
    sheetHeight: { type: Number, default: 0 },
    pickMode: { type: Boolean, default: false },
    pickCoords: { type: Object, default: null },
    mapCenter: { type: Array, default: () => [44.605, 33.522] },
});

const emit = defineEmits(['select', 'pick']);

const mapEl = ref(null);
const mapError = ref(null);
const mapLoading = ref(true);

let ymaps = null;
let map = null;
const stationPlacemarks = [];
let userPlacemark = null;
let pickPlacemark = null;
let mapClickHandler = null;
const marginAccessors = [];

const cityCenter = () => props.mapCenter;
const CITY_ZOOM = 12;
const USER_ZOOM = 14;
const FOCUS_ZOOM = 15;
const TOP_CONTROLS_PX = 56;

let initialCenterDone = false;
let mapReady = false;

const { load } = useYandexMaps();

onMounted(async () => {
    try {
        ymaps = await load();

        map = new ymaps.Map(mapEl.value, {
            center: cityCenter(),
            zoom: 12,
            controls: ['zoomControl'],
        }, {
            suppressMapOpenBlock: false,
        });

        updateMapMargins();
        renderMarkers();
        mapReady = true;
        setupMapClick(props.pickMode);
        tryInitialCenter();
    } catch (e) {
        mapError.value = e.message;
    } finally {
        mapLoading.value = false;
    }
});

onUnmounted(() => {
    clearMapMargins();
    setupMapClick(false);
    map?.destroy();
    map = null;
});

watch(() => props.stations, renderMarkers, { deep: true });
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

function markerColor(color) {
    return MARKER_COLORS[color] || MARKER_COLORS.black;
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
        const placemark = new ymaps.Placemark(
            stationCoords(station),
            {},
            {
                preset: 'islands#circleDotIcon',
                iconColor: markerColor(station.marker_color),
                zIndex: selected ? 1000 : 100,
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
</script>

<template>
    <div class="map-wrap-inner">
        <div v-if="mapLoading" class="map-status">Загрузка карты…</div>
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
        <div v-if="pickMode" class="map-pick-hint">Нажмите на карту- где заправка</div>
        <div ref="mapEl" class="map" :class="{ 'map--picking': pickMode }"></div>
    </div>
</template>

<style scoped>
.map-wrap-inner {
    width: 100%;
    height: 100%;
    position: relative;
}

.map {
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
    background: var(--bg);
    padding: 20px;
    text-align: center;
    font-size: 0.9rem;
}

.map-error {
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
</style>
