<?php

namespace App\Models;

use App\Enums\CanisterPolicy;
use App\Enums\FillVolume;
use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Report extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'station_id',
        'fuel_type',
        'status',
        'statuses',
        'queue_size',
        'sale_types',
        'fill_volume',
        'canister_policy',
        'comment',
        'photo_path',
        'is_confirmation',
        'confirms_report_id',
        'reporter_hash',
        'is_hidden',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'status' => FuelStatus::class,
            'statuses' => 'array',
            'queue_size' => QueueSize::class,
            'sale_types' => 'array',
            'fill_volume' => FillVolume::class,
            'canister_policy' => CanisterPolicy::class,
            'is_confirmation' => 'boolean',
            'is_hidden' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @param  Builder<Report>  $query */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function photoUrl(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
