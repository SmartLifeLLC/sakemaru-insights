<?php

namespace App\Models\Monitoring;

use App\Monitoring\Enums\Coverage;

class Site extends MonitorModel
{
    protected $table = 'monitor_sites';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'coverage' => Coverage::class,
        'coverage_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
