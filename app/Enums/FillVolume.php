<?php

namespace App\Enums;

enum FillVolume: string
{
    case Liters20 = 'liters_20';
    case FullTank = 'full_tank';

    public function label(): string
    {
        return match ($this) {
            self::Liters20 => '20 литров',
            self::FullTank => 'Полный бак',
        };
    }
}
