<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_rejects_file_over_max_size(): void
    {
        Storage::fake('public');

        config(['reports.photo_max_kb' => 5120]);

        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->post('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['available'],
            'queue_size' => 'none',
            'sale_types' => ['regular'],
            'photo' => UploadedFile::fake()->image('big.jpg')->size(6000),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_photo_accepts_file_within_max_size(): void
    {
        Storage::fake('public');

        config(['reports.photo_max_kb' => 5120]);

        $station = Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->post('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['available'],
            'queue_size' => 'up_to_10',
            'sale_types' => ['regular'],
            'photo' => UploadedFile::fake()->image('ok.jpg')->size(4000),
        ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertNotNull(Report::query()->first()?->photo_path);
    }
}
