<?php

namespace App\Console\Commands;

use App\Services\SevtechFuelSyncService;
use Illuminate\Console\Command;

class SyncSevtechFuel extends Command
{
    protected $signature = 'stations:sync-sevtech';

    protected $description = 'Синхронизировать наличие топлива с fuel.sevtech.org/map';

    public function handle(SevtechFuelSyncService $sync): int
    {
        if (! config('sevtech.enabled')) {
            $this->warn('SEVTECH_FUEL_ENABLED=false — синхронизация пропущена');

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Создано отчётов: {$result['created']}, пропущено без изменений: {$result['skipped']}");

        if (($result['updated_stations'] ?? []) !== []) {
            $this->info('Обновлены данные АЗС: '.implode('; ', $result['updated_stations']));
        }

        return self::SUCCESS;
    }
}
