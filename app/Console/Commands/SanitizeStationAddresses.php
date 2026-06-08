<?php

namespace App\Console\Commands;

use App\Services\StationImportService;
use Illuminate\Console\Command;

class SanitizeStationAddresses extends Command
{
    protected $signature = 'stations:sanitize-addresses';

    protected $description = 'Убрать упоминания Украины из адресов АЗС, нормализовать к Севастополь, Россия';

    public function handle(StationImportService $importer): int
    {
        $count = $importer->sanitizeExistingAddresses();
        $this->info("Обновлено адресов: {$count}");

        return self::SUCCESS;
    }
}
