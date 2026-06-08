<?php

namespace Tests\Feature;

use App\Models\FeedbackMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.password' => 'test-admin-secret']);
    }

    public function test_user_can_submit_feedback(): void
    {
        $this->postJson('/api/feedback', [
            'type' => 'suggestion',
            'message' => 'Добавьте фильтр по расстоянию в списке',
            'contact' => '@user',
        ])->assertCreated()
            ->assertJsonPath('message', 'Спасибо! Сообщение отправлено.');

        $this->assertDatabaseCount('feedback_messages', 1);
        $this->assertSame('new', FeedbackMessage::query()->first()->status->value);
    }

    public function test_feedback_requires_minimum_message_length(): void
    {
        $this->postJson('/api/feedback', [
            'type' => 'feedback',
            'message' => 'коротко',
        ])->assertUnprocessable();
    }

    public function test_admin_can_list_and_update_feedback(): void
    {
        $message = FeedbackMessage::query()->create([
            'type' => 'feedback',
            'message' => 'Кнопка не работает на iPhone',
            'status' => 'new',
            'created_at' => now(),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/feedback')
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.status', 'new');

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->patchJson("/api/admin/feedback/{$message->id}", [
                'status' => 'done',
                'admin_note' => 'Проверить Safari',
            ])
            ->assertOk();

        $message->refresh();
        $this->assertSame('done', $message->status->value);
        $this->assertSame('Проверить Safari', $message->admin_note);
    }

    public function test_admin_summary_includes_feedback_count(): void
    {
        FeedbackMessage::query()->create([
            'type' => 'suggestion',
            'message' => 'Идея для улучшения карты',
            'status' => 'new',
            'created_at' => now(),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('data.new_feedback', 1);
    }
}
