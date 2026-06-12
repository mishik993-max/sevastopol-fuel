<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelAssistantSession extends Model
{
    protected $fillable = [
        'client_id',
        'status',
        'messages',
        'draft',
        'preview',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'draft' => 'array',
            'preview' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
