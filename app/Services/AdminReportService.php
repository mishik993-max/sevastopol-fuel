<?php

namespace App\Services;

use App\Enums\FuelStatus;
use App\Enums\SaleType;
use App\Models\Report;
use Illuminate\Support\Collection;

class AdminReportService
{
    /** @return list<array<string, mixed>> */
    public function list(int $limit = 80): array
    {
        return Report::query()
            ->with('station:id,name,network')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Report $report) => $this->format($report))
            ->all();
    }

    public function hide(Report $report): void
    {
        $report->update(['is_hidden' => true]);
    }

    public function unhide(Report $report): void
    {
        $report->update(['is_hidden' => false]);
    }

    public function visibleCount(): int
    {
        return Report::query()->visible()->count();
    }

    public function hiddenCount(): int
    {
        return Report::query()->where('is_hidden', true)->count();
    }

    /** @return array<string, mixed> */
    private function format(Report $report): array
    {
        return [
            'id' => $report->id,
            'station_id' => $report->station_id,
            'station_name' => $report->station?->name,
            'station_network' => $report->station?->network,
            'fuel_type' => $report->fuel_type->value,
            'fuel_label' => $report->fuel_type->label(),
            'status_label' => FuelStatus::labelsFor($report->statuses)
                ? implode(' · ', FuelStatus::labelsFor($report->statuses))
                : $report->status->label(),
            'sale_type_labels' => SaleType::labelsFor($report->sale_types),
            'comment' => $report->comment,
            'photo_url' => $report->photoUrl(),
            'is_confirmation' => $report->is_confirmation,
            'is_hidden' => $report->is_hidden,
            'created_at' => $report->created_at?->format('d.m.Y H:i'),
        ];
    }
}
