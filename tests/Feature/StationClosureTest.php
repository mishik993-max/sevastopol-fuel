<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\StationClosureReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_station_hidden_from_list(): void
    {
        Station::query()->create([
            'name' => 'Открытая',
            'network' => 'ТЭС',
            'address' => 'ул. А 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
            'is_active' => true,
        ]);

        Station::query()->create([
            'name' => 'Закрытая',
            'network' => 'CRS',
            'address' => 'ул. Б 2',
            'latitude' => 44.61,
            'longitude' => 33.51,
            'is_active' => false,
            'closed_at' => now(),
            'closed_reason' => 'Тест',
        ]);

        $response = $this->getJson('/api/stations?fuel=a95')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('Открытая', $names);
        $this->assertNotContains('Закрытая', $names);
    }

    public function test_closure_reports_hide_station_after_threshold(): void
    {
        config(['stations.closure.reports_required' => 2]);

        $station = Station::query()->create([
            'name' => 'CRS',
            'network' => 'CRS',
            'address' => 'ул. Гидрографическая 2А',
            'latitude' => 44.58,
            'longitude' => 33.5,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson("/api/stations/{$station->id}/close")
            ->assertOk()
            ->assertJsonPath('data.deactivated', false);

        $this->withServerVariables(['REMOTE_ADDR' => '2.2.2.2'])
            ->postJson("/api/stations/{$station->id}/close")
            ->assertOk()
            ->assertJsonPath('data.deactivated', true);

        $station->refresh();
        $this->assertFalse($station->is_active);

        $this->getJson('/api/stations?fuel=a95')
            ->assertOk()
            ->assertJsonMissing(['name' => 'CRS']);
    }

    public function test_closure_count_has_no_time_window(): void
    {
        config(['stations.closure.reports_required' => 2]);

        $station = Station::query()->create([
            'name' => 'Старая пометка',
            'network' => 'ТЭС',
            'address' => 'ул. В 3',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        StationClosureReport::query()->create([
            'station_id' => $station->id,
            'reporter_hash' => hash('sha256', 'old'),
            'created_at' => now()->subDays(120),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '3.3.3.3'])
            ->postJson("/api/stations/{$station->id}/close")
            ->assertOk()
            ->assertJsonPath('data.deactivated', true);

        $this->assertFalse($station->fresh()->is_active);
    }

    public function test_station_includes_closure_report_count(): void
    {
        config(['stations.closure.reports_required' => 5]);

        $station = Station::query()->create([
            'name' => 'С счётчиком',
            'network' => 'Атан',
            'address' => 'ул. Г 4',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        StationClosureReport::query()->create([
            'station_id' => $station->id,
            'reporter_hash' => hash('sha256', 'a'),
        ]);
        StationClosureReport::query()->create([
            'station_id' => $station->id,
            'reporter_hash' => hash('sha256', 'b'),
        ]);

        $this->getJson("/api/stations/{$station->id}?fuel=a95")
            ->assertOk()
            ->assertJsonPath('data.closure_reports_count', 2)
            ->assertJsonPath('data.closure_reports_required', 5);
    }
}
