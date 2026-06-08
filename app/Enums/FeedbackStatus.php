<?php

namespace App\Enums;

enum FeedbackStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новое',
            self::Read => 'Прочитано',
            self::Done => 'Обработано',
        };
    }
}
