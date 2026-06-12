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
            'sevtech.stations_path' => '/map/api/stations',
            'sevtech.enabled' => true,
        ]);
    }

    public function test_sevtech_preview_matches_station_and_sync_creates_reports(): void
    {
        $station = Station::query()->create([
            'name' => 'ТЭС №1',
            'network' => 'ТЭС',
            'address' => 'Фиолентовское шоссе 5',
            'latitude' => 44.58,
            'longitude' => 33.48,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://fuel.sevtech.org/map/api/stations' => Http::response([
                'data' => [[
                    'id' => 101,
                    'name' => 'ТЭС Фиолент',
                    'address' => 'Фиолентовское шоссе 5',
                    'lat' => 44.58,
                    'lng' => 33.48,
                    'a92' => true,
                    'a95' => false,
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

        $this->assertSame(2, $sync['created']);
        $this->assertDatabaseCount('reports', 2);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a92',
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'status' => 'none',
        ]);
    }

    public function test_sevtech_sync_skips_unchanged_status(): void
    {
        $station = Station::query()->create([
            'name' => 'ТЭС №1',
            'network' => 'ТЭС',
            'address' => 'Фиолентовское шоссе 5',
            'latitude' => 44.58,
            'longitude' => 33.48,
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
            'https://fuel.sevtech.org/map/api/stations' => Http::response([
                ['id' => 1, 'name' => 'ТЭС Фиолент', 'address' => 'Фиолентовское шоссе 5', 'ai92' => true],
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
