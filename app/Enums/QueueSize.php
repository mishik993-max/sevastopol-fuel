<?php

namespace App\Enums;

enum QueueSize: string
{
    case None = 'none';
    case UpTo10 = 'up_to_10';
    case TenTo30 = '10_30';
    case ThirtyPlus = '30_plus';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Нет',
            self::UpTo10 => 'До 10 машин',
            self::TenTo30 => '10–30',
            self::ThirtyPlus => '30+',
        };
    }
}
