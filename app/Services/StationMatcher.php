<?php

namespace App\Services;

use App\Models\Station;

class StationMatcher
{
    /** @return list<array{station: Station, score: float, match_type: string, distance_m?: int}> */
    public function candidates(
        string $networkHint,
        string $nameHint,
        ?string $addressHint = null,
        int $limit = 5,
        ?float $latitude = null,
        ?float $longitude = null,
        bool $restrictNetwork = true,
    ): array {
        $networkHint = $this->normalize($networkHint);
        $stations = Station::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Station $station) => ! $restrictNetwork
                || $networkHint === ''
                || $this->networksMatch($station->network, $networkHint));

        $needle = $this->normalize(trim($nameHint.' '.($addressHint ?? '')));
        $addressNeedle = $this->addressNeedle($nameHint, $addressHint);
        $number = $this->extractStationNumber($nameHint.' '.($addressHint ?? ''));

        $scored = [];

        foreach ($stations as $station) {
            $distanceM = $this->stationDistanceM($station, $latitude, $longitude);
            $best = null;

            $strictScore = $this->scoreStrict($station, $needle, $number, $networkHint);

            if ($strictScore >= 25) {
                $best = [
                    'station' => $station,
                    'score' => $strictScore,
                    'match_type' => 'number',
                    'distance_m' => $distanceM,
                ];
            }

            $addressScore = $this->scoreByAddress($station, $addressNeedle, $networkHint, $number);

            if ($addressScore >= 25 && ($best === null || $addressScore > $best['score'])) {
                $best = [
                    'station' => $station,
                    'score' => $addressScore,
                    'match_type' => 'address',
                    'distance_m' => $distanceM,
                ];
            }

            if ($latitude !== null && $longitude !== null) {
                $coordinateMatch = $this->scoreByCoordinates($station, $latitude, $longitude, $networkHint);

                if ($coordinateMatch !== null && ($best === null || $coordinateMatch['score'] > $best['score'])) {
                    $best = $coordinateMatch;
                }
            }

            if ($best !== null) {
                $scored[] = $best;
            }
        }

        usort($scored, fn (array $a, array $b) => $this->compareCandidates($a, $b, $latitude !== null && $longitude !== null));

        $deduped = [];
        $seen = [];

        foreach ($scored as $item) {
            $id = $item['station']->id;

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $deduped[] = $item;

            if (count($deduped) >= $limit) {
                break;
            }
        }

        return $deduped;
    }

    /** @return array{station: Station, score: float, match_type: string, distance_m?: int}|null */
    public function bestMatch(
        string $networkHint,
        string $nameHint,
        ?string $addressHint = null,
        ?float $latitude = null,
        ?float $longitude = null,
        bool $restrictNetwork = true,
    ): ?array {
        $hasCoords = $latitude !== null && $longitude !== null;
        $limit = $hasCoords && ! $restrictNetwork ? 15 : 5;
        $candidates = $this->candidates(
            $networkHint,
            $nameHint,
            $addressHint,
            $limit,
            $latitude,
            $longitude,
            $restrictNetwork,
        );

        if ($candidates === []) {
            return null;
        }

        $best = $candidates[0];

        if ($hasCoords && ($best['distance_m'] ?? null) !== null && $best['distance_m'] <= 100) {
            return $best['score'] >= 55 ? $best : null;
        }

        $minScore = in_array($best['match_type'], ['address', 'coordinates'], true) ? 55 : 45;

        return $best['score'] >= $minScore ? $best : null;
    }

    /** @param  array{score: float, match_type: string, distance_m?: int|null}  $a
     * @param  array{score: float, match_type: string, distance_m?: int|null}  $b
     */
    private function compareCandidates(array $a, array $b, bool $preferDistance): int
    {
        $tierA = $this->candidateTier($a);
        $tierB = $this->candidateTier($b);

        if ($tierA !== $tierB) {
            return $tierB <=> $tierA;
        }

        if ($preferDistance && $tierA === 2) {
            $distanceA = $a['distance_m'] ?? null;
            $distanceB = $b['distance_m'] ?? null;

            if ($distanceA !== null && $distanceB !== null && $distanceA !== $distanceB) {
                return $distanceA <=> $distanceB;
            }

            if ($distanceA !== null && $distanceB === null) {
                return -1;
            }

            if ($distanceA === null && $distanceB !== null) {
                return 1;
            }
        }

        return $b['score'] <=> $a['score'];
    }

    /** @param  array{score: float, match_type: string}  $candidate */
    private function candidateTier(array $candidate): int
    {
        $type = $candidate['match_type'];
        $score = $candidate['score'];

        if (in_array($type, ['number', 'address'], true) && $score >= 55) {
            return 3;
        }

        if ($type === 'coordinates' && $score >= 55) {
            return 2;
        }

        return 1;
    }

    private function stationDistanceM(Station $station, ?float $latitude, ?float $longitude): ?int
    {
        if ($latitude === null || $longitude === null || $station->latitude === null || $station->longitude === null) {
            return null;
        }

        return (int) round($this->distanceM(
            $latitude,
            $longitude,
            (float) $station->latitude,
            (float) $station->longitude,
        ));
    }

    private function scoreStrict(Station $station, string $needle, ?string $number, string $networkHint): float
    {
        $haystack = $this->normalize($station->name.' '.$station->address);
        $score = 0.0;

        if ($networkHint !== '' && $this->networksMatch($station->network, $networkHint)) {
            $score += 25;
        }

        if ($number !== null) {
            $stationNumber = $this->extractStationNumberFromStation($station);

            if ($stationNumber !== null) {
                if ($stationNumber !== $number) {
                    return 0;
                }

                $score += 55;
            } elseif (! preg_match('/\b'.preg_quote($number, '/').'\b/u', $haystack)) {
                return 0;
            } else {
                $score += 45;
            }
        }

        similar_text($haystack, $needle, $percent);
        $score += min(25.0, $percent * 0.25);

        foreach ($this->tokens($needle) as $token) {
            if (mb_strlen($token) >= 4 && str_contains($haystack, $token)) {
                $score += 3;
            }
        }

        return min(100.0, round($score, 1));
    }

    private function scoreByAddress(Station $station, string $addressNeedle, string $networkHint, ?string $number): float
    {
        if ($addressNeedle === '' || mb_strlen($addressNeedle) < 4) {
            return 0;
        }

        $stationAddress = $this->normalize($station->address);
        $haystack = $this->normalize($station->name.' '.$station->address);

        if ($stationAddress === '') {
            return 0;
        }

        $hintHouse = $this->extractHouseNumber($addressNeedle);
        $stationHouse = $this->extractHouseNumber($stationAddress);

        if ($hintHouse !== null && $stationHouse !== null && ! $this->houseNumbersMatch($hintHouse, $stationHouse)) {
            return 0;
        }

        $score = 0.0;

        if ($networkHint !== '' && $this->networksMatch($station->network, $networkHint)) {
            $score += 15;
        }

        similar_text($stationAddress, $addressNeedle, $percent);
        $score += min(45.0, $percent * 0.55);

        if ($hintHouse !== null && $stationHouse !== null) {
            $score += 28;
        }

        foreach ($this->addressTokens($addressNeedle) as $token) {
            if (str_contains($haystack, $token)) {
                $score += 6;
            }
        }

        if ($number !== null) {
            $stationNumber = $this->extractStationNumberFromStation($station);

            if ($stationNumber === $number) {
                $score += 15;
            }
        }

        return min(85.0, round($score, 1));
    }

    /** @return array{station: Station, score: float, match_type: string, distance_m: int}|null */
    private function scoreByCoordinates(Station $station, float $latitude, float $longitude, string $networkHint): ?array
    {
        if ($station->latitude === null || $station->longitude === null) {
            return null;
        }

        $distanceM = (int) round($this->distanceM(
            $latitude,
            $longitude,
            (float) $station->latitude,
            (float) $station->longitude,
        ));

        $maxDistanceM = (int) config('sevtech.match_max_distance_m', 400);

        if ($distanceM > $maxDistanceM) {
            return null;
        }

        if ($distanceM <= 30) {
            $score = 98 - ($distanceM / 30) * 3;
        } elseif ($distanceM <= 100) {
            $score = 95 - (($distanceM - 30) / 70) * 8;
        } else {
            $score = 92 - ($distanceM / max($maxDistanceM, 1)) * 37;
        }

        if ($networkHint !== '' && $this->networksMatch($station->network, $networkHint)) {
            $score += 2;
        }

        return [
            'station' => $station,
            'score' => round(min(98.0, max(55.0, $score)), 1),
            'match_type' => 'coordinates',
            'distance_m' => $distanceM,
        ];
    }

    private function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function addressNeedle(string $nameHint, ?string $addressHint): string
    {
        $address = trim((string) $addressHint);

        if ($address === '') {
            $address = preg_replace('/^(?:азс|а\.?з\.?с\.?)\s*№?\s*\d+\s*/ui', '', $nameHint) ?? $nameHint;
        }

        return $this->normalize($address);
    }

    private function extractHouseNumber(string $text): ?string
    {
        if (! preg_match_all('/(\d+)\s*([а-яa-z])?/ui', $text, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($matches);
        $digits = $last[1];
        $letter = isset($last[2]) && $last[2] !== '' ? mb_strtolower($last[2]) : '';

        return $digits.$letter;
    }

    private function houseNumbersMatch(string $left, string $right): bool
    {
        return $this->normalizeHouseNumber($left) === $this->normalizeHouseNumber($right);
    }

    private function normalizeHouseNumber(string $value): string
    {
        if (preg_match('/^(\d+)\s*([а-яa-z])?/ui', $value, $matches)) {
            $letter = isset($matches[2]) ? mb_strtolower($matches[2]) : '';

            return $matches[1].$letter;
        }

        return mb_strtolower($value);
    }

    /** @return list<string> */
    private function addressTokens(string $text): array
    {
        $stopWords = [
            'улица', 'ул', 'проспект', 'пр', 'просп', 'переулок', 'пер',
            'шоссе', 'ш', 'бульвар', 'бул', 'район', 'поселок', 'пос',
            'село', 'город', 'севастополь', 'россия',
        ];

        return array_values(array_filter(
            $this->tokens($text),
            fn (string $token) => mb_strlen($token) >= 4 && ! in_array($token, $stopWords, true),
        ));
    }

    private function networksMatch(string $left, string $right): bool
    {
        $a = $this->normalize($left);
        $b = $this->normalize($right);

        if ($a === '' || $b === '') {
            return false;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        foreach ($this->networkAliases($a) as $alias) {
            if ($alias === $b || str_contains($b, $alias) || str_contains($alias, $b)) {
                return true;
            }
        }

        foreach ($this->networkAliases($b) as $alias) {
            if ($alias === $a || str_contains($a, $alias) || str_contains($alias, $a)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function networkAliases(string $network): array
    {
        $groups = [
            ['atan', 'атан'],
            ['тэс', 'tes'],
            ['wog'],
            ['снп', 'snp'],
        ];

        foreach ($groups as $aliases) {
            if (in_array($network, $aliases, true)) {
                return $aliases;
            }
        }

        return [$network];
    }

    private function extractStationNumber(string $text): ?string
    {
        if (preg_match('/(?:азс|а\.?з\.?с\.?)\s*[-–№]?\s*(\d+)/ui', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/№\s*(\d+)/u', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractStationNumberFromStation(Station $station): ?string
    {
        if (preg_match('/(?:азс|а\.?з\.?с\.?)\s*[-–№]?\s*(\d+)/ui', $station->name, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(?:№|Russia|Россия|ATAN|АТАН)\s*(\d+)/ui', $station->name, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\d]{4,}/u', $text, $matches);

        return $matches[0] ?? [];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/[^\p{L}\d\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
