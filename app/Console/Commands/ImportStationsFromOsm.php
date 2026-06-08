<?php

namespace App\Console\Commands;

use App\Services\StationImportService;
use App\Services\StationOsmSyncService;
use Illuminate\Console\Command;

class ImportStationsFromOsm extends Command
{
    protected $signature = 'stations:import-osm
                            {--fresh : Удалить старые АЗС без external_id перед импортом}
                            {--json= : Импорт из локального GeoJSON/OSM JSON (экспорт overpass-turbo)}
                            {--skip-sync : Не проверять закрытые АЗС в OpenStreetMap после импорта}';

    protected $description = 'Импорт реальных АЗС Севастополя из OpenStreetMap';

    public function handle(StationImportService $importer, StationOsmSyncService $syncService): int
    {
        if ($this->option('fresh')) {
            $deleted = \App\Models\Station::query()
                ->where(function ($q) {
                    $q->whereNull('external_id')
                        ->orWhereIn('source', ['manual', 'user']);
                })
                ->delete();
            $this->warn("Удалено устаревших записей: {$deleted}");
        }

        try {
            if ($json = $this->option('json')) {
                $this->info("Импорт из файла: {$json}");
                $result = $importer->importFromJsonFile($json);
            } else {
                $this->info('Поиск АЗС через Nominatim (сетка + местные сети)…');
                $result = $importer->importFromNominatim();

                if ($result['imported'] + $result['updated'] === 0) {
                    $this->warn('Nominatim пусто, пробуем Overpass…');
                    try {
                        $result = $importer->importFromOpenStreetMap();
                    } catch (\Throwable $overpassError) {
                        $this->warn('Overpass недоступен: '.$overpassError->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            $jsonPath = database_path('seeders/data/stations-osm.json');

            if (! $this->option('json') && file_exists($jsonPath)) {
                $this->warn('Онлайн-импорт недоступен, читаем stations-osm.json…');
                $result = $importer->importFromJsonFile($jsonPath);
            } else {
                $this->error('Ошибка импорта: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info("Импортировано: {$result['imported']}, обновлено: {$result['updated']}, пропущено: {$result['skipped']}");

        $sanitized = $importer->sanitizeExistingAddresses();
        if ($sanitized > 0) {
            $this->info("Адреса нормализованы: {$sanitized}");
        }

        $this->line('Всего в базе: '.\App\Models\Station::query()->count());

        if (! $this->option('skip-sync') && ! $this->option('json')) {
            $this->newLine();
            $this->info('Проверка закрытых АЗС в OpenStreetMap…');
            $sync = $syncService->syncAll();
            $this->info("OSM: проверено {$sync['checked']}, скрыто {$sync['deactivated']}, возвращено {$sync['reactivated']}");
            $this->line('Активных на карте: '.\App\Models\Station::query()->where('is_active', true)->count());
        }

        return self::SUCCESS;
    }
}
