<?php

namespace App\Services;

use App\Models\Station;
use App\Support\AddressSanitizer;
use Illuminate\Validation\ValidationException;

class UserStationService
{
    public function __construct(private AppSettingsService $appSettings) {}

    public function create(array $data): Station
    {
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $radius = $this->appSettings->duplicateRadiusM();

        $duplicate = Station::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Station $s) => $this->distanceM($lat, $lng, $s->latitude, $s->longitude) < $radius);

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'latitude' => ["Рядом уже есть АЗС: {$duplicate->network} {$duplicate->name}"],
            ]);
        }

        $network = trim($data['network']);
        $name = trim($data['name'] ?? '') ?: $network;

        return Station::query()->create([
            'source' => 'user',
            'external_id' => null,
            'name' => $name,
            'network' => $network,
            'address' => AddressSanitizer::clean($data['address']),
            'latitude' => $lat,
            'longitude' => $lng,
            'is_active' => true,
        ]);
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
