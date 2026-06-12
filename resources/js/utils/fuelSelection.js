import { FUEL_TYPES } from '../constants';

const MARKER_RANK = { green: 4, yellow: 3, red: 2, black: 1 };
const STATUS_RANK = { available: 4, low: 3, none: 2, unknown: 1 };

export function mergeMarkerColor(station, fuelTypes) {
    if (!fuelTypes?.length) {
        return station.marker_color ?? 'black';
    }

    let best = 'black';
    let bestRank = 0;

    for (const fuelType of fuelTypes) {
        const fuel = station.fuels?.find((item) => item.fuel_type === fuelType);

        if (!fuel?.marker_color) {
            continue;
        }

        const rank = MARKER_RANK[fuel.marker_color] ?? 0;

        if (rank > bestRank) {
            bestRank = rank;
            best = fuel.marker_color;
        }
    }

    return best;
}

export function pickDisplayFuel(station, fuelTypes) {
    if (!station?.fuels?.length) {
        return null;
    }

    if (!fuelTypes?.length) {
        return station.fuels[0];
    }

    let best = null;
    let bestRank = -1;

    for (const fuelType of fuelTypes) {
        const fuel = station.fuels.find((item) => item.fuel_type === fuelType);

        if (!fuel) {
            continue;
        }

        const rank = STATUS_RANK[fuel.status] ?? 0;

        if (rank > bestRank) {
            bestRank = rank;
            best = fuel;
        }
    }

    return best
        ?? station.fuels.find((item) => fuelTypes.includes(item.fuel_type))
        ?? station.fuels[0];
}

export function loadSelectedFuels() {
    try {
        const raw = localStorage.getItem('selected_fuels');

        if (!raw) {
            return ['a95'];
        }

        const parsed = JSON.parse(raw);

        if (!Array.isArray(parsed) || parsed.length === 0) {
            return ['a95'];
        }

        const allowed = new Set(FUEL_TYPES.map((item) => item.value));
        const valid = parsed.filter((value) => allowed.has(value));

        return valid.length ? valid : ['a95'];
    } catch {
        return ['a95'];
    }
}

export function saveSelectedFuels(fuelTypes) {
    localStorage.setItem('selected_fuels', JSON.stringify(fuelTypes));
}

export function normalizeFuelSelection(values) {
    const allowed = new Set(FUEL_TYPES.map((item) => item.value));
    const ordered = [];

    for (const item of FUEL_TYPES) {
        if (values.includes(item.value) && allowed.has(item.value)) {
            ordered.push(item.value);
        }
    }

    return ordered.length ? ordered : ['a95'];
}

export function parseFuelQueryParam(raw) {
    if (!raw) {
        return null;
    }

    const parts = raw.split(',').map((part) => part.trim()).filter(Boolean);

    return normalizeFuelSelection(parts);
}
