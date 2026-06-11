<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
    }

    public function test_public_faq_returns_only_published_items_in_order(): void
    {
        FaqItem::query()->create([
            'question' => 'Скрытый вопрос',
            'answer' => 'Скрытый ответ для проверки фильтрации',
            'sort_order' => 5,
            'is_published' => false,
        ]);

        $visible = FaqItem::query()->create([
            'question' => 'Сколько литров можно везти?',
            'answer' => 'Не более 100 литров жидкого топлива через мост.',
            'sort_order' => 10,
            'is_published' => true,
        ]);

        $this->getJson('/api/faq')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.question', 'Сколько литров можно везти?');
    }

    public function test_admin_can_create_update_reorder_and_delete_faq(): void
    {
        $first = FaqItem::query()->create([
            'question' => 'Первый вопрос для админки',
            'answer' => 'Первый ответ для админки с достаточной длиной',
            'sort_order' => 10,
            'is_published' => true,
        ]);

        $second = FaqItem::query()->create([
            'question' => 'Второй вопрос для админки',
            'answer' => 'Второй ответ для админки с достаточной длиной',
            'sort_order' => 20,
            'is_published' => true,
        ]);

        $headers = ['X-Admin-Token' => 'test-admin-secret'];

        $this->withHeaders($headers)
            ->postJson('/api/admin/faq', [
                'question' => 'Новый вопрос про QR-коды',
                'answer' => 'QR выдают по расписанию в официальном чате города.',
                'is_published' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.2.question', 'Новый вопрос про QR-коды');

        $created = FaqItem::query()->where('question', 'Новый вопрос про QR-коды')->first();
        $this->assertNotNull($created);
        $this->assertFalse($created->is_published);

        $this->withHeaders($headers)
            ->patchJson("/api/admin/faq/{$created->id}", [
                'question' => 'Обновлённый вопрос про QR',
                'is_published' => true,
            ])
            ->assertOk();

        $created->refresh();
        $this->assertSame('Обновлённый вопрос про QR', $created->question);
        $this->assertTrue($created->is_published);

        $this->withHeaders($headers)
            ->patchJson('/api/admin/faq/reorder', [
                'ids' => [$second->id, $first->id, $created->id],
            ])
            ->assertOk();

        $this->assertSame(10, $second->fresh()->sort_order);
        $this->assertSame(20, $first->fresh()->sort_order);
        $this->assertSame(30, $created->fresh()->sort_order);

        $this->withHeaders($headers)
            ->deleteJson("/api/admin/faq/{$first->id}")
            ->assertOk();

        $this->assertDatabaseMissing('faq_items', ['id' => $first->id]);
    }

    public function test_faq_validation_requires_meaningful_text(): void
    {
        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/faq', [
                'question' => 'Мало',
                'answer' => 'коротко',
            ])
            ->assertUnprocessable();
    }
}
