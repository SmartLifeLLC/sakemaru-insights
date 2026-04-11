<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DimStore extends Model
{
    protected $table = 'dim_store';

    protected $fillable = [
        'store_code',
        'store_name',
        'area',
        'region',
        'latitude',
        'longitude',
        'postal_code',
        'has_pos',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'has_pos' => 'boolean',
    ];
}
