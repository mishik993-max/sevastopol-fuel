<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = [
        'external_id',
        'source',
        'name',
        'network',
        'address',
        'latitude',
        'longitude',
        'is_active',
        'closed_at',
        'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function closureReports(): HasMany
    {
        return $this->hasMany(StationClosureReport::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(StationCorrection::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
