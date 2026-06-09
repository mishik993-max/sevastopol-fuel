<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\PushSubscriptionWatch;
use App\Models\Report;
use App\Models\Station;
use App\Services\StationStatusService;
use App\Support\VapidKeys;
use Illuminate\Console\Command;

class CheckFuelPush extends Command
{
    protected $signature = 'fuel-push:check {--station= : ID АЗС} {--fuel=a95 : Тип топлива}';

    protected $description = 'Диагностика push «появился топливо» (подписки, watches, статус АЗС)';

    public function handle(StationStatusService $statusService): int
    {
        $this->line('=== Fuel push ===');

        try {
            VapidKeys::config();
            $this->info('VAPID: OK');
        } catch (\InvalidArgumentException $e) {
            $this->error('VAPID: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('APP_URL: '.config('app.url'));
        $this->line('LOG_LEVEL: '.config('logging.channels.single.level', config('logging.level', 'debug')));
        $this->line('Cooldown: '.config('notifications.fuel_push.cooldown_minutes', 45).' мин');

        $subs = PushSubscription::query()->count();
        $watches = PushSubscriptionWatch::query()->count();
        $this->line("Подписок push: {$subs}");
        $this->line("Watches (избранные): {$watches}");

        if ($watches === 0 && $subs > 0) {
            $this->warn('Watches пусто — клиент не синхронизировал ★ после деплоя.');
            $this->line('  → обновите сайт (Ctrl+Shift+R), снимите и снова поставьте ★ на АЗС');
        }

        PushSubscriptionWatch::query()
            ->with(['pushSubscription:id,endpoint', 'station:id,name,network'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->each(function (PushSubscriptionWatch $watch) {
                $station = $watch->station;
                $label = $station
                    ? "{$station->network} «{$station->name}» (#{$station->id})"
                    : "station #{$watch->station_id}";

                $this->line(sprintf(
                    '  watch #%d: %s · %s · marker=%s · notified=%s',
                    $watch->id,
                    $label,
                    $watch->fuel_type->value,
                    $watch->last_marker_color ?? '—',
                    $watch->last_notified_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? 'никогда',
                ));
            });

        $stationId = $this->option('station');

        if ($stationId !== null) {
            $station = Station::query()->find($stationId);

            if ($station === null) {
                $this->error("АЗС #{$stationId} не найдена");

                return self::FAILURE;
            }

            $fuel = $this->option('fuel');
            $reports = Report::query()
                ->visible()
                ->where('station_id', $station->id)
                ->orderByDesc('created_at')
                ->get();

            $fuelStatus = $statusService->fuelStatus(
                $station,
                \App\Enums\FuelType::from($fuel),
                $reports,
            );

            $watchCount = PushSubscriptionWatch::query()
                ->where('station_id', $station->id)
                ->where('fuel_type', $fuel)
                ->count();

            $this->newLine();
            $this->line("АЗС #{$station->id} {$station->network} «{$station->name}» · {$fuel}:");
            $this->line('  marker_color: '.$fuelStatus['marker_color']);
            $this->line('  status: '.$fuelStatus['status'].' ('.$fuelStatus['status_label'].')');
            $this->line('  freshness: '.$fuelStatus['freshness']);
            $this->line("  watches на эту АЗС: {$watchCount}");

            $fuelReports = $reports->filter(
                fn (Report $report) => $report->fuel_type->value === $fuel,
            );

            $this->line('  Последние отчёты по этому топливу:');
            if ($fuelReports->isEmpty()) {
                $this->line('    (нет)');
            }

            $fuelReports->take(3)->each(function (Report $report) {
                $this->line(sprintf(
                    '    #%d %s · %s · confirmation=%s',
                    $report->id,
                    $report->created_at?->format('Y-m-d H:i'),
                    $report->status->value,
                    $report->is_confirmation ? 'yes' : 'no',
                ));
            });

            if ($watchCount > 0 && $fuelStatus['marker_color'] !== 'green') {
                $this->info('  → Следующий отчёт «Есть» (не confirm) должен отправить push на '.$watchCount.' watch(ей).');
            } elseif ($watchCount > 0 && $fuelStatus['marker_color'] === 'green') {
                $this->warn('  → Сейчас уже green: push только после «Нет» и снова «Есть».');
            } else {
                $this->warn('  → Нет watch на эту АЗС/топливо — ★ не синхронизирован.');
            }

            $this->line('  Push уйдёт только при переходе marker → green (не confirm).');
        }

        $this->newLine();
        $this->line('Логи: grep -i "Fuel push" storage/logs/laravel.log | tail -20');
        $this->line('  Пусто = после деплоя не было новых отчётов (не confirm).');

        return self::SUCCESS;
    }
}
