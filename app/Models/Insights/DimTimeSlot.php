<?php

namespace App\Models\Insights;

use Illuminate\Database\Eloquent\Model;

class DimTimeSlot extends Model
{
    protected $table = 'dim_time_slot';

    public $timestamps = false;

    protected $fillable = [
        'time_slot',
        'label',
    ];
}
