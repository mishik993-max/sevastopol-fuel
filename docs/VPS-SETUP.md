# Настройка VPS с нуля (от покупки до рабочего сайта)

Пошаговая инструкция для Ubuntu 22.04/24.04. Подойдёт Timeweb, Selectel, Hetzner, DigitalOcean и аналоги.

## Что понадобится

- VPS: минимум 1 vCPU, 1–2 GB RAM, 20 GB SSD (для старта хватит)
- Домен (например `fuel.example.com`)
- SSH-ключ на вашем ПК (рекомендуется вместо пароля root)
- Репозиторий проекта (Git)

## 1. Покупка и первый вход

1. Создайте VPS с **Ubuntu 22.04 или 24.04**.
2. При создании добавьте свой **SSH public key** (из `~/.ssh/id_ed25519.pub` или сгенерируйте: `ssh-keygen -t ed25519`).
3. Запишите **IP-адрес** сервера.
4. Подключитесь с ПК:

```bash
ssh root@ВАШ_IP
```

Если используете пароль - смените его сразу после входа: `passwd`.

## 2. Базовая безопасность

```bash
apt update && apt upgrade -y
apt install -y ufw fail2ban

# Отдельный пользователь (не работайте постоянно под root)
adduser deploy
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

# Firewall
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw enable
```

Дальше работайте под `deploy`:

```bash
ssh deploy@ВАШ_IP
```

## 3. Установка зависимостей

Проекту нужен **PHP 8.3+** (`composer.json`).

