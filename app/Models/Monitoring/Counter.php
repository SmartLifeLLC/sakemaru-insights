<?php

namespace App\Models\Monitoring;

class Counter extends MonitorModel
{
    protected $table = 'monitor_counters';

    protected $casts = [
        'counts_json' => 'array',
        'bucket_start' => 'datetime',
        'calculated_at' => 'datetime',
    ];
}
