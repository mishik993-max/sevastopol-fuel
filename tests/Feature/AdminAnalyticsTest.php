<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
    }

    public function test_visit_is_recorded_once_per_visitor_per_day(): void
    {
        $visitorId = (string) Str::uuid();

        $this->postJson('/api/visit', ['visitor_id' => $visitorId])->assertOk();
        $this->postJson('/api/visit', ['visitor_id' => $visitorId])->assertOk();

        $today = Carbon::now(config('app.timezone'))->toDateString();

        $this->assertDatabaseHas('visitor_daily_stats', [
            'date' => $today,
            'unique_visitors' => 1,
            'total_visits' => 2,
        ]);
    }

    public function test_admin_analytics_returns_visitors_only(): void
    {
        $today = Carbon::now(config('app.timezone'))->toDateString();

        DB::table('visitor_daily_stats')->insert([
            'date' => $today,
            'unique_visitors' => 5,
            'total_visits' => 8,
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/analytics')
            ->assertOk()
            ->assertJsonPath('data.visitors_today', 5)
            ->assertJsonStructure([
                'data' => [
                    'visitors_today',
                    'visitors_yesterday',
                    'visitors_daily',
                ],
            ]);

        $this->assertArrayNotHasKey('system', $response->json('data'));
    }

    public function test_admin_system_returns_server_metrics(): void
    {
        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/system')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'memory_used_mb',
                    'memory_peak_mb',
                    'queue_pending',
                    'cache_driver',
                    'cache_driver_label',
                    'queue_driver',
                    'queue_driver_label',
                    'database_connection',
                    'database_connection_label',
                ],
            ]);
    }

    public function test_admin_summary_includes_visitor_counts(): void
    {
        $today = Carbon::now(config('app.timezone'))->toDateString();

        DB::table('visitor_daily_stats')->insert([
            'date' => $today,
            'unique_visitors' => 3,
            'total_visits' => 4,
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('data.visitors_today', 3);
    }
}
