<?php

namespace App\Models\Monitoring;

class IncidentEvent extends MonitorModel
{
    protected $table = 'monitor_incident_events';

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'before_json' => 'array',
        'after_json' => 'array',
    ];
}
