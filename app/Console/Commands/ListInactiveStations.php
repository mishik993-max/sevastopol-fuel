<?php

namespace App\Console\Commands;

use App\Models\Station;
use Illuminate\Console\Command;

class ListInactiveStations extends Command
{
    protected $signature = 'stations:list-inactive';

    protected $description = 'Список скрытых (закрытых) АЗС';

    public function handle(): int
    {
        $stations = Station::query()
            ->where('is_active', false)
            ->orderByDesc('closed_at')
            ->get();

        if ($stations->isEmpty()) {
            $this->info('Скрытых АЗС нет');

            return self::SUCCESS;
        }

        foreach ($stations as $station) {
            $closed = $station->closed_at?->format('d.m.Y H:i') ?? '-';
            $this->line("[{$station->id}] {$station->network} {$station->name}");
            $this->line("    {$station->address}");
            $this->line("    закрыта: {$closed}- {$station->closed_reason}");
        }

        $this->newLine();
        $this->info("Всего скрытых: {$stations->count()}");

        return self::SUCCESS;
    }
}
