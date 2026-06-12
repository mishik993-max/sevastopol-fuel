<?php

namespace App\Support;

use App\Models\Station;

class StationDisplay
{
    /** @var list<string> */
    private const GENERIC_NAMES = [
        'atan', 'атан', 'тэс', 'tes', 'wog', 'crs', 'snp', 'азс', 'заправка', 'fuel',
    ];

    public static function optionLabel(Station $station): string
    {
        $number = self::extractNumber($station->name);
        $shortAddress = self::shortAddress($station->address);
        $name = trim($station->name);

        if ($number !== null) {
            $title = "АЗС №{$number}";
        } elseif (self::isGenericName($name, $station->network)) {
            $title = $shortAddress !== '' ? $shortAddress : trim((string) $station->network);
        } else {
            $title = $name;
        }

        if ($shortAddress !== '' && ! self::contains($title, $shortAddress)) {
            return "{$title} · {$shortAddress}";
        }

        return $title !== '' ? $title : 'АЗС';
    }

    public static function cardLabel(Station $station): string
    {
        $option = self::optionLabel($station);
        $network = trim((string) ($station->network ?? ''));

        if ($network === '' || self::contains(mb_strtolower($option), mb_strtolower($network))) {
            return $option;
        }

        return "{$network} · {$option}";
    }

    public static function extractNumber(string $text): ?string
    {
        if (preg_match('/(?:азс|а\.?з\.?с\.?)\s*[-–№]?\s*(\d+)/ui', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/№\s*(\d+)/u', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function shortAddress(?string $address): string
    {
        $clean = AddressSanitizer::clean($address);
        $clean = preg_replace('/,?\s*(?:севастополь|россия).*$/ui', '', $clean) ?? $clean;

        return trim($clean, ', ');
    }

    private static function isGenericName(string $name, ?string $network): bool
    {
        $normalized = self::normalize($name);
        $networkNorm = self::normalize((string) $network);

        if ($normalized === '' || $normalized === $networkNorm) {
            return true;
        }

        if (in_array($normalized, self::GENERIC_NAMES, true)) {
            return true;
        }

        return preg_match('/^(atan|атан|тэс|tes|wog|crs|snp)\s*(россия|russia)?\s*$/ui', $name) === 1;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains(self::normalize($haystack), self::normalize($needle));
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/[^\p{L}\d\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
