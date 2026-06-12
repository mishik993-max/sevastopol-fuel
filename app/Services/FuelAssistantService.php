<?php

namespace App\Services;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use App\Enums\SaleType;
use App\Models\FuelAssistantSession;
use App\Models\Report;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FuelAssistantService
{
    public function __construct(
        private TimewebAiClient $ai,
        private StationMatcher $matcher,
    ) {}

    /** @return array<string, mixed>|null */
    public function activeSessionForClient(string $clientId): ?array
    {
        $session = $this->findActiveSession($clientId);

        return $session ? $this->formatSession($session) : null;
    }

    /** @return array<string, mixed> */
    public function sendMessage(
        string $clientId,
        string $message,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $contextStationId = null,
    ): array {
        $session = $this->resolveSession($clientId);

        if ($this->userTurnCount($session) >= (int) config('fuel_assistant.max_turns', 25)) {
            $this->closeSession($session, 'completed');

            throw new RuntimeException('Достигнут лимит сообщений в диалоге. Начните новый.');
        }

        $session->messages = array_merge($session->messages ?? [], [[
            'role' => 'user',
            'content' => trim($message),
            'at' => now()->toIso8601String(),
        ]]);

        $context = $this->buildContext($contextStationId, $latitude, $longitude);
        $aiResult = $this->ai->chatJsonWithMeta($this->buildAiMessages($session, $context));
        $parsed = $aiResult['data'];

        $reply = trim((string) ($parsed['reply'] ?? ''));
        $status = (string) ($parsed['status'] ?? 'collecting');

        if ($reply === '') {
            throw new RuntimeException('AI не вернул ответ');
        }

        if ($status === 'rejected') {
            $session->draft = null;
            $session->preview = null;
            $this->appendMessage($session, [
                'role' => 'assistant',
                'content' => $reply,
                'status' => 'rejected',
                'at' => now()->toIso8601String(),
            ]);
            $session->save();

            return $this->formatSession($session->fresh());
        }

        $draft = is_array($parsed['draft'] ?? null) ? $parsed['draft'] : null;

        if ($status === 'draft' && $draft !== null) {
            $preview = $this->buildPreview($draft, $latitude, $longitude);
            $session->draft = $draft;
            $session->preview = $preview;
            $this->appendMessage($session, [
                'role' => 'assistant',
                'content' => $reply,
                'status' => 'draft',
                'at' => now()->toIso8601String(),
            ]);
            $session->save();

            $formatted = $this->formatSession($session->fresh());
            $formatted['ai_debug'] = [
                'duration_ms' => $aiResult['duration_ms'],
                'model' => $aiResult['model'],
            ];

            return $formatted;
        }

        $session->draft = null;
        $session->preview = null;
        $this->appendMessage($session, [
            'role' => 'assistant',
            'content' => $reply,
            'status' => 'collecting',
            'at' => now()->toIso8601String(),
        ]);
        $session->save();

        return $this->formatSession($session->fresh());
    }

    /** @return array<string, mixed> */
    public function confirm(string $clientId, ?int $stationId = null): array
    {
        $session = $this->findActiveSession($clientId);

        if ($session === null || ! is_array($session->preview)) {
            throw new RuntimeException('Нет данных для публикации. Продолжите диалог.');
        }

        $preview = $session->preview;
        $resolvedStationId = $stationId ?? ($preview['station_id'] ?? null);

        if ($resolvedStationId === null) {
            throw new RuntimeException('Выберите АЗС перед публикацией.');
        }

        $station = Station::query()->find($resolvedStationId);

        if ($station === null) {
            throw new RuntimeException('АЗС не найдена.');
        }

        $fuels = $preview['fuels'] ?? [];

        if ($fuels === []) {
            throw new RuntimeException('Нет данных о топливе для публикации.');
        }

        $created = 0;

        DB::transaction(function () use ($station, $fuels, &$created) {
            foreach ($fuels as $fuel) {
                Report::query()->create([
                    'station_id' => $station->id,
                    'fuel_type' => FuelType::from($fuel['fuel_type']),
                    'status' => FuelStatus::primaryFrom($fuel['statuses']),
                    'statuses' => $fuel['statuses'],
                    'queue_size' => QueueSize::from($fuel['queue_size'] ?? QueueSize::Unknown->value),
                    'sale_types' => $fuel['sale_types'],
                    'comment' => $fuel['comment'] ?? 'Помощник на карте',
                    'is_confirmation' => false,
                    'created_at' => now(),
                ]);

                $created++;
            }
        });

        $this->closeSession($session, 'completed');

        return [
            'created' => $created,
            'station_id' => $station->id,
            'station_label' => "{$station->network} · {$station->name}",
            'fuel_type' => $fuels[0]['fuel_type'] ?? FuelType::A95->value,
            'message' => 'Спасибо! Данные опубликованы на карте.',
        ];
    }

    /** @return array<string, mixed> */
    public function reject(string $clientId, ?string $message = null): array
    {
        $session = $this->findActiveSession($clientId);

        if ($session === null) {
            throw new RuntimeException('Диалог не найден.');
        }

        $session->draft = null;
        $session->preview = null;

        $userText = trim((string) $message);

        if ($userText === '') {
            $userText = 'Нет, это не так';
        }

        $session->messages = array_merge($session->messages ?? [], [[
            'role' => 'user',
            'content' => $userText,
            'at' => now()->toIso8601String(),
        ]]);

        $aiResult = $this->ai->chatJsonWithMeta(
            $this->buildAiMessages($session, null, 'Пользователь отклонил preview. Спроси, что исправить, и собери данные заново.'),
        );

        $reply = trim((string) ($aiResult['data']['reply'] ?? 'Что нужно исправить?'));

        $this->appendMessage($session, [
            'role' => 'assistant',
            'content' => $reply,
            'status' => 'collecting',
            'at' => now()->toIso8601String(),
        ]);
        $session->save();

        return $this->formatSession($session->fresh());
    }

    public function close(string $clientId): void
    {
        $session = $this->findActiveSession($clientId);

        if ($session !== null) {
            $this->closeSession($session, 'cancelled');
        }
    }

    private function resolveSession(string $clientId): FuelAssistantSession
    {
        $session = $this->findActiveSession($clientId);

        if ($session !== null) {
            return $session;
        }

        return FuelAssistantSession::query()->create([
            'client_id' => $clientId,
            'status' => 'active',
            'messages' => [],
            'draft' => null,
            'preview' => null,
        ]);
    }

    private function findActiveSession(string $clientId): ?FuelAssistantSession
    {
        $session = FuelAssistantSession::query()
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();

        if ($session === null) {
            return null;
        }

        $idleMinutes = (int) config('fuel_assistant.idle_minutes', 120);

        if ($session->updated_at !== null && $session->updated_at->lt(now()->subMinutes($idleMinutes))) {
            $this->closeSession($session, 'expired');

            return null;
        }

        return $session;
    }

    private function closeSession(FuelAssistantSession $session, string $status): void
    {
        $session->update([
            'status' => $status,
            'closed_at' => now(),
            'draft' => null,
            'preview' => null,
        ]);
    }

    /** @param  array<string, mixed>  $message */
    private function appendMessage(FuelAssistantSession $session, array $message): void
    {
        $messages = $session->messages ?? [];
        $messages[] = $message;
        $session->messages = $messages;
    }

    /** @return array<string, mixed> */
    private function buildContext(?int $stationId, ?float $latitude, ?float $longitude): ?array
    {
        $context = [];

        if ($stationId !== null) {
            $station = Station::query()->find($stationId);

            if ($station !== null) {
                $context['selected_station'] = [
                    'id' => $station->id,
                    'label' => "{$station->network} · {$station->name}",
                    'address' => $station->address,
                ];
            }
        }

        if ($latitude !== null && $longitude !== null) {
            $context['user_location'] = [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $context === [] ? null : $context;
    }

    /** @param  array<string, mixed>|null  $context */
    private function buildAiMessages(FuelAssistantSession $session, ?array $context, ?string $extraSystem = null): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
        ];

        if ($extraSystem !== null) {
            $messages[] = ['role' => 'system', 'content' => $extraSystem];
        }

        if ($context !== null) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Контекст приложения: '.json_encode($context, JSON_UNESCAPED_UNICODE),
            ];
        }

        foreach ($session->messages ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $role = ($entry['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($entry['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            if ($role === 'assistant') {
                $content = json_encode([
                    'reply' => $content,
                    'status' => $entry['status'] ?? 'collecting',
                ], JSON_UNESCAPED_UNICODE);
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
    }

    /** @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function buildPreview(array $draft, ?float $latitude, ?float $longitude): array
    {
        $networkHint = (string) ($draft['network'] ?? '');
        $nameHint = (string) ($draft['name_hint'] ?? '');
        $addressHint = isset($draft['address_hint']) ? (string) $draft['address_hint'] : null;
        $saleTypes = $this->normalizeSaleTypes($draft['sale_types'] ?? ['regular']);
        $stationQueue = $this->normalizeQueueSize($draft['queue_size'] ?? null);
        $note = isset($draft['note']) ? (string) $draft['note'] : null;

        $fuelsRaw = is_array($draft['fuels'] ?? null) ? $draft['fuels'] : [];
        $fuels = [];

        foreach ($fuelsRaw as $fuelRow) {
            if (! is_array($fuelRow)) {
                continue;
            }

            $fuelType = (string) ($fuelRow['fuel_type'] ?? '');

            if (FuelType::tryFrom($fuelType) === null) {
                continue;
            }

            $status = (string) ($fuelRow['status'] ?? 'available');
            $statuses = in_array($status, ['available', 'low', 'none', 'unknown'], true) ? [$status] : ['available'];
            $fuelQueue = $this->normalizeQueueSize($fuelRow['queue_size'] ?? null) ?? $stationQueue ?? QueueSize::Unknown->value;

            $fuels[] = [
                'fuel_type' => $fuelType,
                'fuel_label' => FuelType::from($fuelType)->label(),
                'statuses' => $statuses,
                'status_label' => FuelStatus::from($statuses[0])->label(),
                'queue_size' => $fuelQueue,
                'queue_label' => QueueSize::from($fuelQueue)->label(),
                'sale_types' => isset($fuelRow['sale_types']) && is_array($fuelRow['sale_types'])
                    ? $this->normalizeSaleTypes($fuelRow['sale_types'])
                    : $saleTypes,
                'comment' => $note ? 'Помощник: '.$note : 'Помощник на карте',
            ];
        }

        if ($fuels === [] && $stationQueue !== null) {
            $fuels[] = [
                'fuel_type' => FuelType::A95->value,
                'fuel_label' => FuelType::A95->label(),
                'statuses' => [FuelStatus::Unknown->value],
                'status_label' => 'Только очередь',
                'queue_size' => $stationQueue,
                'queue_label' => QueueSize::from($stationQueue)->label(),
                'sale_types' => $saleTypes,
                'comment' => $note ? 'Помощник: '.$note : 'Помощник на карте',
            ];
        }

        $restrictNetwork = trim($networkHint) !== '';
        $hasStrongIdentity = $restrictNetwork && (
            ($addressHint !== null && trim($addressHint) !== '')
            || preg_match('/(?:азс|а\.?з\.?с\.?)\s*[-–№]?\s*\d+/ui', $nameHint) === 1
        );
        $matchLatitude = $hasStrongIdentity ? null : $latitude;
        $matchLongitude = $hasStrongIdentity ? null : $longitude;

        $match = $this->matcher->bestMatch(
            $networkHint,
            $nameHint,
            $addressHint,
            $matchLatitude,
            $matchLongitude,
            restrictNetwork: $restrictNetwork,
        );

        $candidates = $this->matcher->candidates(
            $networkHint,
            $nameHint,
            $addressHint,
            8,
            $matchLatitude,
            $matchLongitude,
            restrictNetwork: $restrictNetwork,
        );

        $detectedLabel = trim($networkHint) !== '' && trim($nameHint) !== ''
            ? trim("{$networkHint} · {$nameHint}")
            : null;

        return [
            'network' => $networkHint,
            'name_hint' => $nameHint,
            'address_hint' => $addressHint,
            'detected_label' => $detectedLabel,
            'detected_address' => $addressHint,
            'queue_size' => $stationQueue,
            'queue_label' => $stationQueue ? QueueSize::from($stationQueue)->label() : null,
            'fuels' => $fuels,
            'station_id' => $match['station']->id ?? null,
            'station_label' => $match
                ? "{$match['station']->network} · {$match['station']->name}"
                : $detectedLabel,
            'station_address' => $match['station']->address ?? $addressHint,
            'confidence' => $match['score'] ?? null,
            'match_type' => $match['match_type'] ?? null,
            'match_distance_m' => $match['distance_m'] ?? null,
            'candidates' => array_map(fn (array $candidate) => [
                'station_id' => $candidate['station']->id,
                'label' => "{$candidate['station']->network} · {$candidate['station']->name}",
                'address' => $candidate['station']->address,
                'score' => $candidate['score'],
                'match_type' => $candidate['match_type'],
                'distance_m' => $candidate['distance_m'] ?? null,
            ], $candidates),
        ];
    }

    /** @return array<string, mixed> */
    private function formatSession(FuelAssistantSession $session): array
    {
        return [
            'session_id' => $session->id,
            'status' => $session->status,
            'messages' => collect($session->messages ?? [])
                ->filter(fn ($m) => is_array($m) && ($m['role'] ?? '') === 'assistant' || ($m['role'] ?? '') === 'user')
                ->map(fn (array $m) => [
                    'role' => $m['role'],
                    'content' => $m['content'] ?? '',
                    'status' => $m['status'] ?? null,
                    'at' => $m['at'] ?? null,
                ])
                ->values()
                ->all(),
            'preview' => $session->preview,
            'has_preview' => is_array($session->preview) && ($session->preview['fuels'] ?? []) !== [],
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];
    }

    private function userTurnCount(FuelAssistantSession $session): int
    {
        return collect($session->messages ?? [])
            ->filter(fn ($m) => is_array($m) && ($m['role'] ?? '') === 'user')
            ->count();
    }

    private function systemPrompt(): string
    {
        $fuelTypes = implode(', ', array_map(fn (FuelType $type) => $type->value, FuelType::cases()));
        $saleTypes = implode(', ', array_map(fn (SaleType $type) => $type->value, SaleType::cases()));
        $queueSizes = implode(', ', array_map(fn (QueueSize $size) => $size->value, QueueSize::cases()));

        return <<<PROMPT
Ты личный помощник на карте АЗС Севастополя. Ведёшь короткий диалог с одним пользователем, чтобы собрать данные для отчёта о топливе.

Отвечай ТОЛЬКО JSON без markdown:
{
  "status": "collecting|draft|rejected",
  "reply": "текст пользователю на русском, 1-3 предложения",
  "draft": {
    "network": "Атан",
    "name_hint": "АЗС Семипалатинская",
    "address_hint": "проспект Победы",
    "queue_size": "30_plus",
    "sale_types": ["regular"],
    "note": "краткая деталь",
    "fuels": [{"fuel_type": "a95", "status": "available"}]
  },
  "missing": ["station"]
}

status:
- collecting — не хватает данных; задай ОДИН уточняющий вопрос; draft может быть null или частичным
- draft — данных достаточно; в reply кратко перескажи и спроси «Всё верно?»; draft заполнен
- rejected — сообщение не про топливо/АЗС/очередь; вежливый отказ, draft = null

Только темы: наличие топлива, очередь, QR/талоны, конкретная АЗС Севастополя. Остальное → rejected.

fuel_type: {$fuelTypes}
queue_size: {$queueSizes}
sale_types: {$saleTypes}

Если пользователь на выбранной АЗС (контекст selected_station) — используй её, уточняй только топливо/очередь.
Если только очередь без топлива — fuels: [], queue_size обязателен.

Не выдумывай АЗС. Один вопрос за раз при collecting.
PROMPT;
    }

    /** @param  mixed  $values
     * @return list<string>
     */
    private function normalizeSaleTypes(mixed $values): array
    {
        if (! is_array($values)) {
            return [SaleType::Regular->value];
        }

        $allowed = array_map(fn (SaleType $type) => $type->value, SaleType::cases());
        $filtered = array_values(array_unique(array_filter(
            array_map('strval', $values),
            fn (string $value) => in_array($value, $allowed, true),
        )));

        return $filtered !== [] ? $filtered : [SaleType::Regular->value];
    }

    private function normalizeQueueSize(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return QueueSize::tryFrom(trim($raw))?->value;
    }
}
