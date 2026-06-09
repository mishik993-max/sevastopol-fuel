# Домен, привязка к VPS и SSL

Три сценария: **с доменом** (рекомендуется), **без домена по IP**, **временно для теста**.

## Что выбрать

| Сценарий | HTTPS | Push/PWA | Сложность |
|----------|-------|----------|-----------|
| Домен + Let's Encrypt | ✅ доверенный, 90 дней | ✅ | средняя |
| IP + Let's Encrypt (2026) | ✅ доверенный, **~6 дней** | ✅ | выше |
| IP + самоподписанный | ⚠️ предупреждение в браузере | ⚠️ часто не работает | низкая |
| Только HTTP по IP | ❌ | ❌ push не работает | минимальная |

**Для продакшена лучше купить короткий домен** (в т.ч. `.su`) — проще SSL, push и ссылки для пользователей.

---

## 1. Привязка домена к VPS (пошагово)

### Шаг 1. Купить домен

Регистраторы `.su`: [nic.ru](https://www.nic.ru), [reg.ru](https://www.reg.ru), [webnames.ru](https://webnames.ru) и др.

После оплаты домен появится в личном кабинете. Запишите его: например `sevazs.ru`.

### Шаг 2. Узнать IP VPS

В панели хостинга (Timeweb, Selectel, Hetzner…) скопируйте **публичный IPv4**, например `185.12.34.56`.

### Шаг 3. Создать DNS-записи

В кабинете регистратора откройте **DNS / Управление зоной / DNS-серверы**.

**Вариант А — сайт на корне домена** (`https://sevazs.ru`):

| Тип | Имя (хост) | Значение | TTL |
|-----|------------|----------|-----|
| A | `@` | `185.12.34.56` | 300–3600 |

**Вариант Б — поддомен** (`https://fuel.sevazs.ru`):

| Тип | Имя | Значение | TTL |
|-----|-----|----------|-----|
| A | `fuel` | `185.12.34.56` | 300–3600 |

Частые ошибки:

- В поле «Имя» у некоторых панелей `@` пишут как пустое поле или сам домен `sevazs.ru`.
- Не ставьте `http://` в значение — только IP.
- Если DNS ещё на старых NS регистратора по умолчанию — менять записи нужно **там**, где сейчас указаны NS (вкладка «DNS-серверы»).

### Шаг 4. Дождаться распространения DNS

Обычно 5–30 минут, иногда до 24 ч.

Проверка с VPS или ПК:

```bash
dig +short sevazs.ru
dig +short fuel.sevazs.ru
nslookup sevazs.ru
```

Должен вернуться IP вашего VPS.

### Шаг 5. Nginx и `.env`

В `/etc/nginx/sites-available/sevastopol-fuel`:

```nginx
server_name sevazs.ru;   # или fuel.sevazs.ru
```

В `.env`:

```env
APP_URL=https://sevazs.ru
```

Пересборка и кэш:

```bash
npm run build
php artisan config:cache
sudo nginx -t && sudo systemctl reload nginx
```

### Шаг 6. SSL Let's Encrypt (с доменом)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d sevazs.ru
# или с www:
sudo certbot --nginx -d sevazs.ru -d www.sevazs.ru
```

Certbot сам пропишет HTTPS в Nginx и настроит автообновление.

Проверка:

```bash
sudo certbot renew --dry-run
curl -I https://sevazs.ru
```

---

## 2. Запуск без домена (только IP)

### HTTP (быстрый старт, без HTTPS)

Nginx:

```nginx
server {
    listen 80;
    server_name 185.12.34.56;   # ваш IP
    # ... остальное как в DEPLOY.md
}
```

`.env`:

```env
APP_URL=http://185.12.34.56
```

Карта откроется, но **Web Push и PWA установка не заработают** — браузеры требуют HTTPS (кроме localhost).

### HTTPS по IP — Let's Encrypt (с 2026)

Let's Encrypt выдаёт сертификаты **на IP**, но:

- срок **~6 дней** (профиль `shortlived`);
- `certbot --nginx` **пока не умеет** ставить IP-сертификат автоматически — пути к файлам прописывают вручную;
- нужен **Certbot 5.3+**.

```bash
sudo apt install -y certbot

# Проверка версии (нужна 5.3+)
certbot --version

# Сначала тест на staging:
sudo certbot certonly --staging \
  --preferred-profile shortlived \
  --webroot -w /var/www/sevastopol-fuel/public \
  --ip-address 185.12.34.56

# Боевой сертификат (без --staging):
sudo certbot certonly \
  --preferred-profile shortlived \
  --webroot -w /var/www/sevastopol-fuel/public \
  --ip-address 185.12.34.56
```

Файлы появятся в `/etc/letsencrypt/live/…/`. Добавьте в Nginx **второй** `server` или блок `listen 443 ssl`:

```nginx
server {
    listen 443 ssl;
    listen 80;
    server_name 185.12.34.56;

    ssl_certificate     /etc/letsencrypt/live/185.12.34.56/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/185.12.34.56/privkey.pem;

    root /var/www/sevastopol-fuel/public;
    index index.php;

    client_max_body_size 3M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Пути к `fullchain.pem` / `privkey.pem` уточните:

```bash
sudo ls -la /etc/letsencrypt/live/
```

**Обновление** — чаще обычного (сертификат живёт ~6 дней):

```bash
sudo certbot renew
```

Убедитесь, что cron certbot активен (`/etc/cron.d/certbot` или systemd timer).

`.env`:

```env
APP_URL=https://185.12.34.56
```

### HTTPS по IP — самоподписанный (только тест)

Браузер покажет «Небезопасно». Push на телефонах **скорее всего не заработает**.

```bash
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/ssl/private/sevastopol-fuel.key \
  -out /etc/ssl/certs/sevastopol-fuel.crt \
  -subj "/CN=185.12.34.56"

sudo chmod 600 /etc/ssl/private/sevastopol-fuel.key
```

В Nginx:

```nginx
ssl_certificate     /etc/ssl/certs/sevastopol-fuel.crt;
ssl_certificate_key /etc/ssl/private/sevastopol-fuel.key;
```

---

## 3. Идеи короткого домена `.su`

Проверяйте свободность в поиске на [nic.ru](https://www.nic.ru) или [reg.ru](https://www.reg.ru) — ниже варианты **по смыслу**, не гарантия что свободны.

| Домен | Почему удобно |
|-------|----------------|
| **sevazs.ru** | Севастополь + АЗС, 7 символов до точки |
| **azs-sev.su** | Понятно с первого взгляда |
| **sevfuel.su** | Коротко, «топливо Севаста» |
| **zaprav.su** | От «заправка», 6 букв |
| **benz92.su** | Бензин + 92, узнаваемо |
| **qr-azs.su** | Акцент на QR-напоминания |
| **goazs.su** | «Поехали на АЗС», 5 букв |
| **topazs.su** | Топливо + АЗС |
| **sevbenz.su** | Сев + benz, читаемо |
| **fuelmap.su** | Карта топлива (может быть занят) |

**Критерии хорошего имени для этого проекта:**

- до 8–10 символов до `.su` — легко диктовать и вбивать;
- без дефиса — проще говорить вслух (но `azs-sev` тоже норм);
- латиница — меньше путаницы в DNS и SSL;
- не цифры в начале (иногда проблемы с некоторыми сервисами).

**Рекомендация:** если свободен — **`sevazs.ru`** или **`zaprav.su`**: коротко, по-русски понятно, хорошо смотрится в ссылке `https://sevazs.ru`.

После покупки — раздел **«1. Привязка домена»** выше.

---

## 4. Чеклист

- [ ] DNS A-запись указывает на IP VPS
- [ ] `dig +short ваш-домен` возвращает правильный IP
- [ ] `APP_URL` в `.env` совпадает с реальным URL (http/https, домен или IP)
- [ ] `npm run build` после смены URL
- [ ] `php artisan config:cache`
- [ ] HTTPS открывается без ошибок (для push обязательно)
- [ ] `sudo certbot renew --dry-run` проходит (для LE)

## Связанные документы

- [VPS-SETUP.md](VPS-SETUP.md) — полная установка с нуля
- [DEPLOY.md](DEPLOY.md) — краткий деплой
