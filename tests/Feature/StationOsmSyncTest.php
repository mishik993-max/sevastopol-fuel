<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Services\StationOsmSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StationOsmSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_deactivates_station_marked_disused_in_osm(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/details.php*' => Http::response([
                'type' => 'fuel',
                'localname' => 'CRS',
                'extratags' => [
                    'disused:amenity' => 'fuel',
                    'brand' => 'CRS',
                ],
            ]),
        ]);

        $station = Station::query()->create([
            'external_id' => 'way/93366745',
            'source' => 'osm',
            'name' => 'CRS',
            'network' => 'CRS',
            'address' => 'ул. Гидрографическая 2А',
            'latitude' => 44.58,
            'longitude' => 33.5,
            'is_active' => true,
        ]);

        $result = app(StationOsmSyncService::class)->syncAll();

        $station->refresh();

        $this->assertSame(1, $result['deactivated']);
        $this->assertFalse($station->is_active);
        $this->assertStringStartsWith('OSM:', $station->closed_reason);
    }

    public function test_sync_does_not_override_manual_closure(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/details.php*' => Http::response([
                'extratags' => ['amenity' => 'fuel', 'brand' => 'CRS'],
            ]),
        ]);

        $station = Station::query()->create([
            'external_id' => 'way/93366745',
            'source' => 'osm',
            'name' => 'CRS',
            'network' => 'CRS',
            'address' => 'ул. Гидрографическая 2А',
            'latitude' => 44.58,
            'longitude' => 33.5,
            'is_active' => false,
            'closed_reason' => 'CRS закрыта',
        ]);

        $result = app(StationOsmSyncService::class)->syncAll();

        $station->refresh();

        $this->assertSame(0, $result['checked']);
        $this->assertFalse($station->is_active);
        $this->assertSame('CRS закрыта', $station->closed_reason);
    }
}
