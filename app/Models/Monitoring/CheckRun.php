<?php

namespace App\Models\Monitoring;

class CheckRun extends MonitorModel
{
    protected $table = 'monitor_check_runs';

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
        'checkpoint_json' => 'array',
        'verification_complete' => 'bool',
    ];
}
