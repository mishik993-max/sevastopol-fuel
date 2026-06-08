<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\StationClosureService;
use Illuminate\Console\Command;

class ReactivateStation extends Command
{
    protected $signature = 'stations:reactivate {--id= : ID АЗС}';

    protected $description = 'Вернуть скрытую АЗС на карту';

    public function handle(StationClosureService $closureService): int
    {
        $id = $this->option('id');

        if (! $id) {
            $this->error('Укажите --id=');

            return self::FAILURE;
        }

        $station = Station::query()->find($id);

        if ($station === null) {
            $this->error("АЗС с id={$id} не найдена");

            return self::FAILURE;
        }

        $closureService->reactivate($station);

        $this->info("Снова на карте: [{$station->id}] {$station->network} {$station->name}");

        return self::SUCCESS;
    }
}
