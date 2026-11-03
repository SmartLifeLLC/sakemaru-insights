<?php

namespace App\Models\Monitoring;

class ServerMetric extends MonitorModel
{
    protected $table = 'monitor_server_metrics';

    protected $casts = [
        'bucket_start' => 'datetime',
        'expires_at' => 'datetime',
        'disk_json' => 'array',
        'io_json' => 'array',
        'net_json' => 'array',
        'extra_json' => 'array',
    ];
}
