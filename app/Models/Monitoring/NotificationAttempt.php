<?php

namespace App\Models\Monitoring;

class NotificationAttempt extends MonitorModel
{
    protected $table = 'monitor_notification_attempts';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
