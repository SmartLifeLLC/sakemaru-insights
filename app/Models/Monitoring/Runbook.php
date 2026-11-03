<?php

namespace App\Models\Monitoring;

class Runbook extends MonitorModel
{
    protected $table = 'monitor_runbooks';

    public $timestamps = true;

    protected $casts = [
        'enabled' => 'bool',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
