<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SevtechFuelSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.password' => 'test-admin-secret',
            'sevtech.base_url' => 'https://fuel.sevtech.org',
            'sevtech.stations_path' => '/map/a',
            'sevtech.enabled' => true,
            'sevtech.low_percent_threshold' => 25,
        ]);
    }

    public function test_sevtech_preview_parses_gas_stations_and_sync_creates_reports(): void
    {
        $station = Station::query()->create([
            'name' => 'Фиолентовское',
            'network' => 'ТЭС',
            'address' => 'Фиолентовское, 5а',
            'latitude' => 44.581116,
            'longitude' => 33.478819,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://fuel.sevtech.org/map/a' => Http::response([
                'gas_stations' => [[
                    'id' => 'gs21',
                    'uuid' => '8690d9bb-4cdd-4203-9e01-76b9c3b3d0b9',
                    'title' => 'Фиолентовское',
                    'address' => 'Фиолентовское, 5а',
                    'lat_lng' => ['lat' => 44.581116, 'lng' => 33.478819],
                    'a92' => 'FUEL_STATUS_AVAILABLE',
                    'a92_percent' => 75,
                    'a95' => 'FUEL_STATUS_AVAILABLE',
                    'a95_percent' => 40,
                    'a95_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'diesel' => 'FUEL_STATUS_AVAILABLE',
                    'diesel_percent' => 80,
                    'diesel_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'a100' => 'FUEL_STATUS_UNAVAILABLE',
                    'lpg' => 'FUEL_STATUS_UNAVAILABLE',
                    'last_inventory_at' => '2026-06-12T11:08:24.430959+03:00',
                ]],
            ], 200),
        ]);

        $preview = $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/sevtech/preview')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.station_id', $station->id)
            ->json('data');

        $this->assertTrue($preview['items'][0]['will_create']);

        $sync = $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/sevtech/sync', [
                'station_ids' => [$station->id],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $sync['created']);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a92',
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'dt',
            'status' => 'available',
        ]);
    }

    public function test_sevtech_maps_out_of_stock_and_low_percent(): void
    {
        $station = Station::query()->create([
            'name' => 'Гор.шоссе',
            'network' => 'ТЭС',
            'address' => 'Гор.шоссе, 15',
            'latitude' => 44.547508,
            'longitude' => 33.534253,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://fuel.sevtech.org/map/a' => Http::response([
                'gas_stations' => [[
                    'id' => 'gs5',
                    'uuid' => '38b8bdc5-5246-4c13-abce-c47128836221',
                    'title' => 'Гор.шоссе',
                    'address' => 'Гор.шоссе, 15',
                    'lat_lng' => ['lat' => 44.547508, 'lng' => 33.534253],
                    'a92' => 'FUEL_STATUS_AVAILABLE',
                    'a92_percent' => 75,
                    'a95' => 'FUEL_STATUS_OUT_OF_STOCK',
                    'a95_ultra' => 'FUEL_STATUS_AVAILABLE',
                    'a95_ultra_percent' => 10,
                    'diesel' => 'FUEL_STATUS_UNAVAILABLE',
                    'diesel_ultra' => 'FUEL_STATUS_AVAILABLE',
                    'diesel_ultra_percent' => 85,
                    'a100' => 'FUEL_STATUS_UNAVAILABLE',
                    'lpg' => 'FUEL_STATUS_UNAVAILABLE',
                ]],
            ], 200),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/sevtech/sync', [
                'station_ids' => [$station->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'status' => 'none',
        ]);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95_plus',
            'status' => 'low',
        ]);
    }

    public function test_sevtech_matches_station_by_coordinates_when_names_differ(): void
    {
        $station = Station::query()->create([
            'name' => 'ТЭС',
            'network' => 'ТЭС',
            'address' => 'ул. Индустриальная 10, Севастополь',
            'latitude' => 44.567306,
            'longitude' => 33.512421,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://fuel.sevtech.org/map/a' => Http::response([
                'gas_stations' => [[
                    'id' => 'gs7',
                    'uuid' => 'f88c5da0-746b-4c29-8e0c-0b04d5e79168',
                    'title' => 'Индустриальная',
                    'address' => 'Индустриальная, 10',
                    'lat_lng' => ['lat' => 44.567306, 'lng' => 33.512421],
                    'a92' => 'FUEL_STATUS_AVAILABLE',
                    'a92_percent' => 75,
                    'a95' => 'FUEL_STATUS_AVAILABLE',
                    'a95_percent' => 40,
                    'a95_ultra' => 'FUEL_STATUS_AVAILABLE',
                    'a95_ultra_percent' => 65,
                    'diesel' => 'FUEL_STATUS_AVAILABLE',
                    'diesel_percent' => 70,
                    'diesel_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'a100' => 'FUEL_STATUS_UNAVAILABLE',
                    'lpg' => 'FUEL_STATUS_UNAVAILABLE',
                ]],
            ], 200),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/sevtech/preview')
            ->assertOk()
            ->assertJsonPath('data.items.0.station_id', $station->id)
            ->assertJsonPath('data.items.0.match_type', 'coordinates');
    }

    public function test_sevtech_maps_a100_as_separate_fuel_type(): void
    {
        $station = Station::query()->create([
            'name' => 'Фиолентовское',
            'network' => 'ТЭС',
            'address' => 'Фиолентовское, 5а',
            'latitude' => 44.581116,
            'longitude' => 33.478819,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://fuel.sevtech.org/map/a' => Http::response([
                'gas_stations' => [[
                    'id' => 'gs21',
                    'uuid' => '8690d9bb-4cdd-4203-9e01-76b9c3b3d0b9',
                    'title' => 'Фиолентовское',
                    'address' => 'Фиолентовское, 5а',
                    'lat_lng' => ['lat' => 44.581116, 'lng' => 33.478819],
                    'a92' => 'FUEL_STATUS_UNAVAILABLE',
                    'a95' => 'FUEL_STATUS_UNAVAILABLE',
                    'a95_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'diesel' => 'FUEL_STATUS_UNAVAILABLE',
                    'diesel_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'a100' => 'FUEL_STATUS_AVAILABLE',
                    'a100_percent' => 55,
                    'lpg' => 'FUEL_STATUS_UNAVAILABLE',
                ]],
            ], 200),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/sevtech/sync', [
                'station_ids' => [$station->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a100',
            'status' => 'available',
        ]);
    }

    public function test_sevtech_rebind_updates_fuel_diff_for_manual_station(): void
    {
        $station = Station::query()->create([
            'name' => 'Индустриальная',
            'network' => 'ТЭС',
            'address' => 'Индустриальная, 10',
            'latitude' => 44.567306,
            'longitude' => 33.512421,
            'source' => 'manual',
            'is_active' => true,
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/sevtech/rebind', [
                'station_id' => $station->id,
                'fuels' => [
                    ['fuel_type' => 'a92', 'status' => 'available', 'sale_types' => ['qr']],
                    ['fuel_type' => 'a95', 'status' => 'available', 'sale_types' => ['qr']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.station_id', $station->id)
            ->assertJsonPath('data.will_create', true)
            ->assertJsonPath('data.fuels.0.changed', true);
    }

    public function test_sevtech_sync_skips_unchanged_status(): void
    {
        $station = Station::query()->create([
            'name' => 'Фиолентовское',
            'network' => 'ТЭС',
            'address' => 'Фиолентовское, 5а',
            'latitude' => 44.581116,
            'longitude' => 33.478819,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Report::query()->create([
            'station_id' => $station->id,
            'fuel_type' => 'a92',
            'status' => 'available',
            'statuses' => ['available'],
            'queue_size' => 'unknown',
            'sale_types' => ['qr'],
            'comment' => 'Импорт SevTech map (fuel.sevtech.org)',
            'is_confirmation' => false,
            'created_at' => now(),
        ]);

        Http::fake([
            'https://fuel.sevtech.org/map/a' => Http::response([
                'gas_stations' => [[
                    'id' => 'gs21',
                    'uuid' => '8690d9bb-4cdd-4203-9e01-76b9c3b3d0b9',
                    'title' => 'Фиолентовское',
                    'address' => 'Фиолентовское, 5а',
                    'lat_lng' => ['lat' => 44.581116, 'lng' => 33.478819],
                    'a92' => 'FUEL_STATUS_AVAILABLE',
                    'a92_percent' => 75,
                    'a95' => 'FUEL_STATUS_UNAVAILABLE',
                    'a95_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'diesel' => 'FUEL_STATUS_UNAVAILABLE',
                    'diesel_ultra' => 'FUEL_STATUS_UNAVAILABLE',
                    'a100' => 'FUEL_STATUS_UNAVAILABLE',
                    'lpg' => 'FUEL_STATUS_UNAVAILABLE',
                ]],
            ], 200),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/sevtech/sync', [
                'station_ids' => [$station->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 0);
    }
}
