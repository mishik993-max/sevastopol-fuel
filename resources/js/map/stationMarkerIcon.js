import { MARKER_COLORS, queueMarkerColor } from '../constants';

/** Цвета маркеров из Figma Make (MapSVG.tsx) */
const FIGMA_STATUS = {
    green: '#22C55E',
    yellow: '#EAB308',
    red: '#EF4444',
    black: '#6B7280',
};

/** Золото избранного — ярче базового --gold для контраста на карте */
const FAVORITE_GOLD = '#FFD54F';
const FAVORITE_GOLD_SOFT = '#E8B84B';

export const MARKER_ICON_SIZE = 32;
export const FAVORITE_MARKER_ICON_SIZE = 40;

const iconCache = new Map();

function escapeXml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

export function networkMarkerLabel(network) {
    const trimmed = (network || '').trim();

    if (!trimmed) {
        return 'А';
    }

    return trimmed.charAt(0).toUpperCase();
}

export function markerFillColor(markerColor) {
    return FIGMA_STATUS[markerColor] || MARKER_COLORS[markerColor] || FIGMA_STATUS.black;
}

function buildMarkerSvg({
    fill,
    label,
    favorite = false,
    glow = false,
}) {
    const safeLabel = escapeXml(label);
    const id = `m${Math.random().toString(36).slice(2, 9)}`;
    const size = favorite ? FAVORITE_MARKER_ICON_SIZE : MARKER_ICON_SIZE;
    const center = size / 2;

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <defs>
    <filter id="${id}-shadow" x="-40%" y="-40%" width="180%" height="180%">
      <feDropShadow dx="0" dy="2" stdDeviation="2.5" flood-color="rgba(0,0,0,0.65)"/>
    </filter>
    <filter id="${id}-glow" x="-80%" y="-80%" width="260%" height="260%">
      <feGaussianBlur stdDeviation="3" result="blur"/>
      <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
    <filter id="${id}-gold" x="-100%" y="-100%" width="300%" height="300%">
      <feGaussianBlur stdDeviation="4" result="blur"/>
      <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
  </defs>
  ${favorite ? `<circle cx="${center}" cy="${center}" r="17" fill="${FAVORITE_GOLD_SOFT}" opacity="0.42" filter="url(#${id}-gold)"/>` : ''}
  ${favorite ? `<circle cx="${center}" cy="${center}" r="15.5" fill="none" stroke="${FAVORITE_GOLD}" stroke-width="2" opacity="0.55"/>` : ''}
  ${glow ? `<circle cx="${center}" cy="${center}" r="14" fill="${fill}" opacity="0.18" filter="url(#${id}-glow)"/>` : ''}
  <circle cx="${center}" cy="${center}" r="11" fill="${fill}" filter="url(#${id}-shadow)"/>
  <circle cx="${center}" cy="${center}" r="11" fill="none" stroke="rgba(255,255,255,0.92)" stroke-width="2"/>
  <text x="${center}" y="${center + 4}" text-anchor="middle" fill="#FFFFFF" font-size="10" font-weight="700" font-family="'Exo 2', Arial, sans-serif">${safeLabel}</text>
  ${favorite ? `<circle cx="${center}" cy="${center}" r="12.5" fill="none" stroke="${FAVORITE_GOLD}" stroke-width="3" opacity="1"/>` : ''}
  ${favorite ? `<circle cx="${center}" cy="${center}" r="14.8" fill="none" stroke="${FAVORITE_GOLD_SOFT}" stroke-width="2" opacity="0.95"/>` : ''}
</svg>`;
}

export function getStationMarkerIconUrl(station, { favorite = false, mapLayer = 'fuel' } = {}) {
    const markerColor = mapLayer === 'queue'
        ? queueMarkerColor(station.queue_size)
        : station.marker_color;

    const fill = mapLayer === 'queue'
        ? queueMarkerColor(station.queue_size)
        : markerFillColor(markerColor);

    const label = networkMarkerLabel(station.network);
    const glow = mapLayer !== 'queue' && markerColor === 'green';
    const cacheKey = `${fill}|${label}|${favorite ? 1 : 0}|${glow ? 1 : 0}`;

    if (iconCache.has(cacheKey)) {
        return iconCache.get(cacheKey);
    }

    const svg = buildMarkerSvg({ fill, label, favorite, glow });
    const url = `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
    iconCache.set(cacheKey, url);

    return url;
}

export function markerIconLayoutOptions(station, options = {}) {
    const favorite = Boolean(options.favorite);
    const href = getStationMarkerIconUrl(station, options);
    const size = favorite ? FAVORITE_MARKER_ICON_SIZE : MARKER_ICON_SIZE;
    const half = size / 2;

    return {
        iconLayout: 'default#image',
        iconImageHref: href,
        iconImageSize: [size, size],
        iconImageOffset: [-half, -half],
    };
}
