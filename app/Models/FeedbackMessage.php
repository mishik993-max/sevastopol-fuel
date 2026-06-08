<?php

namespace App\Models;

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use Illuminate\Database\Eloquent\Model;

class FeedbackMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'message',
        'contact',
        'status',
        'admin_note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => FeedbackType::class,
            'status' => FeedbackStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
