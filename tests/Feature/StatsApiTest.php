<?php

namespace Tests\Feature;

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_returns_summary(): void
    {
        Station::query()->create([
            'name' => 'Тест 1',
            'network' => 'Атан',
            'address' => 'ул. А 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->getJson('/api/stats?fuel=a95')
            ->assertOk()
            ->assertJsonPath('data.stations_total', 1)
            ->assertJsonPath('data.fuel', 'a95')
            ->assertJsonStructure([
                'data' => [
                    'networks',
                    'status_counts',
                    'marker_counts',
                    'fuels_overview',
                ],
            ]);
    }
}
