<?php

namespace App\Support;

class AddressSanitizer
{
    public static function clean(?string $address): string
    {
        if ($address === null || trim($address) === '') {
            return 'Севастополь, Россия';
        }

        $address = trim($address);

        $address = str_ireplace(
            ['Ukraine', 'Украина', 'Украине', 'Украину', 'Украины'],
            '',
            $address,
        );

        $address = preg_replace('/,?\s*\d{5,6}\b/u', '', $address) ?? $address;

        $address = preg_replace(
            '/,?\s*(Leninsky|Nakhimovsky|Gagarinsky|Balaklavsky)\s+(Municipal\s+)?District\b/iu',
            '',
            $address,
        ) ?? $address;

        $address = preg_replace(
            '/,?\s*(Нахимовский|Ленинский|Гагаринский|Балаклавский)\s+(район|округ)\b/iu',
            '',
            $address,
        ) ?? $address;

        $address = preg_replace('/,?\s*Sevastopol\b/iu', '', $address) ?? $address;
        $address = preg_replace('/,?\s*North Side\b/iu', '', $address) ?? $address;
        $address = preg_replace('/\s*,\s*/u', ', ', $address) ?? $address;
        $address = trim($address, ', ');

        $address = self::translateKnownStreets($address);

        if ($address === '') {
            return 'Севастополь, Россия';
        }

        if (! preg_match('/севастополь/iu', $address)) {
            $address .= ', Севастополь';
        }

        if (! preg_match('/россия/iu', $address)) {
            $address .= ', Россия';
        }

        return self::compact($address);
    }

    private static function compact(string $address): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));

        $result = [];
        $hasCity = false;
        $hasCountry = false;

        foreach ($parts as $part) {
            if (preg_match('/^(севастополь|россия)$/iu', $part)) {
                if (preg_match('/^севастополь$/iu', $part)) {
                    $hasCity = true;
                }
                if (preg_match('/^россия$/iu', $part)) {
                    $hasCountry = true;
                }

                continue;
            }

            if (preg_match('/^(ленинский|нахимовский|гагаринский|балаклавский)\s+(округ|район)$/iu', $part)) {
                continue;
            }

            $result[] = $part;
        }

        if ($hasCity) {
            $result[] = 'Севастополь';
        }

        if ($hasCountry) {
            $result[] = 'Россия';
        }

        return implode(', ', $result) ?: 'Севастополь, Россия';
    }

    private static function translateKnownStreets(string $address): string
    {
        $map = [
            'Mykolaya Muzyki Street' => 'ул. Николая Музыки',
            'Kamyshovoe Highway' => 'Камышовое шоссе',
            'Khrustalyova Street' => 'ул. Хрусталева',
            'Cheliuskintsev Street' => 'ул. Челюскинцев',
            'Semipalatinskaya Street' => 'ул. Семипалатинская',
            'Fiolentovskoe Road' => 'Фиолентовское шоссе',
            'Laboratorne Road' => 'Лабораторное шоссе',
            'Simonok Street' => 'ул. Симонок',
            'Shabalina' => 'ул. Шабалина',
        ];

        foreach ($map as $en => $ru) {
            $address = str_ireplace($en, $ru, $address);
        }

        return $address;
    }
}
