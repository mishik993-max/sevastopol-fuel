<?php

namespace App\Services;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use App\Enums\SaleType;
use App\Models\FuelImportQueueItem;
use App\Models\Report;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminFuelAiService
{
    public function __construct(
        private TimewebAiClient $ai,
        private StationMatcher $matcher,
    ) {}

    /** @return array<string, mixed> */
    public function parseAndMatch(string $message): array
    {
        $parsed = $this->ai->chatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $message],
        ]);

        $preview = $this->buildPreview($parsed, $message);

        return $this->attachQueueIds($preview, $message);
    }

    /** @return list<array<string, mixed>> */
    public function listQueue(): array
    {
        return FuelImportQueueItem::query()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (FuelImportQueueItem $item) => $this->entryFromQueueItem($item))
            ->values()
            ->all();
    }

    public function removeFromQueue(int $queueId): void
    {
        FuelImportQueueItem::query()->whereKey($queueId)->delete();
    }

    /** @param  list<int>  $queueIds */
    public function removeManyFromQueue(array $queueIds): void
    {
        if ($queueIds === []) {
            return;
        }

        FuelImportQueueItem::query()->whereIn('id', $queueIds)->delete();
    }

    /**
     * @param  list<array{station_id: int, fuels: list<array{fuel_type: string, statuses: list<string>, sale_types: list<string>, comment?: string|null}>}>  $items
     * @param  list<int>  $queueIds
     * @return array{created: int, stations: list<string>}
     */
    public function apply(array $items, array $queueIds = []): array
    {
        $created = 0;
        $stationLabels = [];

        DB::transaction(function () use ($items, &$created, &$stationLabels) {
            foreach ($items as $item) {
                $station = Station::query()->find($item['station_id']);

                if ($station === null) {
                    continue;
                }

                foreach ($item['fuels'] as $fuel) {
                    Report::query()->create([
                        'station_id' => $station->id,
                        'fuel_type' => FuelType::from($fuel['fuel_type']),
                        'status' => FuelStatus::primaryFrom($fuel['statuses']),
                        'statuses' => array_values(array_unique($fuel['statuses'])),
                        'queue_size' => QueueSize::Unknown,
                        'sale_types' => array_values(array_unique($fuel['sale_types'])),
                        'comment' => $fuel['comment'] ?? null,
                        'is_confirmation' => false,
                        'created_at' => now(),
                    ]);

                    $created++;
                }

                $stationLabels[] = "{$station->network} · {$station->name}";
            }
        });

        $this->removeManyFromQueue($queueIds);

        return [
            'created' => $created,
            'stations' => array_values(array_unique($stationLabels)),
        ];
    }

    /** @param  array<string, mixed>  $parsed */
    private function buildPreview(array $parsed, string $sourceMessage): array
    {
        $networkHint = (string) ($parsed['network'] ?? '');
        $summary = (string) ($parsed['summary'] ?? '');
        $networkNotes = is_array($parsed['network_notes'] ?? null) ? $parsed['network_notes'] : [];
        $stations = is_array($parsed['stations'] ?? null) ? $parsed['stations'] : [];

        $items = [];
        $unmatched = [];

        foreach ($stations as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $nameHint = (string) ($row['name_hint'] ?? '');
            $addressHint = isset($row['address_hint']) ? (string) $row['address_hint'] : null;
            $fuelsRaw = is_array($row['fuels'] ?? null) ? $row['fuels'] : [];
            $saleTypes = $this->normalizeSaleTypes($row['sale_types'] ?? ['regular']);
            $note = isset($row['note']) ? (string) $row['note'] : null;

            $fuels = [];

            foreach ($fuelsRaw as $fuelRow) {
                if (! is_array($fuelRow)) {
                    continue;
                }

                $fuelType = (string) ($fuelRow['fuel_type'] ?? '');

                if (! $this->isValidFuelType($fuelType)) {
                    continue;
                }

                $status = (string) ($fuelRow['status'] ?? 'available');
                $statuses = in_array($status, ['available', 'low', 'none'], true) ? [$status] : ['available'];
                $fuelSaleTypes = isset($fuelRow['sale_types']) && is_array($fuelRow['sale_types'])
                    ? $this->normalizeSaleTypes($fuelRow['sale_types'])
                    : $saleTypes;

                $fuels[] = [
                    'fuel_type' => $fuelType,
                    'fuel_label' => FuelType::from($fuelType)->label(),
                    'statuses' => $statuses,
                    'status_label' => FuelStatus::from($statuses[0])->label(),
                    'sale_types' => $fuelSaleTypes,
                    'sale_types_labels' => SaleType::labelsFor($fuelSaleTypes),
                    'comment' => $this->buildComment($sourceMessage, $note),
                ];
            }

            if ($fuels === []) {
                continue;
            }

            $fuels = $this->dedupeFuels($fuels);

            $match = $this->matcher->bestMatch($networkHint, $nameHint, $addressHint);
            $candidates = $this->matcher->candidates($networkHint, $nameHint, $addressHint, 5);

            $entry = [
                'index' => $index,
                'name_hint' => $nameHint,
                'address_hint' => $addressHint,
                'raw' => trim($nameHint.($addressHint ? " ({$addressHint})" : '')),
                'fuels' => $fuels,
                'selected' => $match !== null,
                'station_id' => $match['station']->id ?? null,
                'station_label' => $match
                    ? "{$match['station']->network} · {$match['station']->name}"
                    : null,
                'station_address' => $match['station']->address ?? null,
                'confidence' => $match['score'] ?? null,
                'candidates' => array_map(fn (array $candidate) => $this->formatCandidate($candidate['station'], $candidate['score']), $candidates),
            ];

            if ($match !== null) {
                $items[] = $entry;
            } else {
                $unmatched[] = $entry;
            }
        }

        return [
            'summary' => $summary,
            'network' => $networkHint,
            'network_notes' => array_values(array_filter(array_map(
                fn ($note) => is_string($note) ? $note : null,
                $networkNotes,
            ))),
            'items' => $items,
            'unmatched' => $unmatched,
        ];
    }

    private function systemPrompt(): string
    {
        $fuelTypes = implode(', ', array_map(fn (FuelType $type) => $type->value, FuelType::cases()));
        $saleTypes = implode(', ', array_map(fn (SaleType $type) => $type->value, SaleType::cases()));

        return <<<PROMPT
Ты помощник админки карты АЗС Севастополя. Разбираешь сообщения о наличии топлива и возвращаешь ТОЛЬКО JSON без markdown.

Схема ответа:
{
  "summary": "краткое описание на русском",
  "network": "основная сеть из текста, например Атан или ТЭС",
  "network_notes": ["общие замечания по сетям, не привязанные к конкретной АЗС"],
  "stations": [
    {
      "name_hint": "как в сообщении, например АЗС 61 Верхнесадовое",
      "address_hint": "улица или район если есть",
      "sale_types": ["regular"],
      "note": "доп. детали по этой АЗС",
      "fuels": [
        {"fuel_type": "a92", "status": "available", "sale_types": ["regular"]}
      ]
    }
  ]
}

Правила fuel_type (строго из списка): {$fuelTypes}
- АИ-92, 92 -> a92
- АИ-95 (обычный) -> a95
- АИ-95 Ultra, 95+, 95 Ultra -> a95_plus
- АИ-100 -> a95_plus (ближайший тип; в note укажи «АИ-100»)
- ДТ, дизель -> dt
- ДТ Ultra, ДТ+ -> dt_plus
- газ, пропан -> gas

Правила sale_types (строго из списка): {$saleTypes}
- свободная продажа, обычная -> regular
- талоны -> voucher
- QR, по QR-коду -> qr

Если в сообщении «свободная продажа» для списка АЗС — sale_types: ["regular"].
Если для сети указано «только по QR» без списка АЗС — добавь это в network_notes, не создавай stations для всех АЗС.

status: available (топливо есть), low (мало), none (нет). По умолчанию available для явного списка «будет в продаже».

Каждая numbered строка (1️⃣, 2., и т.д.) — отдельный элемент stations.
Не выдумывай АЗС, которых нет в тексте.
PROMPT;
    }

    /** @param  mixed  $values */
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

    private function isValidFuelType(string $fuelType): bool
    {
        return FuelType::tryFrom($fuelType) !== null;
    }

    private function buildComment(string $sourceMessage, ?string $note): ?string
    {
        $prefix = 'Импорт из AI: '.mb_substr(preg_replace('/\s+/u', ' ', trim($sourceMessage)) ?? '', 0, 120);

        if ($note === null || trim($note) === '') {
            return $prefix;
        }

        return $prefix.' · '.$note;
    }

    /** @param  list<array<string, mixed>>  $fuels */
    private function dedupeFuels(array $fuels): array
    {
        $seen = [];
        $result = [];

        foreach ($fuels as $fuel) {
            $key = $fuel['fuel_type'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $fuel;
        }

        return $result;
    }

    /** @return array{station_id: int, label: string, address: string, score: float, map_url: string} */
    private function formatCandidate(Station $station, float $score): array
    {
        return [
            'station_id' => $station->id,
            'label' => "{$station->network} · {$station->name}",
            'address' => $station->address,
            'score' => $score,
            'map_url' => $this->stationMapUrl($station->id),
        ];
    }

    private function stationMapUrl(int $stationId): string
    {
        return rtrim((string) config('app.url'), '/').'/?station='.$stationId;
    }

    /** @param  array<string, mixed>  $preview */
    private function attachQueueIds(array $preview, string $sourceMessage): array
    {
        $preview['items'] = array_map(
            fn (array $entry) => $this->attachQueueId($entry, (string) ($preview['network'] ?? ''), $sourceMessage),
            $preview['items'],
        );
        $preview['unmatched'] = array_map(
            fn (array $entry) => $this->attachQueueId($entry, (string) ($preview['network'] ?? ''), $sourceMessage),
            $preview['unmatched'],
        );

        return $preview;
    }

    /** @param  array<string, mixed>  $entry */
    private function attachQueueId(array $entry, string $network, string $sourceMessage): array
    {
        $entry['queue_id'] = $this->upsertQueueItem($entry, $network, $sourceMessage);

        return $entry;
    }

    /** @param  array<string, mixed>  $entry */
    private function upsertQueueItem(array $entry, string $network, string $sourceMessage): int
    {
        $fingerprint = $this->fingerprint(
            $network,
            (string) ($entry['name_hint'] ?? $entry['raw']),
            $entry['fuels'],
        );

        $item = FuelImportQueueItem::query()->firstOrNew(['fingerprint' => $fingerprint]);
        $item->fill([
            'network' => $network !== '' ? $network : null,
            'name_hint' => (string) ($entry['name_hint'] ?? $entry['raw']),
            'address_hint' => $entry['address_hint'] ?? null,
            'raw' => (string) $entry['raw'],
            'fuels' => $entry['fuels'],
            'source_message' => $sourceMessage,
        ]);
        $item->save();

        return $item->id;
    }

    /** @param  list<array<string, mixed>>  $fuels */
    private function fingerprint(string $network, string $nameHint, array $fuels): string
    {
        $fuelKeys = array_map(fn (array $fuel) => $fuel['fuel_type'] ?? '', $fuels);
        sort($fuelKeys);

        return hash('sha256', mb_strtolower(trim($network.'|'.$nameHint.'|'.implode(',', $fuelKeys))));
    }

    /** @return array<string, mixed> */
    private function entryFromQueueItem(FuelImportQueueItem $item): array
    {
        $network = (string) ($item->network ?? '');
        $nameHint = $item->name_hint;
        $addressHint = $item->address_hint;
        $fuels = is_array($item->fuels) ? $item->fuels : [];

        $match = $this->matcher->bestMatch($network, $nameHint, $addressHint);
        $candidates = $this->matcher->candidates($network, $nameHint, $addressHint, 5);

        return [
            'queue_id' => $item->id,
            'name_hint' => $nameHint,
            'address_hint' => $addressHint,
            'raw' => $item->raw,
            'fuels' => $fuels,
            'selected' => $match !== null,
            'station_id' => $match['station']->id ?? null,
            'station_label' => $match
                ? "{$match['station']->network} · {$match['station']->name}"
                : null,
            'station_address' => $match['station']->address ?? null,
            'confidence' => $match['score'] ?? null,
            'candidates' => array_map(
                fn (array $candidate) => $this->formatCandidate($candidate['station'], $candidate['score']),
                $candidates,
            ),
            'queued_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
