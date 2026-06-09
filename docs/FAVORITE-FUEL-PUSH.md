# Этап 2: Push «на моей АЗС появился А‑95»

Спецификация для отдельной реализации. Этап 1 (избранное в UI + маркеры на карте) уже сделан.

---

## Цель

Пользователь добавляет АЗС в **«Мои АЗС»** и получает **точечный** push, когда по выбранному топливу на этой заправке появляется статус **«Есть»** (зелёный маркер, свежий отчёт).

**Пример уведомления:**

| Поле | Значение |
|------|----------|
| title | Севастополь Топливо |
| body | На WOG «Инкerman» появился А‑95 |
| url | `https://sevazs.ru/?station=42&fuel=a95` |

Клик открывает приложение на карте с выбранной АЗС (как уже работает для QR‑push через `sw.js`).

---

## Текущее состояние (этап 1)

| Компонент | Сейчас |
|-----------|--------|
| Избранное | `localStorage` (`favorite_station_ids`), max 7, только клиент |
| Push‑подписка | `push_subscriptions`: endpoint + ключи, **без привязки к избранному** |
| Отправка | `WebPushService::broadcast()` - всем подписчикам (QR‑напоминания, админка) |
| Триггер статуса | Новый `Report` в `ReportController::store` / `confirm` |
| Статус «Есть» | `FuelStatus::Available` + `Freshness::Fresh` → `marker_color = green` |

**Проблема:** сервер не знает, какие АЗС у пользователя в избранном, и не может отправить push одному устройству.

---

## Архитектура

```
[Клиент]  ★ toggle → POST /api/push/watches  (endpoint + station_ids + fuel)
                ↓
         push_subscription_watches (БД)
                ↓
[Сервер]  Report создан → сравнить old/new marker_color
                ↓
         FavoriteFuelPushService → WebPushService::sendTo(endpoints, payload)
                ↓
[SW]      push event → showNotification → click → ?station=&fuel=
```

### Принципы

1. **Watch привязан к push‑endpoint**, не к аккаунту (у приложения нет логина).
2. **Синхронизация избранного** - при каждом изменении ★ и при старте приложения (если есть push‑подписка).
3. **Точечная отправка**, не broadcast.
4. **Антиспам** - cooldown и фильтр «не своим отчётом» (опционально на v1).

---

## Когда слать push

### Условие перехода (основное)

После сохранения нового **видимого** отчёта (`is_hidden = false`):

```
было: marker_color != 'green'  (или status != available, или freshness != fresh)
стало: marker_color == 'green' для данного fuel_type на station_id
```

Учитывать ту же логику, что в `StationStatusService::markerColor()` - не дублировать вручную, вызывать `fuelStatus()` до и после.

### Не слать

| Ситуация | Причина |
|----------|---------|
| `is_confirmation = true` | Подтверждение не меняет факт появления топлива |
| Статус остался green → green | Нет изменения |
| Переход green → yellow/red | Это другой сценарий (этап 3?) |
| Скрытый отчёт (`hide` в админке) | Не влияет на публичный статус |
| Cooldown не прошёл | См. ниже |
| У подписчика нет watch на эту АЗС + fuel | Не его избранное |

### Cooldown (рекомендация)

- **1 push на пару** `(push_subscription_id, station_id, fuel_type)` **раз в 30–60 минут**.
- Таблица `push_notification_log` или поле `last_notified_at` в watches.
- При повторном «пропало → появилось» в тот же час - не дублировать.

### Какие топливо отслеживать

**v1 (простой вариант):** только топливо из фильтра приложения (`selectedFuel`, по умолчанию A95) - передаётся в watch.

**v2:** watch на все `FuelType::all()` для каждой избранной АЗС (до 7 × 4 = 28 watches на устройство).

---

## База данных

### Миграция 1: расширить `push_subscriptions`

```sql
ALTER TABLE push_subscriptions ADD COLUMN client_id CHAR(36) NULL UNIQUE;
-- UUID с клиента, стабильный между переподписками (localStorage)
```

### Миграция 2: `push_subscription_watches`

```sql
CREATE TABLE push_subscription_watches (
    id BIGINT PRIMARY KEY,
    push_subscription_id BIGINT NOT NULL REFERENCES push_subscriptions(id) ON DELETE CASCADE,
    station_id BIGINT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
    fuel_type VARCHAR(10) NOT NULL,          -- a95, a92, dt, gas
    notify_available BOOLEAN DEFAULT TRUE,   -- задел на другие типы алертов
    last_marker_color VARCHAR(10) NULL,      -- кэш для diff без лишних запросов
    last_notified_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (push_subscription_id, station_id, fuel_type)
);
CREATE INDEX idx_watches_station_fuel ON push_subscription_watches (station_id, fuel_type);
```

### Миграция 3 (опционально): `push_notification_log`

Для отладки и админки: `subscription_id`, `station_id`, `fuel_type`, `report_id`, `sent_at`, `success`.

---

## API

### `PUT /api/push/watches`

Синхронизация избранного с push‑подпиской.

**Request:**

```json
{
  "endpoint": "https://fcm.googleapis.com/...",
  "client_id": "550e8400-e29b-41d4-a716-446655440000",
  "station_ids": [12, 45, 78],
  "fuel_type": "a95"
}
```

