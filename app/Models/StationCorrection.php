<?php

namespace App\Models;

use App\Enums\CorrectionField;
use App\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StationCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'station_id',
        'field',
        'proposed_name',
        'proposed_address',
        'proposed_latitude',
        'proposed_longitude',
        'status',
        'proposer_hash',
        'created_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'field' => CorrectionField::class,
            'status' => CorrectionStatus::class,
            'proposed_latitude' => 'float',
            'proposed_longitude' => 'float',
            'created_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(StationCorrectionReport::class, 'correction_id');
    }
}
