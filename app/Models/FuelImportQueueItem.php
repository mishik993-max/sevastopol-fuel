<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelImportQueueItem extends Model
{
    protected $table = 'fuel_import_queue';

    protected $fillable = [
        'fingerprint',
        'network',
        'name_hint',
        'address_hint',
        'raw',
        'fuels',
        'source_message',
    ];

    protected function casts(): array
    {
        return [
            'fuels' => 'array',
        ];
    }
}
