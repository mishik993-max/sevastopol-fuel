<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PushSubscription extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function watches(): HasMany
    {
        return $this->hasMany(PushSubscriptionWatch::class);
    }
}
