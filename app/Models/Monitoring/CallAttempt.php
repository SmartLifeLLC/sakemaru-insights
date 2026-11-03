<?php

namespace App\Models\Monitoring;

class CallAttempt extends MonitorModel
{
    protected $table = 'monitor_call_attempts';

    protected $casts = [
        'token_expires_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
