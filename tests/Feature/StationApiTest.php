<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stations_list_returns_json(): void
    {
        Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->getJson('/api/stations?fuel=a95')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'marker_color', 'fuels']]]);
    }

    public function test_report_and_confirm_flow(): void
    {
        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'status' => 'available',
            'queue_size' => 'none',
            'statuses' => ['available', 'low'],
            'sale_types' => ['voucher', 'qr'],
            'fill_volume' => 'liters_20',
            'canister_policy' => 'allowed',
        ])->assertCreated()
            ->assertJsonPath('data.sale_types', ['voucher', 'qr'])
            ->assertJsonPath('data.status_labels', ['Есть', 'Мало'])
            ->assertJsonPath('data.fill_volume_label', '20 литров')
            ->assertJsonPath('data.canister_policy', 'allowed')
            ->assertJsonPath('data.canister_policy_label', 'Можно в канистру');

        $this->postJson("/api/stations/{$station->id}/confirm", [
            'fuel_type' => 'a95',
        ])->assertOk();

        $this->postJson("/api/stations/{$station->id}/confirm", [
            'fuel_type' => 'a95',
        ])->assertStatus(422)
            ->assertJsonPath('errors.fuel_type.0', 'Вы уже подтверждали этот отчёт.');

        $this->assertEquals(2, Report::query()->count());
        $this->assertTrue(Report::query()->latest('id')->first()->is_confirmation);
    }

    public function test_report_accepts_unknown_queue_size(): void
    {
        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['available'],
            'queue_size' => 'unknown',
            'sale_types' => ['regular'],
        ])->assertCreated()
            ->assertJsonPath('data.queue_size', 'unknown')
            ->assertJsonPath('data.queue_label', 'Не знаю');
    }

    public function test_station_queue_size_follows_selected_fuel(): void
    {
        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['available'],
            'queue_size' => '30_plus',
            'sale_types' => ['regular'],
        ])->assertCreated();

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'dt',
            'statuses' => ['available'],
            'queue_size' => 'none',
            'sale_types' => ['regular'],
        ])->assertCreated();

        $this->getJson('/api/stations?fuel=dt')
            ->assertOk()
            ->assertJsonPath('data.0.queue_size', 'none');

        $this->getJson('/api/stations?fuel=a95')
            ->assertOk()
            ->assertJsonPath('data.0.queue_size', '30_plus');
    }
}
