<?php

namespace App\Models\Monitoring;

class Secret extends MonitorModel
{
    protected $table = 'monitor_secrets';

    protected $hidden = ['value_encrypted'];

    protected $casts = [
        'updated_at' => 'datetime',
    ];
}
