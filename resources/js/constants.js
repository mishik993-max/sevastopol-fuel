export const FUEL_TYPES = [
    { value: 'a92', label: '92' },
    { value: 'a95', label: '95' },
    { value: 'a95_plus', label: '95+' },
    { value: 'dt', label: 'ДТ' },
    { value: 'dt_plus', label: 'ДТ+' },
    { value: 'gas', label: 'Газ' },
];

export const FUEL_STATUSES = [
    { value: 'available', label: 'Есть' },
    { value: 'low', label: 'Мало' },
    { value: 'none', label: 'Нет' },
];

export const QUEUE_SIZES = [
    { value: 'none', label: 'Нет' },
    { value: 'up_to_10', label: 'До 10' },
    { value: '10_30', label: '10–30' },
    { value: '30_plus', label: '30+' },
];

export const SALE_TYPES = [
    { value: 'regular', label: 'Обычный' },
    { value: 'voucher', label: 'По талонам' },
    { value: 'qr', label: 'Нужен QR' },
];

export const FILL_VOLUMES = [
    { value: 'liters_20', label: '20 литров' },
    { value: 'full_tank', label: 'Полный бак' },
];

/** Порядок сетей в фильтре (остальные- по количеству АЗС) */
export const NETWORK_PRIORITY = [
    'Атан',
    'ТЭС',
    'Грифон',
    'WOG',
    'OKKO',
    'Лукойл',
    'Роснефть',
    'Севастопольская',
    'Red Petrol',
    'Мустанг',
    'Sota',
    'Shell',
    'Татнефть',
    'Gulf',
];

/** Границы Севастополя и окрестностей (как в config/stations.php) */
export const SEVASTOPOL_BBOX = {
    south: 44.48,
    west: 33.38,
    north: 44.72,
    east: 33.72,
};

export const SEVASTOPOL_CENTER = [44.605, 33.522];

export function distanceM(lat1, lng1, lat2, lng2) {
    const earthRadius = 6_371_000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos((lat1 * Math.PI) / 180)
        * Math.cos((lat2 * Math.PI) / 180)
        * Math.sin(dLng / 2) ** 2;

    return earthRadius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function isInSevastopolArea(lat, lng) {
    const la = Number(lat);
    const ln = Number(lng);

    return la >= SEVASTOPOL_BBOX.south
        && la <= SEVASTOPOL_BBOX.north
        && ln >= SEVASTOPOL_BBOX.west
        && ln <= SEVASTOPOL_BBOX.east;
}

export const MARKER_COLORS = {
    green: '#22c55e',
    yellow: '#eab308',
    red: '#ef4444',
    black: '#374151',
};
