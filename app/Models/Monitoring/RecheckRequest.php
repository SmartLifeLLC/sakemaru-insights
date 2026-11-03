<?php

namespace App\Models\Monitoring;

class RecheckRequest extends MonitorModel
{
    protected $table = 'monitor_recheck_requests';

    protected $casts = [
        'scope_json' => 'array',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
