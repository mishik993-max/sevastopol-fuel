<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TimewebAiClient
{
    /** @param  list<array{role: string, content: string}>  $messages
     * @return array{data: array<string, mixed>, raw: string, duration_ms: int, model: string}
     */
    public function chatJsonWithMeta(array $messages): array
    {
        $started = microtime(true);
        $apiKey = config('ai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Не задан TIMEWEB_AI_API_KEY в .env');
        }

        $baseUri = rtrim((string) config('ai.base_uri'), '/');
        $model = (string) config('ai.model');

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(120)
            ->post("{$baseUri}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException('Ошибка AI: '.$message);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI вернул пустой ответ');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI вернул некорректный JSON');
        }

        return [
            'data' => $decoded,
            'raw' => $content,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $model,
        ];
    }

    /** @param  list<array{role: string, content: string}>  $messages */
    public function chatJson(array $messages): array
    {
        return $this->chatJsonWithMeta($messages)['data'];
    }
}
