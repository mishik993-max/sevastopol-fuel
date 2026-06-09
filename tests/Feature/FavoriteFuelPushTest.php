<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\PushSubscriptionWatch;
use App\Models\Report;
use App\Models\Station;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class FavoriteFuelPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example/sub/fuel-test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sevazs.ru']);
    }

    public function test_fuel_available_on_watched_station_sends_push(): void
    {
        $station = $this->createStation();
        $subscription = $this->createSubscription();

        PushSubscriptionWatch::query()->create([
            'push_subscription_id' => $subscription->id,
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'notify_available' => true,
            'last_marker_color' => 'red',
        ]);

        $this->mock(WebPushService::class, function (MockInterface $mock) use ($station): void {
            $mock->shouldReceive('sendTo')
                ->once()
                ->withArgs(function ($subs, $title, $body, $url, $tag) use ($station) {
                    return $body === 'На WOG «Тест» появился А-95'
                        && $url === 'https://sevazs.ru/?station='.$station->id.'&fuel=a95'
                        && $tag === 'sevazs-fuel-'.$station->id;
                })
                ->andReturn(1);
        });

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['none'],
            'queue_size' => 'none',
            'sale_types' => ['regular'],
        ])->assertCreated();

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['available'],
            'queue_size' => 'none',
            'sale_types' => ['regular'],
        ])->assertCreated();
    }

    public function test_confirmation_report_does_not_send_fuel_push(): void
    {
        $station = $this->createStation();
        $subscription = $this->createSubscription();

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'statuses' => ['available'],
            'queue_size' => 'none',
            'sale_types' => ['regular'],
        ])->assertCreated();

        PushSubscriptionWatch::query()->create([
            'push_subscription_id' => $subscription->id,
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'notify_available' => true,
            'last_marker_color' => 'green',
        ]);

        $this->mock(WebPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendTo')->never();
        });

        $this->postJson("/api/stations/{$station->id}/confirm", [
            'fuel_type' => 'a95',
        ])->assertOk();
    }

    public function test_push_watches_sync_creates_watches_with_current_marker_color(): void
    {
        $station = $this->createStation();
        $subscription = $this->createSubscription();

        $this->postJson('/api/reports', [
            'station_id' => $station->id,
            'fuel_type' => 'dt',
            'statuses' => ['available'],
            'queue_size' => 'none',
            'sale_types' => ['regular'],
        ])->assertCreated();

        $this->putJson('/api/push/watches', [
            'endpoint' => self::ENDPOINT,
            'client_id' => '550e8400-e29b-41d4-a716-446655440000',
            'station_ids' => [$station->id],
            'fuel_type' => 'dt',
        ])
            ->assertOk()
            ->assertJsonPath('watches', 1);

        $watch = PushSubscriptionWatch::query()->first();

        $this->assertSame('green', $watch->last_marker_color);
        $this->assertSame('dt', $watch->fuel_type->value);
    }

    public function test_push_watches_sync_requires_existing_subscription(): void
    {
        $this->putJson('/api/push/watches', [
            'endpoint' => self::ENDPOINT,
            'station_ids' => [1],
            'fuel_type' => 'a95',
        ])->assertNotFound();
    }

    private function createStation(): Station
    {
        return Station::query()->create([
            'name' => 'Тест',
            'network' => 'WOG',
            'address' => 'ул. Тест 1',
            'latitude' => 44.6,
            'longitude' => 33.5,
        ]);
    }

    private function createSubscription(): PushSubscription
    {
        return PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'public_key' => 'BKxQzExamplePublicKeyForTestsOnly1234567890ABCDEF',
            'auth_token' => 'authTokenExample12',
        ]);
    }
}
