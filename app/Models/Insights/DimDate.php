<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DimDate extends Model
{
    protected $table = 'dim_date';

    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'date',
        'year',
        'month',
        'day',
        'weekday',
        'week_of_year',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