> **Важно:** образ VPS должен быть **Ubuntu 22.04 или 24.04 LTS**. На **Ubuntu 26.04** (`resolute`) PPA `ondrej/php` не работает (404), пакетов `php8.3-*` нет - см. [DEPLOY.md §1](DEPLOY.md#1-подготовка-сервера) или пересоздайте VPS с Ubuntu 24.04.

```bash
sudo apt update && sudo apt upgrade -y
cat /etc/os-release | grep -E '^(VERSION_ID|VERSION_CODENAME)='
```

### Ubuntu 22.04 / 24.04

На **22.04** без PPA пакетов `php8.3-*` нет. На **24.04** PHP 8.3 часто уже в стандартных репозиториях - сначала проверьте `apt-cache search php8.3-fpm`.

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y   # только 22.04/24.04
sudo apt update

sudo apt install -y nginx mysql-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl unzip git curl

curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**Debian** (не Ubuntu): [репозиторий Sury](https://packages.sury.org/php/README.txt) или образ **Ubuntu 22.04/24.04**.

Проверка (для PHP 8.3):

```bash
php -v          # 8.3.x или новее
php-fpm8.3 -v
node -v
composer -V
nginx -v
systemctl status php8.3-fpm --no-pager
```

## 4. MySQL

```bash
sudo mysql -e "CREATE DATABASE sevastopol_fuel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'fuel'@'localhost' IDENTIFIED BY 'СИЛЬНЫЙ_ПАРОЛЬ';"
sudo mysql -e "GRANT ALL ON sevastopol_fuel.* TO 'fuel'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

## 5. Домен (привязка к VPS)

> **Без домена?** Можно открыть сайт по IP, но для push нужен HTTPS - см. [DOMAIN-AND-SSL.md](DOMAIN-AND-SSL.md) (SSL на IP или самоподписанный).

### 5.1. Купить домен

Регистраторы зоны `.su`: nic.ru, reg.ru, webnames.ru. Идеи коротких имён - в [DOMAIN-AND-SSL.md §3](DOMAIN-AND-SSL.md#3-идеи-короткого-домена-su).

### 5.2. DNS у регистратора

В личном кабинете → **DNS / Управление зоной**:

| Цель | Тип | Имя | Значение |
|------|-----|-----|----------|
| `https://sevazs.ru` | A | `@` | IP VPS |
| `https://fuel.sevazs.ru` | A | `fuel` | IP VPS |

Подождите 5–30 минут. Проверка:

```bash
dig +short sevazs.ru
```

Полная инструкция с типичными ошибками: [DOMAIN-AND-SSL.md §1](DOMAIN-AND-SSL.md#1-привязка-домена-к-vps-пошагово).

## 6. Код приложения

```bash
sudo mkdir -p /var/www/sevastopol-fuel
sudo chown deploy:www-data /var/www/sevastopol-fuel
cd /var/www/sevastopol-fuel

git clone <URL_РЕПОЗИТОРИЯ> .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

## 7. Файл `.env` (production)

Отредактируйте `/var/www/sevastopol-fuel/.env`:

```env
APP_NAME="Севастополь Топливо"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fuel.example.com
APP_TIMEZONE=Europe/Simferopol

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=sevastopol_fuel
DB_USERNAME=fuel
DB_PASSWORD=СИЛЬНЫЙ_ПАРОЛЬ

ADMIN_PASSWORD=длинный_пароль_для_админки

VITE_YANDEX_MAPS_API_KEY=...
YANDEX_MAPS_API_KEY=...

VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@example.com
VITE_VAPID_PUBLIC_KEY=...
```

VAPID-ключи:

```bash
npx web-push generate-vapid-keys --json
```

## 8. Сборка фронта и БД

```bash
npm ci && npm run build

php artisan migrate --seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Импорт АЗС - **через админку** (`/admin` → «Импорт OSM») или CLI:

```bash
php artisan stations:import-osm
```

## 9. Права на файлы

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 10. Nginx

```bash
sudo nano /etc/nginx/sites-available/sevastopol-fuel
```

```nginx
server {
    listen 80;
    server_name fuel.example.com;
    root /var/www/sevastopol-fuel/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    client_max_body_size 6M;

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

## 11. SSL

### С доменом (рекомендуется)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d sevazs.ru
```

Certbot настроит HTTPS и автообновление (~90 дней).

### Без домена - только IP

**Let's Encrypt на IP** возможен (Certbot 5.3+, сертификат ~6 дней, Nginx настраивается вручную).  
**Самоподписанный** - для теста, браузер ругается, push на телефонах часто не работает.

Подробные команды и конфиг Nginx: [DOMAIN-AND-SSL.md §2](DOMAIN-AND-SSL.md#2-запуск-без-домена-только-ip).

## 12. Cron (обязателен для push-напоминаний)

```bash
sudo crontab -u www-data -e
```

Добавьте строку:

```cron
* * * * * cd /var/www/sevastopol-fuel && php artisan schedule:run >> /dev/null 2>&1
```

Проверка:

```bash
php artisan schedule:list
```

Должна быть задача `notifications:qr-reminder-tick` каждую минуту.

## 13. Проверка после запуска

- [ ] `https://fuel.example.com` - карта открывается
- [ ] `https://fuel.example.com/api/stations` - JSON с АЗС
- [ ] Отчёт «Сообщить» сохраняется, фото открывается по ссылке
- [ ] `/admin` - вход по `ADMIN_PASSWORD`
- [ ] Push работает (только HTTPS)
- [ ] `php artisan schedule:list` - cron настроен

## 14. Обновление версии

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

## 15. Типичные проблемы

| Симптом | Решение |
|---------|---------|
| `Unable to locate package php8.3-*` | VPS на Ubuntu 26.04 - PPA не поддерживается; см. [DEPLOY.md §1](DEPLOY.md#ubuntu-2604-если-vps-уже-на-ней) или пересоздайте VPS с **24.04 LTS** |
| 502 Bad Gateway | `sudo systemctl status php8.3-fpm nginx` (или `php8.4-fpm` на Ubuntu 26.04) |
| Фото не открываются | `php artisan storage:link`, права на `storage/` |
| Карта пустая | импорт OSM в админке или `stations:import-osm` |
| Push не приходит | HTTPS, VAPID в `.env`, cron для `schedule:run` |
| 500 после деплоя | `storage/logs/laravel.log`, `APP_DEBUG=true` временно |

## 16. Нагрузочное тестирование

См. [LOAD-TEST.md](LOAD-TEST.md) - сценарии k6 и оценка пропускной способности.

## Связанные документы

- [DEPLOY.md](DEPLOY.md) - краткий чеклист деплоя
- [LOCAL.md](LOCAL.md) - разработка на Laragon
