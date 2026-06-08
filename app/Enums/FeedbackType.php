<?php

namespace App\Enums;

enum FeedbackType: string
{
    case Feedback = 'feedback';
    case Suggestion = 'suggestion';

    public function label(): string
    {
        return match ($this) {
            self::Feedback => 'Обратная связь',
            self::Suggestion => 'Предложение',
        };
    }
}
