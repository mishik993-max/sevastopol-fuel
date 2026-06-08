<?php

namespace App\Enums;

enum FuelStatus: string
{
    case Available = 'available';
    case Low = 'low';
    case None = 'none';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Есть',
            self::Low => 'Мало',
            self::None => 'Нет',
            self::Unknown => 'Неизвестно',
        };
    }

    /** @param  list<string>  $values */
    public static function primaryFrom(array $values): self
    {
        $set = collect($values)->map(fn (string $v) => self::from($v));

        if ($set->contains(self::None)) {
            return self::None;
        }

        if ($set->contains(self::Low)) {
            return self::Low;
        }

        if ($set->contains(self::Available)) {
            return self::Available;
        }

        return self::Unknown;
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
