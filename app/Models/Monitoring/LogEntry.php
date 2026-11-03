<?php

namespace App\Models\Monitoring;

use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\SourceKind;

class LogEntry extends MonitorModel
{
    protected $table = 'monitor_logs';

    protected $casts = [
        'severity' => Severity::class,
        'source_kind' => SourceKind::class,
        'business_date' => 'date:Y-m-d',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'expires_at' => 'datetime',
        'metrics_json' => 'array',
        'evidence_json' => 'array',
    ];
}
