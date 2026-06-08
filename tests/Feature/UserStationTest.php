<?php

namespace Tests\Feature;

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'network' => 'Атан',
            'name' => 'АЗС тест',
            'address' => 'ул. Тестовая, 1',
            'latitude' => 44.605,
            'longitude' => 33.522,
        ], $overrides);
    }

    public function test_user_can_add_station(): void
    {
        $this->postJson('/api/stations?fuel=a95', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.network', 'Атан')
            ->assertJsonStructure(['data' => ['id', 'name', 'marker_color', 'fuels']]);

        $this->assertDatabaseHas('stations', [
            'network' => 'Атан',
            'source' => 'user',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_station_is_rejected(): void
    {
        Station::query()->create([
            'name' => 'Существующая',
            'network' => 'ТЭС',
            'address' => 'ул. Рядом, 1',
            'latitude' => 44.605,
            'longitude' => 33.522,
            'is_active' => true,
        ]);

        $this->postJson('/api/stations?fuel=a95', $this->validPayload([
            'network' => 'Атан',
            'latitude' => 44.60501,
            'longitude' => 33.52201,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
    }

    public function test_station_outside_bbox_is_rejected(): void
    {
        $this->postJson('/api/stations?fuel=a95', $this->validPayload([
            'latitude' => 45.0,
            'longitude' => 33.5,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
    }
}
