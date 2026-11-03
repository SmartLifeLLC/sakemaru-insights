<?php

namespace App\Models\Monitoring;

use App\Monitoring\Enums\ObservationState;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\WorkflowStatus;

class Incident extends MonitorModel
{
    protected $table = 'monitor_incidents';

    public $timestamps = true;

    protected $casts = [
        'severity' => Severity::class,
        'observation_state' => ObservationState::class,
        'workflow_status' => WorkflowStatus::class,
        'scope_json' => 'array',
        'evidence_snapshot_json' => 'array',
        'resolution_json' => 'array',
        'business_date' => 'date:Y-m-d',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'recovered_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'next_reminder_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'last_delivered_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isUnresolved(): bool
    {
        return $this->workflow_status->isOpenish();
    }
}