**Поведение:**

1. Найти `PushSubscription` по `endpoint` (404 если нет - клиент сначала subscribe).
2. Upsert `client_id` на подписке.
3. Заменить watches: удалить лишние, добавить новые (max 7 station_ids).
4. Для каждого watch проставить `last_marker_color` из текущего `fuelStatus()`.

**Response:** `{ "watches": 3 }`

Throttle: `30/min` на endpoint.

### `DELETE /api/push/watches`

Очистить все watches для endpoint (при отключении избранных алертов).

### Изменение `POST /api/push/subscribe`

Принимать опциональный `client_id` (UUID), сохранять на подписке.

---

## Backend‑сервисы

### `WebPushService::sendTo(array $subscriptionIds, string $title, string $body, ?string $url): int`

Выделить из `broadcast()` - отправка списку подписок, общий flush и очистка expired.

### `FavoriteFuelPushService`

```php
public function handleReport(Report $report, Station $station): void
{
    // 1. Пропустить confirmation / hidden
    // 2. old + new fuelStatus для report->fuel_type
    // 3. if (! transitioned to green) return
    // 4. Найти watches: station_id + fuel_type, notify_available=true
    // 5. Фильтр cooldown
    // 6. Сформировать title/body/url
    // 7. sendTo(...)
    // 8. Обновить last_notified_at, last_marker_color
}
```

**Точка вызова:** `ReportController::store` после create (не в `confirm`).

Альтернатива чище: **Laravel Observer** `Report::created` - один вход для store и будущих импортов.

**Текст body:**

```php
sprintf('На %s «%s» появился %s', $station->network, $station->name, $fuelType->label());
```

### Скрытие отчёта в админке

При `hideReport` пересчитать статус и при необходимости обновить `last_marker_color` у watches (push не слать).

---

## Frontend

### 1. Стабильный `client_id`

```js
// localStorage push_client_id - UUID, генерируется один раз
```

Передавать в `/api/push/subscribe` и `/api/push/watches`.

### 2. Синхронизация watches

Новый composable `useFavoritePushWatches.js`:

- `syncWatches(favoriteIds, selectedFuel)` - debounce 500 ms
- Вызывать при: toggle ★, изменении `selectedFuel`, успешном push subscribe, `onMounted` если `push_subscribed`

### 3. UX: отдельный переключатель (рекомендуется)

QR‑push и «мои АЗС» - разные сценарии. В настройках или рядом с фильтром «Мои АЗС»:

> 🔔 Уведомлять, когда на моих АЗС появится топливо

- Включено → `syncWatches`
- Выключено → `DELETE /api/push/watches`
- Требует разрешения Notification + активной push‑подписки

### 4. Deep link

В `App.vue` при загрузке:

```js
const params = new URLSearchParams(location.search);
const stationId = Number(params.get('station'));
const fuel = params.get('fuel');
// выбрать АЗС, fuel filter, открыть sheet
```

### 5. Service Worker

Заменить фиксированный `tag: 'sevazs-qr'` на `sevazs-fuel-{stationId}` - чтобы несколько алертов не затирали друг друга.

---

## Админка (минимум)

В `GET /api/admin/summary`:

- `push_watches_count`
- `fuel_push_sent_24h` (из log)

Опционально: экран последних fuel‑push в `AdminPushPanel`.

---

## План работ (чеклист)

### Backend

- [ ] Миграции `client_id`, `push_subscription_watches`, опционально log
- [ ] `WebPushService::sendTo()`
- [ ] `FavoriteFuelPushService` + вызов из Observer/Controller
- [ ] `PushWatchController` - sync / clear
- [ ] Cooldown + обновление `last_marker_color`
- [ ] Feature tests: transition green, no push on confirm, cooldown

### Frontend

- [ ] `client_id` + sync watches composable
- [ ] Toggle «уведомления по избранным» - **не делаем**: push автоматически при ★ + активной подписке
- [ ] Deep link `?station=&fuel=`
- [ ] SW: разные notification tags

### Deploy

```bash
git pull && composer install --no-dev && npm ci && npm run build
php artisan migrate --force
php artisan route:cache && php artisan config:cache
```

Cron не нужен - event‑driven при новом отчёте.

---

## Риски и ограничения

| Риск | Митигация |
|------|-----------|
| Избранное только локально на другом устройстве | Каждое устройство синхронизирует свой список |
| Переустановка PWA → новый endpoint | `client_id` помогает только для аналитики; watches привязаны к endpoint |
| Спам при активных отчётах | Cooldown 30–60 мин |
| Много подписчиков на одну АЗС | Индекс `(station_id, fuel_type)`, sendTo батчами |
| VAPID битый на prod | Уже есть `webpush:check` |

---

## Будущее (не в scope этапа 2)

- Push «закончился А‑95 на моей АЗС»
- Push «очередь выросла»
- Watch без избранного (радиус от геолокации)
- Server-side избранное с аккаунтом Telegram/MAX

---

## Оценка

| Часть | Объём |
|-------|-------|
| Backend | ~1–1.5 дня |
| Frontend + UX | ~0.5–1 день |
| Тесты + prod | ~0.5 дня |

**Итого:** ~2–3 дня на v1 (один fuel_type, toggle, cooldown, deep link).
