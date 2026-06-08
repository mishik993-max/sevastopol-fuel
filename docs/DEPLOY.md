# Деплой на Linux VPS (Ubuntu 22.04/24.04)

Пошаговая инструкция для чистого сервера.

## 1. Подготовка сервера

Нужен **PHP 8.3+** (`composer.json`). При создании VPS выбирайте **Ubuntu 24.04 LTS** (рекомендуется) или **22.04 LTS**.

> **Не используйте Ubuntu 26.04** для этой инструкции: PPA `ondrej/php` для неё не опубликован (ошибка `404 ... resolute`), пакетов `php8.3-`* в apt нет.

Сначала проверьте ОС:

```bash
cat /etc/os-release | grep -E '^(VERSION_ID|VERSION_CODENAME)='
```

### Ubuntu 22.04 / 24.04

На **22.04** пакетов `php8.3-`* в стандартных репозиториях нет — нужен PPA. На **24.04** PHP 8.3 часто уже есть без PPA; если `apt install php8.3-fpm` находит пакет, PPA можно не добавлять.

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y   # только 22.04/24.04; на 26.04 — ошибка 404
sudo apt update
sudo apt install -y nginx mysql-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl unzip git curl
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
php -v   # 8.3.x
```

### Ubuntu 26.04 (если VPS уже на ней)

Удалите сломанный PPA (если добавляли) и ставьте PHP из стандартных репозиториев (обычно **8.4** или **8.5** — для проекта подходит):

```bash
sudo add-apt-repository --remove ppa:ondrej/php -y 2>/dev/null || true
sudo rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*
sudo apt update
apt-cache search '^php[0-9]' | grep -E 'fpm$'   # смотрим версию, например php8.4-fpm

PHPV=8.4   # подставьте версию из вывода выше
sudo apt install -y nginx mysql-server \
  php${PHPV}-fpm php${PHPV}-cli php${PHPV}-mysql php${PHPV}-mbstring php${PHPV}-xml php${PHPV}-curl \
  php${PHPV}-zip php${PHPV}-gd php${PHPV}-bcmath php${PHPV}-intl unzip git curl
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
php -v
```

В конфиге Nginx (§7) замените сокет: `php8.3-fpm.sock` → `php${PHPV}-fpm.sock` (например `php8.4-fpm.sock`).

Установите Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 2. MySQL

```bash
sudo mysql -e "CREATE DATABASE sevastopol_fuel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'fuel'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';"
sudo mysql -e "GRANT ALL ON sevastopol_fuel.* TO 'fuel'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

## 3. Код приложения

```bash
sudo mkdir -p /var/www/sevastopol-fuel
sudo chown $USER:www-data /var/www/sevastopol-fuel
cd /var/www/sevastopol-fuel

git clone <URL_РЕПОЗИТОРИЯ> .
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

## 4. Настройка .env

```bash
cp .env.example .env
php artisan key:generate
```

Отредактируйте `/var/www/sevastopol-fuel/.env`:

```env
APP_NAME="Севастополь Топливо"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fuel.example.com
APP_TIMEZONE=Europe/Simferopol

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sevastopol_fuel
DB_USERNAME=fuel
DB_PASSWORD=STRONG_PASSWORD

# Яндекс.Карты (developer.tech.yandex.ru)
VITE_YANDEX_MAPS_API_KEY=...
YANDEX_MAPS_API_KEY=...

# VAPID (сгенерировать на сервере)
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@example.com
VITE_VAPID_PUBLIC_KEY=...
```

Генерация VAPID:

```bash
npx web-push generate-vapid-keys --json
```

## 5. Миграции

```bash
php artisan migrate --seed --force
php artisan storage:link
# Импорт АЗС: через /admin → «Импорт OSM» или CLI:
# php artisan stations:import-osm
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 6. Права

```bash
sudo chown -R www-data:www-data /var/www/sevastopol-fuel/storage
sudo chown -R www-data:www-data /var/www/sevastopol-fuel/bootstrap/cache
sudo chmod -R 775 /var/www/sevastopol-fuel/storage
sudo chmod -R 775 /var/www/sevastopol-fuel/bootstrap/cache
```

## 7. Nginx

`/etc/nginx/sites-available/sevastopol-fuel`:

```nginx
server {
    listen 80;
    server_name fuel.example.com;
    root /var/www/sevastopol-fuel/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/sevastopol-fuel /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 8. SSL

**С доменом** (после привязки DNS — см. [DOMAIN-AND-SSL.md](DOMAIN-AND-SSL.md)):

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d sevazs.su
```

**Без домена (только IP):** Let's Encrypt на IP (~6 дней) или самоподписанный сертификат — [DOMAIN-AND-SSL.md §2](DOMAIN-AND-SSL.md#2-запуск-без-домена-только-ip).  
`certbot --nginx -d 1.2.3.4` **не сработает** — нужен другой способ.

## 9. Cron — обязателен для QR-напоминаний

Команда `notifications:qr-reminder-tick` запускается **каждую минуту** через `schedule:run` и сама решает, слать ли push в слоты 21:30–22:00 (Europe/Simferopol).

```bash
sudo crontab -u www-data -e
```

Добавьте:

```cron
* * * * * cd /var/www/sevastopol-fuel && php artisan schedule:run >> /dev/null 2>&1
```

Проверка:

```bash
php artisan schedule:list
php artisan notifications:qr-reminder-tick
```

## 10. Тексты уведомлений

Редактируются без изменения кода в `config/notifications.php`:


| Слот  | Время |
| ----- | ----- |
| 21_30 | 21:30 |
| 21_45 | 21:45 |
| 21_55 | 21:55 |
| 22_00 | 22:00 |


После правки: `php artisan config:cache`

## 11. Обновление

```bash
cd /var/www/sevastopol-fuel
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 12. Чеклист после деплоя

- `https://fuel.example.com` открывается, карта загружается
- `/api/stations` возвращает JSON с АЗС
- Можно отправить отчёт «Сообщить»
- Кнопка «Подтверждаю» работает
- Push-подписка запрашивает разрешение (HTTPS обязателен)
- `php artisan schedule:list` показывает `notifications:qr-reminder-tick`
- Cron настроен для `www-data`
- `/admin` — модерация отчётов и импорт OSM работают
- Фото отчётов открываются (`storage:link`)

## Примечания

- **Web Push работает только по HTTPS** (кроме localhost).
- Для PWA пользователи должны «Добавить на экран» или установить через браузер.
- Загрузка фото: до 2 МБ, jpg/png, хранятся в `storage/app/public/reports/`.
- Полная инструкция с покупки VPS: [VPS-SETUP.md](VPS-SETUP.md).
- Нагрузочные тесты: [LOAD-TEST.md](LOAD-TEST.md).

