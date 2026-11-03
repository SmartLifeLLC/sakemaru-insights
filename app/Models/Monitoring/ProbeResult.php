<?php

namespace App\Models\Monitoring;

class ProbeResult extends MonitorModel
{
    protected $table = 'monitor_probe_results';

    protected $casts = [
        'ok' => 'bool',
        'checked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
