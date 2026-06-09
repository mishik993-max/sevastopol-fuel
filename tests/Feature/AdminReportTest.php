<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Station;
use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
        Storage::fake('public');
    }

    public function test_admin_can_hide_report_and_it_disappears_from_station_status(): void
    {
        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $report = Report::query()->create([
            'station_id' => $station->id,
            'fuel_type' => FuelType::A95,
            'status' => FuelStatus::Available,
            'statuses' => ['available'],
            'queue_size' => QueueSize::None,
            'sale_types' => ['voucher'],
            'comment' => 'Спам',
            'created_at' => now(),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson("/api/admin/reports/{$report->id}/hide")
            ->assertOk()
            ->assertJsonPath('message', 'Отчёт скрыт с карты');

        $this->assertTrue($report->fresh()->is_hidden);

        $this->getJson('/api/stations?fuel=a95')
            ->assertOk()
            ->assertJsonPath('data.0.fuels.0.status', 'unknown');
    }

    public function test_admin_can_delete_report(): void
    {
        Storage::disk('public')->put('reports/to-delete.jpg', 'fake');

        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $report = Report::query()->create([
            'station_id' => $station->id,
            'fuel_type' => FuelType::A95,
            'status' => FuelStatus::Available,
            'statuses' => ['available'],
            'queue_size' => QueueSize::None,
            'sale_types' => ['voucher'],
            'photo_path' => 'reports/to-delete.jpg',
            'created_at' => now(),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->deleteJson("/api/admin/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Отчёт удалён');

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
        Storage::disk('public')->assertMissing('reports/to-delete.jpg');
    }

    public function test_station_detail_includes_photo_url(): void
    {
        Storage::disk('public')->put('reports/test.jpg', 'fake');

        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        Report::query()->create([
            'station_id' => $station->id,
            'fuel_type' => FuelType::A95,
            'status' => FuelStatus::Available,
            'statuses' => ['available'],
            'queue_size' => QueueSize::None,
            'sale_types' => [],
            'photo_path' => 'reports/test.jpg',
            'created_at' => now(),
        ]);

        $this->getJson("/api/stations/{$station->id}?fuel=a95")
            ->assertOk()
            ->assertJsonPath('data.last_photo_url', Storage::disk('public')->url('reports/test.jpg'));
    }
}
