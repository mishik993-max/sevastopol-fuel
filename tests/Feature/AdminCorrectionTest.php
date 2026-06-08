<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\StationCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
    }

    public function test_admin_can_apply_correction(): void
    {
        $station = Station::query()->create([
            'name' => 'Старое',
            'network' => 'Атан',
            'address' => 'ул. 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $correction = StationCorrection::query()->create([
            'station_id' => $station->id,
            'field' => 'name',
            'proposed_name' => 'Новое',
            'status' => 'pending',
            'proposer_hash' => hash('sha256', 'x'),
            'created_at' => now(),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson("/api/admin/corrections/{$correction->id}/apply")
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertSame('Новое', $station->fresh()->name);
    }

    public function test_admin_rejects_without_token(): void
    {
        $station = Station::query()->create([
            'name' => 'А',
            'network' => 'Атан',
            'address' => 'ул. 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $correction = StationCorrection::query()->create([
            'station_id' => $station->id,
            'field' => 'name',
            'proposed_name' => 'Б',
            'status' => 'pending',
            'proposer_hash' => hash('sha256', 'x'),
            'created_at' => now(),
        ]);

        $this->postJson("/api/admin/corrections/{$correction->id}/reject")
            ->assertUnauthorized();
    }
}
