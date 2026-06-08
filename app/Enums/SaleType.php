<?php

namespace App\Enums;

enum SaleType: string
{
    case Regular = 'regular';
    case Voucher = 'voucher';
    case Qr = 'qr';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Обычный',
            self::Voucher => 'По талонам',
            self::Qr => 'Нужен QR',
        };
    }

    /** @param  list<string>|null  $values */
    public static function labelsFor(?array $values): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $value) => self::tryFrom($value)?->label(),
            $values,
        )));
    }
}
