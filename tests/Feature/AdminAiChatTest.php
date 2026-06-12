<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Station;
use App\Services\StationMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.password' => 'test-admin-secret',
            'ai.api_key' => 'test-key',
            'ai.base_uri' => 'https://api.timeweb.ai/v1',
            'ai.model' => 'gemini/gemini-2.5-flash-lite',
        ]);
    }

    public function test_ai_chat_status_reports_configuration(): void
    {
        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/ai-chat/status')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.model', 'gemini/gemini-2.5-flash-lite');
    }

    public function test_ai_chat_parse_matches_stations_and_apply_creates_reports(): void
    {
        $station = Station::query()->create([
            'name' => 'АЗС 61 Верхнесадовое',
            'network' => 'Атан',
            'address' => 'Верхнесадовое',
            'latitude' => 44.6,
            'longitude' => 33.5,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'С 10:00 на Атан свободная продажа',
                            'network' => 'Атан',
                            'network_notes' => ['В сети ТЭС только QR'],
                            'stations' => [[
                                'name_hint' => 'АЗС 61 Верхнесадовое',
                                'address_hint' => 'Верхнесадовое',
                                'sale_types' => ['regular'],
                                'fuels' => [
                                    ['fuel_type' => 'a92', 'status' => 'available'],
                                    ['fuel_type' => 'a95_plus', 'status' => 'available'],
                                ],
                            ]],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $parse = $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/ai-chat/parse', [
                'message' => '1️⃣ АЗС 61 Верхнесадовое (АИ-92, АИ-95 Ultra)',
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.station_id', $station->id)
            ->assertJsonPath('data.network_notes.0', 'В сети ТЭС только QR')
            ->assertJsonStructure([
                'data' => [
                    'items' => [['queue_id']],
                    'parse_stats' => ['matched', 'unmatched', 'total'],
                    'ai_debug' => ['model', 'duration_ms', 'system_prompt', 'user_message', 'response_raw', 'response_parsed'],
                ],
            ]);

        $items = $parse->json('data.items');

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/ai-chat/apply', [
                'items' => [[
                    'station_id' => $items[0]['station_id'],
                    'fuels' => array_map(fn (array $fuel) => [
                        'fuel_type' => $fuel['fuel_type'],
                        'statuses' => $fuel['statuses'],
                        'sale_types' => $fuel['sale_types'],
                        'comment' => $fuel['comment'],
                    ], $items[0]['fuels']),
                ]],
                'queue_ids' => [$items[0]['queue_id']],
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, Report::query()->where('station_id', $station->id)->count());
        $this->assertDatabaseCount('fuel_import_queue', 0);
    }

    public function test_ai_chat_queue_lists_pending_items(): void
    {
        Station::query()->create([
            'name' => 'АТАН Россия №82',
            'network' => 'Атан',
            'address' => 'Камышовое шоссе',
            'latitude' => 44.61,
            'longitude' => 33.51,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Тест',
                            'network' => 'Атан',
                            'network_notes' => [],
                            'stations' => [[
                                'name_hint' => 'АЗС 82 Камышовое шоссе',
                                'address_hint' => 'Камышовое шоссе',
                                'sale_types' => ['regular'],
                                'fuels' => [
                                    ['fuel_type' => 'a92', 'status' => 'available'],
                                ],
                            ]],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->postJson('/api/admin/ai-chat/parse', [
                'message' => 'АЗС 82 Камышовое шоссе (АИ-92)',
            ])
            ->assertOk();

        $this->withHeader('X-Admin-Token', 'test-admin-secret')
            ->getJson('/api/admin/ai-chat/queue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.raw', 'АЗС 82 Камышовое шоссе (Камышовое шоссе)');
    }

    public function test_station_matcher_prefers_matching_station_number(): void
    {
        $station61 = Station::query()->create([
            'name' => 'АТАН Россия №61',
            'network' => 'Атан',
            'address' => 'Верхнесадовое',
            'latitude' => 44.6,
            'longitude' => 33.5,
            'source' => 'manual',
            'is_active' => true,
        ]);

        Station::query()->create([
            'name' => 'АТАН Россия №82',
            'network' => 'Атан',
            'address' => 'Камышовое шоссе',
            'latitude' => 44.61,
            'longitude' => 33.51,
            'source' => 'manual',
            'is_active' => true,
        ]);

        $match = app(StationMatcher::class)->bestMatch('Атан', 'АЗС 61 Верхнесадовое', 'Верхнесадовое');

        $this->assertNotNull($match);
        $this->assertSame($station61->id, $match['station']->id);
    }
}
