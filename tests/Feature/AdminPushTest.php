<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
    }

    public function test_push_send_requires_admin_token(): void
    {
        $this->postJson('/api/admin/push/send', [
            'title' => 'Test',
            'body' => 'Body',
        ])->assertUnauthorized();
    }

    public function test_push_send_requires_subscriptions(): void
    {
        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/push/send', [
                'title' => 'Test',
                'body' => 'Body',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Нет подписок на push. Пользователи должны включить уведомления на сайте.');
    }

    public function test_admin_can_send_push(): void
    {
        PushSubscription::query()->create([
            'endpoint' => 'https://push.example/sub/1',
            'public_key' => 'BKxQzExamplePublicKeyForTestsOnly1234567890ABCDEF',
            'auth_token' => 'authTokenExample12',
        ]);

        $this->mock(WebPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('broadcast')
                ->once()
                ->with('QR доступен', 'Перейдите в чат', 'https://t.me/example')
                ->andReturn(1);
        });

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/push/send', [
                'title' => 'QR доступен',
                'body' => 'Перейдите в чат',
                'url' => 'https://t.me/example',
            ])
            ->assertOk()
            ->assertJsonPath('data.delivered', 1)
            ->assertJsonPath('data.total', 1);
    }

    public function test_push_status_returns_subscription_count(): void
    {
        PushSubscription::query()->create([
            'endpoint' => 'https://push.example/sub/2',
            'public_key' => 'BKxQzExamplePublicKeyForTestsOnly1234567890ABCDEF',
            'auth_token' => 'authTokenExample12',
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/push/status')
            ->assertOk()
            ->assertJsonPath('data.subscriptions', 1);
    }
}
