<?php

namespace App\Models\Monitoring;

class AuditEvent extends MonitorModel
{
    protected $table = 'monitor_audit_events';

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
