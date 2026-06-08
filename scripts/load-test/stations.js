import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = __ENV.BASE_URL || 'http://sevastopol-fuel.test';

export const options = {
    stages: [
        { duration: '2m', target: 50 },
        { duration: '5m', target: 50 },
        { duration: '1m', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.02'],
        http_req_duration: ['p(95)<1500'],
    },
};

let stationIds = [];

export function setup() {
    const res = http.get(`${baseUrl}/api/stations?fuel=a95`);

    if (res.status !== 200) {
        return { ids: [] };
    }

    const body = res.json();

    return {
        ids: (body.data || []).slice(0, 20).map((s) => s.id),
    };
}

export default function (data) {
    const roll = Math.random();

    if (roll < 0.7) {
        const res = http.get(`${baseUrl}/api/stations?fuel=a95`);
        check(res, { 'stations list ok': (r) => r.status === 200 });
    } else if (roll < 0.85 && data.ids.length > 0) {
        const id = data.ids[Math.floor(Math.random() * data.ids.length)];
        const res = http.get(`${baseUrl}/api/stations/${id}?fuel=a95`);
        check(res, { 'station detail ok': (r) => r.status === 200 });
    } else if (roll < 0.95) {
        const res = http.get(
            `${baseUrl}/api/stations/nearby?lat=44.610&lng=33.522&fuel=a95&limit=20`,
        );
        check(res, { 'nearby ok': (r) => r.status === 200 });
    } else {
        const res = http.get(`${baseUrl}/api/settings`);
        check(res, { 'settings ok': (r) => r.status === 200 });
    }

    sleep(1 + Math.random() * 2);
}
