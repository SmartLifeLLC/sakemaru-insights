<?php

namespace App\Models\Monitoring;

class DeliveryBatch extends MonitorModel
{
    protected $table = 'monitor_delivery_batches';

    protected $casts = [
        'notification_ids_json' => 'array',
        'payload_snapshot_json' => 'array',
        'created_at' => 'datetime',
        'delivered_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
