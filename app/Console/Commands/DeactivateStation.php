<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\StationClosureService;
use Illuminate\Console\Command;

class DeactivateStation extends Command
{
    protected $signature = 'stations:deactivate
                            {--id= : ID АЗС}
                            {--search= : Поиск по названию, сети или адресу}
                            {--reason= : Причина закрытия}';

    protected $description = 'Скрыть АЗС с карты (закрыта, не работает)';

    public function handle(StationClosureService $closureService): int
    {
        $station = $this->resolveStation();

        if ($station === null) {
            return self::FAILURE;
        }

        $reason = $this->option('reason') ?: 'Закрыта вручную';
        $closureService->deactivate($station, $reason);

        $this->info("Скрыта: [{$station->id}] {$station->network} {$station->name}- {$station->address}");

        return self::SUCCESS;
    }

    private function resolveStation(): ?Station
    {
        if ($id = $this->option('id')) {
            $station = Station::query()->find($id);

            if ($station === null) {
                $this->error("АЗС с id={$id} не найдена");

                return null;
            }

            return $station;
        }

        $search = trim((string) $this->option('search'));

        if ($search === '') {
            $this->error('Укажите --id= или --search=');

            return null;
        }

        $matches = Station::query()
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('network', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            })
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            $this->error("Ничего не найдено по запросу: {$search}");

            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->warn('Найдено несколько АЗС:');
        foreach ($matches as $station) {
            $status = $station->is_active ? 'активна' : 'скрыта';
            $this->line("  [{$station->id}] {$station->network} {$station->name}- {$station->address} ({$status})");
        }

        $this->error('Уточните --id= из списка выше');

        return null;
    }
}
