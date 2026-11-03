<?php

namespace App\Models\Monitoring;

class Setting extends MonitorModel
{
    protected $table = 'monitor_settings';

    protected $casts = [
        'value_json' => 'array',
        'expires_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
