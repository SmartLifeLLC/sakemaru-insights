<?php

namespace App\Models\Monitoring;

class Check extends MonitorModel
{
    protected $table = 'monitor_checks';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'implemented' => 'bool',
        'config_json' => 'array',
        'rule_json' => 'array',
        'next_due_at' => 'datetime',
        'last_completed_at' => 'datetime',
        'paused_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
