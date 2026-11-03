<?php

namespace App\Models\Monitoring;

class CheckAttempt extends MonitorModel
{
    protected $table = 'monitor_check_attempts';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
        'checkpoint_json' => 'array',
    ];
}
