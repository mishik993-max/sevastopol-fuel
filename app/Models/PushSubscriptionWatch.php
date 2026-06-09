<?php

namespace App\Models;

use App\Enums\FuelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscriptionWatch extends Model
{
    protected $fillable = [
        'push_subscription_id',
        'station_id',
        'fuel_type',
        'notify_available',
        'last_marker_color',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'notify_available' => 'boolean',
            'last_notified_at' => 'datetime',
        ];
    }

    public function pushSubscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
