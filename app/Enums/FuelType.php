<?php

namespace App\Enums;

enum FuelType: string
{
    case A92 = 'a92';
    case A95 = 'a95';
    case A95Plus = 'a95_plus';
    case A100 = 'a100';
    case Dt = 'dt';
    case DtPlus = 'dt_plus';
    case Gas = 'gas';

    public function label(): string
    {
        return match ($this) {
            self::A92 => 'А-92',
            self::A95 => 'А-95',
            self::A95Plus => 'А-95+',
            self::A100 => 'А-100',
            self::Dt => 'ДТ',
            self::DtPlus => 'ДТ+',
            self::Gas => 'Газ (пропан/бутан)',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
