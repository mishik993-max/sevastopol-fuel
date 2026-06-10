<?php

namespace App\Enums;

enum CanisterPolicy: string
{
    case Allowed = 'allowed';
    case Forbidden = 'forbidden';

    public function label(): string
    {
        return match ($this) {
            self::Allowed => 'Можно в канистру',
            self::Forbidden => 'Нельзя в канистру',
        };
    }
}
