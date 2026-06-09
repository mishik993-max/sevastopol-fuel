# Локальная разработка (Laragon, Windows)

## Требования

- [Laragon](https://laragon.org/) с PHP 8.2+, MySQL 8, Node.js 20+
- Composer (входит в Laragon: `E:\laragon\bin\composer\composer.phar`)
- Расширение PHP `zip` (в `php.ini`: `extension=zip`)

## 1. Клонирование и зависимости

```bash
cd E:\laragon\www\sevastopol-fuel

# PHP (путь Laragon)
php E:\laragon\bin\composer\composer.phar install

npm install
```

## 2. База данных

В Laragon запустите MySQL, затем:

```sql
CREATE DATABASE sevastopol_fuel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Скопируйте `.env.example` в `.env` (если ещё нет) и настройте:

```env
APP_NAME="Севастополь Топливо"
APP_URL=http://sevastopol-fuel.test
APP_TIMEZONE=Europe/Simferopol

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sevastopol_fuel
DB_USERNAME=root
DB_PASSWORD=
```

## 3. Ключ Яндекс.Карт

Карта использует **Яндекс.Карты JavaScript API 2.1**.

1. Зарегистрируйтесь на [developer.tech.yandex.ru](https://developer.tech.yandex.ru/)
2. Создайте ключ для **JavaScript API** (примите условия бесплатного использования)
3. В настройках ключа укажите **ограничение по HTTP Referer**- домен:
   - `sevastopol-fuel.test` (локально)
   - ваш продакшн-домен (на VPS)
   
   Ключ активируется до **15 минут**. Без домена запросы получают 403.

4. Добавьте в `.env`:

```env
VITE_YANDEX_MAPS_API_KEY=ваш_ключ
YANDEX_MAPS_API_KEY=ваш_ключ
```

После изменения `.env` пересоберите фронтенд: `npm run build` (или перезапустите `npm run dev`).

## 4. VAPID-ключи для push-уведомлений

```bash
npx web-push generate-vapid-keys --json
```

Добавьте в `.env`:

```env
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@example.com
VITE_VAPID_PUBLIC_KEY=...   # тот же public key
```

### «Разрешение не получено» при включении уведомлений

1. В запросе браузера нажмите **Разрешить** (не «Блокировать»).
2. Если уже блокировали: **замок** слева от адреса → **Уведомления** → **Разрешить** → обновите страницу (`Ctrl+Shift+R`).
3. Сбросьте флаг «Позже» в консоли браузера (F12):
   ```js
   localStorage.removeItem('push_dismissed'); location.reload();
   ```
4. Нужен **HTTPS** (`https://sevastopol-fuel.test`)- по обычному `http://` push не работает.
5. После `npm run build` обновите страницу (`Ctrl+Shift+R`)- кнопка **Включить** станет активной, когда Service Worker загрузится (обычно 2–5 сек).
6. Service Worker лежит в `public/sw.js` и `public/build/sw.js`- без `npm run build` push не работает.

## 5. Миграции и сиды

```bash
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

При первом `migrate --seed` АЗС загружаются из `database/seeders/data/stations-osm.json`- это **реальные заправки** из OpenStreetMap (ТЭС, WOG и др.), не выдуманные координаты.

### Обновить список АЗС из интернета (рекомендуется перед запуском)

```bash
php artisan stations:import-osm
```

Команда:
1. Загружает АЗС из OpenStreetMap (сетка 3×3 + сети: **Атан, ТЭС, Грифон** и др.)- обычно **80+** точек.
2. **Автоматически проверяет закрытые**- теги OSM `disused`, `abandoned`, `operational_status=closed` и скрывает такие АЗС с карты.

Только синхронизация статуса (без полного импорта):

```bash
php artisan stations:sync-osm-status
```

Быстрый импорт без проверки OSM: `php artisan stations:import-osm --skip-sync`

### Почему не Яндекс.Карты для справочника?

| Источник | Карта на сайте | Справочник АЗС в БД | Статус «закрыта» |
|----------|----------------|---------------------|------------------|
| **Яндекс JS API** | ✅ да | ❌ хранить нельзя (бесплатный тариф) | ❌ нет в API 2.1 |
| **Яндекс Поиск организаций** |- | ❌ платный, кэш запрещён | ⚠️ только в мобильном SDK |
| **OpenStreetMap** |- | ✅ открытые данные | ✅ теги `disused` / `abandoned` |

**Яндекс** используем только для **отображения карты**. Актуальность справочника - **OSM при импорте** + **сообщения пользователей** («АЗС не работает») + **ручное скрытие** (`stations:deactivate`).

> Если в OSM заправка ещё не помечена как закрытая (как CRS на Гидрографической), скройте вручную или дождитесь 3 сообщений от пользователей.  
`--fresh` удаляет старые записи без `external_id`.

Импорт из файла (экспорт с [overpass-turbo.eu](https://overpass-turbo.eu)):

```bash
php artisan stations:import-osm --json=путь/к/export.json
```

Запрос для Overpass Turbo:

```
[out:json][timeout:60];
area["name"="Севастополь"]->.a;
(node["amenity"="fuel"](area.a); way["amenity"="fuel"](area.a););
out center tags;
```

> **Почему не сохраняем из Яндекса?** На бесплатном тарифе Яндекс.Карт **запрещено сохранять** данные API в свою базу. OSM- открытые данные (ODbL), их можно хранить. **Карта** остаётся на Яндексе, **справочник АЗС**- из OSM.

## 6. Virtual host в Laragon (важно!)

**Открывайте:** `https://sevastopol-fuel.test/`- **без** `/public/` в адресе.

Если сейчас открывается только `https://sevastopol-fuel.test/public/`- Laragon смотрит на корень проекта, а не на `public/`. Из-за этого API (`/api/stations`) отдаёт 404.

**Исправление (выберите один вариант):**

1. **Laragon** → правый клик по проекту → **Document root** → укажите папку `public`
2. Или оставьте как есть - в корне проекта уже есть `.htaccess` и `index.php`, которые перенаправляют в `public/`. Тогда откройте `https://sevastopol-fuel.test/` (корень, не `/public/`).

В `.env` укажите:

```env
APP_URL=https://sevastopol-fuel.test
```

Для ключа Яндекс.Карт в Referer достаточно домена `sevastopol-fuel.test` (работает и для HTTPS).

## 7. Тест с телефона (локальная сеть)

ПК и телефон должны быть в **одной Wi‑Fi**. IP ПК: например `192.168.1.108` (узнать: `ipconfig` → IPv4).

### Быстрый способ (рекомендуется)

```bash
npm run build
npm run mobile
```

Скрипт сам находит PHP в `E:\laragon\bin\php\` (в Git Bash `php` часто не в PATH).

Или двойной клик: `scripts/serve-mobile.bat` (IP по умолчанию `192.168.1.108`, порт `8000`).

Из Git Bash напрямую:

```bash
/e/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan serve --host=0.0.0.0 --port=8000
```

**На телефоне откройте:** `http://192.168.1.108:8000`

Другой IP- в `.env`:

```env
MOBILE_LAN_IP=192.168.1.108
MOBILE_PORT=8000
```

### Яндекс.Карты с телефона

В [developer.tech.yandex.ru](https://developer.tech.yandex.ru/) для ключа JavaScript API добавьте в **Referer**:

- `192.168.1.108`
- или `http://192.168.1.108:*`

Без этого карта на телефоне не загрузится.

### Windows Firewall

Если с телефона не открывается - разрешите входящие для PHP/порта 8000 (частная сеть):

```powershell
netsh advfirewall firewall add rule name="Laravel mobile 8000" dir=in action=allow protocol=TCP localport=8000
```

### Через Laragon (без artisan serve)

Если Apache Laragon слушает сеть, можно: `http://192.168.1.108/sevastopol-fuel/`  
(путь зависит от папки в `www`). Удобнее всё же `npm run mobile` на порту 8000.

### Геозона (вход на сайт)

При первом открытии показывается экран **«Разрешить геолокацию»**. Доступ к карте только если вы **в Севастополе** (те же границы, что в `config/stations.php`).  
Повторная проверка не нужна в рамках одной вкладки браузера (`sessionStorage`).

### Ограничения по HTTP

| Функция | `http://192.168.1.108:8000` |
|---------|-------------------------------|
| **Вход на сайт** | ❌ геолокация требует **HTTPS** |
| Карта, API, отчёты | ✅ после входа по HTTPS |
| Push-уведомления | ❌ нужен HTTPS |

По HTTP с телефона сайт **не откроется**- используйте `https://sevastopol-fuel.test` или ngrok.

### Геолокация с телефона (HTTPS)

Вариант 1- **ngrok** (проще всего):

```bash
npm run mobile
# другой терминал:
ngrok http 8000
```

Откройте на телефоне `https://xxxx.ngrok-free.app`, добавьте этот домен в Referer Яндекс.Карт. «Рядом» и GPS заработают.

Вариант 2- тестируйте геолокацию на ПК по `https://sevastopol-fuel.test` (Laragon SSL).

## 8. Запуск

**Продакшн-сборка фронтенда:**

```bash
npm run build
```

**Разработка с hot-reload:**

```bash
# Терминал 1
php artisan serve

# Терминал 2
npm run dev
```

При использовании Laragon virtual host достаточно `npm run dev`- Apache/Nginx уже отдаёт `public/`.

## 9. Справочник, обучение, статистика, админка

- **?** в шапке - полный справочник и кнопка «Пройти обучение снова».
- **%**- статистика по сетям и видам топлива.
- При первом входе - короткий **онбординг-тур** по основным кнопкам.
- **Админка:** `https://sevastopol-fuel.test/admin`- модерация исправлений АЗС.  
  В `.env`: `ADMIN_PASSWORD=ваш_пароль`

## 10. Проверка API

```bash
curl http://sevastopol-fuel.test/api/stations?fuel=a95
curl "http://sevastopol-fuel.test/api/stations/nearby?lat=44.605&lng=33.522&fuel=a95"
```

## 11. QR-напоминания (тест)

**В Git Bash `php` часто не в PATH**- используйте:

```bash
npm run push:test
```

или полный путь Laragon:

```bash
E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan notifications:qr-reminder 22_00
```

Другие слоты: `21_30`, `21_45`, `21_55`, `22_00`.

### Если уведомление не пришло

1. Сайт открыт по **HTTPS** (`https://sevastopol-fuel.test`), нажато **«Включить»** (в БД должна быть подписка).
2. После `npm run build`- **Ctrl+Shift+R** и снова **«Включить»** (обновился Service Worker).
3. Windows: Параметры → Уведомления → разрешить для браузера.
4. Ручная отправка: `npm run push:test`- в консоли должно быть `Доставлено 1 из 1`.
5. **Автоматически не сработает**, если не настроен планировщик (см. ниже).

Для автоматической рассылки по расписанию на Windows добавьте задачу в Планировщик заданий:

```
php E:\laragon\www\sevastopol-fuel\artisan schedule:run
```

каждую минуту.

## Закрытые / неработающие АЗС

**На карте (пользователи):** в карточке АЗС кнопка **«АЗС больше не работает (N/5)»**- видно, сколько пометок уже есть. После **5 сообщений** от разных людей заправка **скрывается** с карты (порог в `.env`: `STATION_CLOSURE_REPORTS`, по умолчанию 5; срок не ограничен).

**Исправление данных:** кнопка **«Исправить название или место»**- можно изменить название, адрес или перенести маркер на карте. Изменение **не применяется сразу**: нужно **5 подтверждений** «Данные верны» от разных пользователей (`.env`: `STATION_CORRECTION_CONFIRMATIONS`). Ожидающие исправления видны в карточке АЗС.

**Вручную (админ):**

```bash
# Найти и скрыть по адресу или названию
php artisan stations:deactivate --search="Гидрографическая 2А" --reason="CRS закрыта"

# По ID
php artisan stations:deactivate --id=95 --reason="CRS закрыта"

# Список скрытых
php artisan stations:list-inactive

# Вернуть на карту
php artisan stations:reactivate --id=95
```

> Повторный `stations:import-osm` **не вернёт** скрытую АЗс - флаг `is_active` сохраняется.

## Добавление АЗС

**В приложении:** кнопка **+** на карте → сеть, адрес, точка на карте (или «Где я»).  
`POST /api/stations`- лимит 5 запросов в час с IP; дубликаты в радиусе 80 м отклоняются.  
Пользовательские АЗС (`source=user`) **не удаляются** при `stations:import-osm --fresh`.

**Вручную (CSV):** отредактируйте `database/seeders/data/stations.csv`:

```csv
network,name,address,latitude,longitude
WOG,Новая АЗС,ул. Пример 1,44.6000000,33.5200000
```

Затем: `php artisan db:seed --class=StationSeeder`
