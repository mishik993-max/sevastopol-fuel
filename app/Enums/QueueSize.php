<?php

namespace App\Enums;

enum QueueSize: string
{
    case None = 'none';
    case UpTo10 = 'up_to_10';
    case TenTo30 = '10_30';
    case ThirtyPlus = '30_plus';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Очереди нет',
            self::UpTo10 => 'До 10 машин',
            self::TenTo30 => '10-30 машин',
            self::ThirtyPlus => 'Больше 30 машин',
            self::Unknown => 'Не знаю',
        };
    }
}
