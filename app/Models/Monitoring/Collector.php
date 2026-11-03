<?php

namespace App\Models\Monitoring;

class Collector extends MonitorModel
{
    protected $table = 'monitor_collectors';

    public $timestamps = true;

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
        'state_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
