<?php

namespace App\Enums;

enum CorrectionField: string
{
    case Name = 'name';
    case Address = 'address';
    case Location = 'location';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Название',
            self::Address => 'Адрес',
            self::Location => 'Местоположение',
        };
    }
}
