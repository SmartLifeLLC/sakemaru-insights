<?php

namespace App\Models\Monitoring;

use App\Monitoring\Enums\NotificationKind;
use App\Monitoring\Enums\NotificationStatus;

class Notification extends MonitorModel
{
    protected $table = 'monitor_notifications';

    protected $casts = [
        'status' => NotificationStatus::class,
        'kind' => NotificationKind::class,
        'payload_snapshot_json' => 'array',
        'available_at' => 'datetime',
        'created_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'delivered_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
