<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
    }

    public function test_public_settings_returns_defaults(): void
    {
        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.geo_bbox.south', 44.48)
            ->assertJsonPath('data.closure_reports_required', 5)
            ->assertJsonStructure(['data' => ['qr_reminders']]);
    }

    public function test_admin_can_update_settings(): void
    {
        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->patchJson('/api/admin/settings', [
                'closure_reports_required' => 3,
                'freshness_fresh_minutes' => 20,
                'network_priority' => ['Атан', 'WOG'],
            ])
            ->assertOk()
            ->assertJsonPath('data.closure_reports_required', 3)
            ->assertJsonPath('data.freshness_fresh_minutes', 20);

        $this->getJson('/api/settings')
            ->assertJsonPath('data.closure_reports_required', 3);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'closure_reports_required',
        ]);
    }

    public function test_updated_closure_threshold_applies_to_api(): void
    {
        AppSetting::query()->create([
            'key' => 'closure_reports_required',
            'value' => 2,
            'updated_at' => now(),
        ]);

        $station = \App\Models\Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);

        $this->getJson('/api/stations/'.$station->id)
            ->assertJsonPath('data.closure_reports_required', 2);
    }
}
