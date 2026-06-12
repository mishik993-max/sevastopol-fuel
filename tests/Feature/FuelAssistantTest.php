<?php

namespace Tests\Feature;

use App\Models\FuelAssistantSession;
use App\Models\Report;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuelAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = '550e8400-e29b-41d4-a716-446655440001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.api_key' => 'test-key',
            'ai.base_uri' => 'https://api.timeweb.ai/v1',
            'ai.model' => 'gemini/gemini-2.5-flash-lite',
        ]);
    }

    public function test_fuel_assistant_dialogue_confirm_and_starts_new_session_after_complete(): void
    {
        $station = Station::query()->create([
            'name' => 'АТАН Семипалатинская',
            'network' => 'Атан',
            'address' => 'проспект Победы, Семипалатинская',
            'latitude' => 44.59,
            'longitude' => 33.52,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::sequence()
                ->push($this->aiResponse([
                    'status' => 'draft',
                    'reply' => 'Похоже, на Атан Семипалатинская большая очередь и есть 95-й. Всё верно?',
                    'draft' => [
                        'network' => 'Атан',
                        'name_hint' => 'АЗС Семипалатинская',
                        'address_hint' => 'проспект Победы',
                        'queue_size' => '30_plus',
                        'sale_types' => ['regular'],
                        'fuels' => [
                            ['fuel_type' => 'a95', 'status' => 'available'],
                        ],
                    ],
                ]))
                ->push($this->aiResponse([
                    'status' => 'collecting',
                    'reply' => 'Что нужно исправить?',
                    'draft' => null,
                ])),
        ]);

        $message = $this->postJson('/api/fuel-assistant/message', [
            'client_id' => self::CLIENT_ID,
            'message' => 'Очередь на семипалатенскую атан с победы с самого верха. Стою уже 2 часа',
            'latitude' => 44.59,
            'longitude' => 33.52,
        ])->assertOk();

        $message->assertJsonPath('data.has_preview', true)
            ->assertJsonPath('data.preview.station_id', $station->id);

        $this->postJson('/api/fuel-assistant/confirm', [
            'client_id' => self::CLIENT_ID,
            'station_id' => $station->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('reports', [
            'station_id' => $station->id,
            'fuel_type' => 'a95',
            'queue_size' => '30_plus',
        ]);

        $this->assertDatabaseHas('fuel_assistant_sessions', [
            'client_id' => self::CLIENT_ID,
            'status' => 'completed',
        ]);

        $this->getJson('/api/fuel-assistant/session?client_id='.self::CLIENT_ID)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_fuel_assistant_reject_clears_preview_and_continues_session(): void
    {
        FuelAssistantSession::query()->create([
            'client_id' => self::CLIENT_ID,
            'status' => 'active',
            'messages' => [
                ['role' => 'user', 'content' => 'test', 'at' => now()->toIso8601String()],
            ],
            'draft' => ['name_hint' => 'test'],
            'preview' => ['fuels' => [['fuel_type' => 'a95']]],
        ]);

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'status' => 'collecting',
                            'reply' => 'Что исправить?',
                            'draft' => null,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/fuel-assistant/reject', [
            'client_id' => self::CLIENT_ID,
        ])
            ->assertOk()
            ->assertJsonPath('data.preview', null)
            ->assertJsonPath('data.has_preview', false);

        $this->assertDatabaseHas('fuel_assistant_sessions', [
            'client_id' => self::CLIENT_ID,
            'status' => 'active',
        ]);
    }

    public function test_fuel_assistant_close_ends_session(): void
    {
        FuelAssistantSession::query()->create([
            'client_id' => self::CLIENT_ID,
            'status' => 'active',
            'messages' => [],
        ]);

        $this->postJson('/api/fuel-assistant/close', [
            'client_id' => self::CLIENT_ID,
        ])->assertOk();

        $this->assertDatabaseHas('fuel_assistant_sessions', [
            'client_id' => self::CLIENT_ID,
            'status' => 'cancelled',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function aiResponse(array $payload): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ],
            ]],
        ];
    }
}
