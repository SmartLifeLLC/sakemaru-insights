<?php

namespace App\Models\Monitoring;

class Node extends MonitorModel
{
    protected $table = 'monitor_nodes';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'os_info_json' => 'array',
        'thresholds_json' => 'array',
        'latest_json' => 'array',
        'last_heartbeat_at' => 'datetime',
        'last_metrics_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
