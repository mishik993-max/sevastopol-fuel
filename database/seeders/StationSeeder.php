<?php

namespace Database\Seeders;

use App\Models\Station;
use App\Services\StationImportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class StationSeeder extends Seeder
{
    public function run(): void
    {
        if (Station::query()->where('source', 'osm')->exists()) {
            return;
        }

        $importer = app(StationImportService::class);
        $jsonPath = database_path('seeders/data/stations-osm.json');

        if (file_exists($jsonPath)) {
            try {
                $result = $importer->importFromJsonFile($jsonPath);
                $this->command?->info(
                    "OSM JSON: импортировано {$result['imported']}, обновлено {$result['updated']}"
                );

                if ($result['imported'] + $result['updated'] > 0) {
                    Station::query()->whereNull('external_id')->delete();

                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('OSM JSON import failed', ['error' => $e->getMessage()]);
            }
        }

        $this->command?->warn('Для онлайн-импорта: php artisan stations:import-osm');
        $this->command?->warn('Пока используем CSV (запустите import-osm когда есть сеть)');

        $this->seedFromCsv();
    }

    private function seedFromCsv(): void
    {
        $path = database_path('seeders/data/stations.csv');

        if (! file_exists($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);

            Station::query()->updateOrCreate(
                [
                    'network' => $data['network'],
                    'name' => $data['name'],
                ],
                [
                    'source' => 'manual',
                    'address' => $data['address'],
                    'latitude' => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                ]
            );
        }

        fclose($handle);
    }
}
