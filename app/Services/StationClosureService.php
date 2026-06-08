<?php

namespace App\Services;

use App\Models\Station;
use App\Models\StationClosureReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StationClosureService
{
    public function __construct(private AppSettingsService $appSettings) {}

    public function reporterHash(Request $request): string
    {
        return hash('sha256', $request->ip().'|'.($request->userAgent() ?? ''));
    }

    /** @return array{deactivated: bool, reports_count: int, reports_required: int} */
    public function reportClosure(Station $station, string $reporterHash, ?string $comment = null): array
    {
        if (! $station->is_active) {
            return [
                'deactivated' => true,
                'reports_count' => 0,
                'reports_required' => $this->reportsRequired(),
            ];
        }

        DB::transaction(function () use ($station, $reporterHash, $comment) {
            StationClosureReport::query()->updateOrCreate(
                [
                    'station_id' => $station->id,
                    'reporter_hash' => $reporterHash,
                ],
                [
                    'comment' => $comment,
                    'created_at' => now(),
                ],
            );
        });

        $reportsCount = $this->reportsCount($station);
        $required = $this->reportsRequired();

        if ($reportsCount >= $required) {
            $this->deactivate($station, 'Закрыта по сообщениям пользователей');

            return [
                'deactivated' => true,
                'reports_count' => $reportsCount,
                'reports_required' => $required,
            ];
        }

        return [
            'deactivated' => false,
            'reports_count' => $reportsCount,
            'reports_required' => $required,
        ];
    }

    public function deactivate(Station $station, ?string $reason = null): void
    {
        if (! $station->is_active) {
            return;
        }

        $station->update([
            'is_active' => false,
            'closed_at' => now(),
            'closed_reason' => $reason ?? 'Закрыта вручную',
        ]);
    }

    public function reactivate(Station $station): void
    {
        $station->update([
            'is_active' => true,
            'closed_at' => null,
            'closed_reason' => null,
        ]);

        StationClosureReport::query()
            ->where('station_id', $station->id)
            ->delete();
    }

    public function reportsCount(Station $station): int
    {
        return StationClosureReport::query()
            ->where('station_id', $station->id)
            ->count();
    }

    public function reportsRequired(): int
    {
        return $this->appSettings->closureReportsRequired();
    }
}
