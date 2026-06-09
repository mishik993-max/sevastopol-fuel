# План нагрузочного тестирования

Цель: понять, сколько одновременных пользователей выдержит VPS при типичных сценариях (просмотр карты, отправка отчётов).

## Что тестируем

| Сценарий | Endpoint | Доля трафика |
|----------|----------|--------------|
| Открытие карты | `GET /api/stations?fuel=a95` | ~70% |
| Карточка АЗС | `GET /api/stations/{id}?fuel=a95` | ~15% |
| Рядом со мной | `GET /api/stations/nearby?lat=44.6&lng=33.5&fuel=a95` | ~10% |
| Настройки | `GET /api/settings` | ~5% |

Отправку отчётов (`POST /api/reports`) тестируем отдельно с низкой частотой - там лимиты throttle.

## Подготовка

1. Разверните staging на том же типе VPS, что и production.
2. Импортируйте АЗС (`/admin` → Импорт OSM).
3. Установите [k6](https://k6.io/docs/get-started/installation/):

```bash
# Ubuntu
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | \
  sudo tee /etc/apt/sources.list.d/k6.list
sudo apt update && sudo apt install k6
```

4. Скопируйте скрипт `scripts/load-test/stations.js` на машину, с которой будете гонять тест (можно с ноутбука).

## Базовый прогон

```bash
export BASE_URL=https://fuel.example.com
k6 run scripts/load-test/stations.js
```

По умолчанию: 50 виртуальных пользователей, 2 минуты разгон + 5 минут плато.

## Этапы наращивания

| Этап | VUs | Длительность | Ожидание |
|------|-----|--------------|----------|
| 1 | 20 | 3 мин | p95 < 500 ms, 0% ошибок |
| 2 | 50 | 5 мин | p95 < 1 s |
| 3 | 100 | 5 мин | смотрим CPU/RAM |
| 4 | 200 | 3 мин | ищем точку деградации |

Меняйте в скрипте блок `options.stages` или передайте переменные окружения.

## Метрики на сервере

Во время теста на VPS:

```bash
htop
# или
vmstat 1
tail -f /var/log/nginx/access.log
```

Смотрите:

- CPU PHP-FPM (часто узкое место)
- MySQL connections
- Nginx `502` / `504`

## Критерии «достаточно для запуска»

Для карты Севастополя (~100–150 АЗС) на VPS 1 vCPU / 2 GB:

- **50 одновременных** читателей карты - обычно комфортно
- **100** - возможно при кэше и `config:cache`
- **200+** - нужен второй vCPU или Redis-кэш для `/api/stations`

Ориентир: если при 50 VUs `http_req_failed < 1%` и `http_req_duration p(95) < 800ms` - можно запускать публично и мониторить.

## Тест записи (осторожно)

Отдельный короткий сценарий с 5 VUs и 1 запрос/10 с на `POST /api/reports` - только на staging, чтобы не засорить production.

## После теста

- Зафиксируйте результаты k6 (JSON: `k6 run --out json=results.json`)
- При необходимости: `php artisan config:cache`, увеличение `pm.max_children` в PHP-FPM, апгрейд VPS

## Скрипт

См. `scripts/load-test/stations.js` в репозитории.
