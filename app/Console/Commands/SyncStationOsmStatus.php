<?php

namespace App\Console\Commands;

use App\Services\StationOsmSyncService;
use Illuminate\Console\Command;

class SyncStationOsmStatus extends Command
{
    protected $signature = 'stations:sync-osm-status';

    protected $description = 'Скрыть АЗС, помеченные как закрытые в OpenStreetMap (disused, abandoned и т.д.)';

    public function handle(StationOsmSyncService $syncService): int
    {
        $this->info('Проверка статуса АЗС в OpenStreetMap (Nominatim details)…');
        $this->warn('Это займёт ~2 мин на 80+ АЗС (лимит Nominatim 1 запрос/сек).');

        $result = $syncService->syncAll();

        $this->info("Проверено: {$result['checked']}");
        $this->info("Скрыто по OSM: {$result['deactivated']}");
        $this->info("Возвращено на карту: {$result['reactivated']}");

        if ($result['failed'] > 0) {
            $this->warn("Не удалось проверить: {$result['failed']}");
        }

        $active = \App\Models\Station::query()->where('is_active', true)->count();
        $this->line("Активных на карте: {$active}");

        return self::SUCCESS;
    }
}
