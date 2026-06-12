<?php

namespace App\Services;

use App\Models\Station;

class StationMatcher
{
    /** @return list<array{station: Station, score: float}> */
    public function candidates(string $networkHint, string $nameHint, ?string $addressHint = null, int $limit = 5): array
    {
        $networkHint = $this->normalize($networkHint);
        $stations = Station::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Station $station) => $networkHint === '' || $this->networksMatch($station->network, $networkHint));

        $needle = $this->normalize(trim($nameHint.' '.($addressHint ?? '')));
        $number = $this->extractStationNumber($nameHint.' '.($addressHint ?? ''));

        $scored = [];

        foreach ($stations as $station) {
            $score = $this->score($station, $needle, $number, $networkHint);

            if ($score >= 25) {
                $scored[] = ['station' => $station, 'score' => $score];
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    public function bestMatch(string $networkHint, string $nameHint, ?string $addressHint = null): ?array
    {
        $candidates = $this->candidates($networkHint, $nameHint, $addressHint, 1);

        if ($candidates === []) {
            return null;
        }

        $best = $candidates[0];

        return $best['score'] >= 45 ? $best : null;
    }

    private function score(Station $station, string $needle, ?string $number, string $networkHint): float
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
            if ($alias === $b || str_contains($b, $alias)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function networkAliases(string $network): array
    {
        $map = [
            'atan' => ['atan', 'атан'],
            'тэс' => ['тэс', 'tes'],
            'wog' => ['wog'],
        ];

        foreach ($map as $aliases) {
            if (in_array($network, $aliases, true)) {
                return $aliases;
            }
        }

        return [$network];
    }

    private function extractStationNumber(string $text): ?string
    {
        if (preg_match('/(?:азс|а\.?з\.?с\.?)\s*№?\s*(\d+)/ui', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/№\s*(\d+)/u', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractStationNumberFromStation(Station $station): ?string
    {
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
