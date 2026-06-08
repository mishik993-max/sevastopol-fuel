# Севастополь Топливо

Оперативная карта АЗС Севастополя: статус топлива (6 видов), очереди, отчёты пользователей, push-напоминания о QR в 21:30–22:00.

## Стек

- Laravel 13 + MySQL
- Vue 3 + Vite + Яндекс.Карты + PWA
- Web Push (VAPID)

## Быстрый старт

```bash
composer install
cp .env.example .env
# настроить DB, YANDEX_MAPS_API_KEY и VAPID в .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
```

Открыть: `http://sevastopol-fuel.test` (Laragon) или `php artisan serve`.

## Документация

- [Локальная разработка (Laragon)](docs/LOCAL.md)
- [Деплой на Linux VPS](docs/DEPLOY.md)
- [VPS с нуля: домен, SSL, зависимости](docs/VPS-SETUP.md)
- [Привязка домена, SSL по IP, идеи .su](docs/DOMAIN-AND-SSL.md)
- [Нагрузочное тестирование](docs/LOAD-TEST.md)

## API

| Метод | URL |
|-------|-----|
| GET | `/api/stations?fuel=a95` |
| GET | `/api/stations/nearby?lat=&lng=&fuel=a95` |
| GET | `/api/stations/{id}` |
| POST | `/api/reports` (multipart, поле `photo`) |
| POST | `/api/stations/{id}/confirm` |
| GET | `/api/settings` |
| POST | `/api/feedback` |
| POST | `/api/push/subscribe` |
| GET | `/api/stats?fuel=a95` |
| POST | `/api/admin/login` |
| GET | `/api/admin/corrections` (X-Admin-Token) |
| GET | `/api/admin/reports` |
| POST | `/api/admin/reports/{id}/hide` |
| GET | `/api/admin/osm-import/preview` |
| POST | `/api/admin/osm-import/run` |
| GET/PATCH | `/api/admin/settings` |

Админ-панель: `/admin`. Импорт АЗС из OSM — кнопка в админке (без автообновления по cron).
