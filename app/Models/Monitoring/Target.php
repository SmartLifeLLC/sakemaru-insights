<?php

namespace App\Models\Monitoring;

class Target extends MonitorModel
{
    protected $table = 'monitor_targets';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'params_json' => 'array',
        'thresholds_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
