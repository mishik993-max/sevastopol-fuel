<?php

namespace App\Services;

use App\Enums\CorrectionField;
use App\Enums\CorrectionStatus;
use App\Models\Station;
use App\Models\StationCorrection;
use App\Models\StationCorrectionReport;
use App\Support\AddressSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StationCorrectionService
{
    public function __construct(
        private StationClosureService $closureService,
        private AppSettingsService $appSettings,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function propose(Station $station, string $reporterHash, array $items): array
    {
        if (! $station->is_active) {
            throw ValidationException::withMessages([
                'station' => ['Нельзя исправить скрытую АЗС.'],
            ]);
        }

        $results = [];

        foreach ($items as $item) {
            $field = CorrectionField::from($item['field']);
            $results[] = $this->proposeField($station, $reporterHash, $field, $item);
        }

        return $results;
    }

    /** @return array{applied: bool, confirmations_count: int, confirmations_required: int, correction: array<string, mixed>} */
    public function confirm(StationCorrection $correction, string $reporterHash): array
    {
        if ($correction->status !== CorrectionStatus::Pending) {
            throw ValidationException::withMessages([
                'correction' => ['Это исправление уже неактуально.'],
            ]);
        }

        DB::transaction(function () use ($correction, $reporterHash) {
            StationCorrectionReport::query()->updateOrCreate(
                [
                    'correction_id' => $correction->id,
                    'reporter_hash' => $reporterHash,
                ],
                ['created_at' => now()],
            );
        });

        $correction->load('station');
        $count = $this->reportsCount($correction);
        $required = $this->confirmationsRequired();

        if ($count >= $required) {
            $this->apply($correction->fresh(['station']));

            return [
                'applied' => true,
                'confirmations_count' => $count,
                'confirmations_required' => $required,
                'correction' => $this->formatCorrection($correction->fresh(), $correction->station),
            ];
        }

        return [
            'applied' => false,
            'confirmations_count' => $count,
            'confirmations_required' => $required,
            'correction' => $this->formatCorrection($correction->fresh(), $correction->station),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function pendingForStation(Station $station): array
    {
        return StationCorrection::query()
            ->where('station_id', $station->id)
            ->where('status', CorrectionStatus::Pending)
            ->withCount('reports')
            ->orderBy('field')
            ->get()
            ->map(fn (StationCorrection $c) => $this->formatCorrection($c, $station))
            ->values()
            ->all();
    }

    public function confirmationsRequired(): int
    {
        return $this->appSettings->correctionConfirmationsRequired();
    }

    /** @return array<int, array<string, mixed>> */
    public function allPending(): array
    {
        return StationCorrection::query()
            ->where('status', CorrectionStatus::Pending)
            ->with(['station'])
            ->withCount('reports')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StationCorrection $c) => array_merge(
                $this->formatCorrection($c, $c->station),
                [
                    'station_id' => $c->station_id,
                    'station_network' => $c->station->network,
                    'station_name' => $c->station->name,
                    'created_at' => $c->created_at?->format('d.m.Y H:i'),
                ],
            ))
            ->values()
            ->all();
    }

    public function reject(StationCorrection $correction): void
    {
        if ($correction->status !== CorrectionStatus::Pending) {
            throw ValidationException::withMessages([
                'correction' => ['Исправление уже неактуально.'],
            ]);
        }

        $correction->update(['status' => CorrectionStatus::Superseded]);
    }

    public function forceApply(StationCorrection $correction): void
    {
        if ($correction->status !== CorrectionStatus::Pending) {
            throw ValidationException::withMessages([
                'correction' => ['Исправление уже неактуально.'],
            ]);
        }

        $correction->load('station');
        $this->apply($correction);
    }

    public function reporterHash(\Illuminate\Http\Request $request): string
    {
        return $this->closureService->reporterHash($request);
    }

    /** @param  array<string, mixed>  $item */
    private function proposeField(Station $station, string $reporterHash, CorrectionField $field, array $item): array
    {
        $this->validateChange($station, $field, $item);

        $correction = DB::transaction(function () use ($station, $reporterHash, $field, $item) {
            StationCorrection::query()
                ->where('station_id', $station->id)
                ->where('field', $field)
                ->where('status', CorrectionStatus::Pending)
                ->update(['status' => CorrectionStatus::Superseded]);

            $correction = StationCorrection::query()->create([
                'station_id' => $station->id,
                'field' => $field,
                'proposed_name' => $field === CorrectionField::Name ? trim($item['name']) : null,
                'proposed_address' => $field === CorrectionField::Address ? AddressSanitizer::clean($item['address']) : null,
                'proposed_latitude' => $field === CorrectionField::Location ? (float) $item['latitude'] : null,
                'proposed_longitude' => $field === CorrectionField::Location ? (float) $item['longitude'] : null,
                'status' => CorrectionStatus::Pending,
                'proposer_hash' => $reporterHash,
                'created_at' => now(),
            ]);

            StationCorrectionReport::query()->create([
                'correction_id' => $correction->id,
                'reporter_hash' => $reporterHash,
                'created_at' => now(),
            ]);

            return $correction;
        });

        $correction->loadCount('reports');

        return $this->formatCorrection($correction, $station);
    }

    /** @param  array<string, mixed>  $item */
    private function validateChange(Station $station, CorrectionField $field, array $item): void
    {
        match ($field) {
            CorrectionField::Name => $this->validateNameChange($station, $item),
            CorrectionField::Address => $this->validateAddressChange($station, $item),
            CorrectionField::Location => $this->validateLocationChange($station, $item),
        };
    }

    /** @param  array<string, mixed>  $item */
    private function validateNameChange(Station $station, array $item): void
    {
        $name = trim((string) ($item['name'] ?? ''));

        if ($name === '' || $name === $station->name) {
            throw ValidationException::withMessages([
                'name' => ['Укажите новое название, отличное от текущего.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $item */
    private function validateAddressChange(Station $station, array $item): void
    {
        $address = AddressSanitizer::clean((string) ($item['address'] ?? ''));

        if ($address === '' || $address === $station->address) {
            throw ValidationException::withMessages([
                'address' => ['Укажите новый адрес, отличный от текущего.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $item */
    private function validateLocationChange(Station $station, array $item): void
    {
        $lat = (float) ($item['latitude'] ?? 0);
        $lng = (float) ($item['longitude'] ?? 0);
        $bbox = $this->appSettings->geoBbox();

        if ($lat < $bbox['south'] || $lat > $bbox['north'] || $lng < $bbox['west'] || $lng > $bbox['east']) {
            throw ValidationException::withMessages([
                'latitude' => ['Точка должна быть в пределах Севастополя.'],
            ]);
        }

        if ($this->distanceM($lat, $lng, $station->latitude, $station->longitude) < 10) {
            throw ValidationException::withMessages([
                'latitude' => ['Новая точка слишком близко к текущей - сдвиньте маркер заметнее.'],
            ]);
        }

        $radius = $this->appSettings->duplicateRadiusM();
        $duplicate = Station::query()
            ->where('is_active', true)
            ->where('id', '!=', $station->id)
            ->get()
            ->first(fn (Station $s) => $this->distanceM($lat, $lng, $s->latitude, $s->longitude) < $radius);

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'latitude' => ["Рядом уже есть АЗС: {$duplicate->network} {$duplicate->name}"],
            ]);
        }
    }

    private function apply(StationCorrection $correction): void
    {
        DB::transaction(function () use ($correction) {
            $station = $correction->station;

            match ($correction->field) {
                CorrectionField::Name => $station->update(['name' => $correction->proposed_name]),
                CorrectionField::Address => $station->update(['address' => $correction->proposed_address]),
                CorrectionField::Location => $station->update([
                    'latitude' => $correction->proposed_latitude,
                    'longitude' => $correction->proposed_longitude,
                ]),
            };

            $correction->update([
                'status' => CorrectionStatus::Applied,
                'applied_at' => now(),
            ]);
        });
    }

    private function reportsCount(StationCorrection $correction): int
    {
        return $correction->reports_count
            ?? StationCorrectionReport::query()->where('correction_id', $correction->id)->count();
    }

    /** @return array<string, mixed> */
    public function formatCorrection(StationCorrection $correction, Station $station): array
    {
        $field = $correction->field;

        return [
            'id' => $correction->id,
            'field' => $field->value,
            'field_label' => $field->label(),
            'current_value' => $this->currentValue($station, $field),
            'proposed_value' => $this->proposedValue($correction, $field),
            'confirmations_count' => $this->reportsCount($correction),
            'confirmations_required' => $this->confirmationsRequired(),
        ];
    }

    private function currentValue(Station $station, CorrectionField $field): string
    {
        return match ($field) {
            CorrectionField::Name => $station->name,
            CorrectionField::Address => $station->address,
            CorrectionField::Location => sprintf('%.5f, %.5f', $station->latitude, $station->longitude),
        };
    }

    private function proposedValue(StationCorrection $correction, CorrectionField $field): string
    {
        return match ($field) {
            CorrectionField::Name => (string) $correction->proposed_name,
            CorrectionField::Address => (string) $correction->proposed_address,
            CorrectionField::Location => sprintf('%.5f, %.5f', $correction->proposed_latitude, $correction->proposed_longitude),
        };
    }

    private function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
