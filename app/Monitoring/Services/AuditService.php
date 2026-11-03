<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\AuditEvent;
use Carbon\CarbonImmutable;

class AuditService
{
    /** @param array{id:int|null,name:string}|null $actor */
    public function record(?array $actor, string $action, string $targetType, string $targetId, ?array $before, ?array $after, ?string $reason = null): AuditEvent
    {
        $now = CarbonImmutable::now('UTC');

        return AuditEvent::query()->create([
            'actor_user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['name'] ?? 'system',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_json' => $before,
            'after_json' => $after,
            'reason' => $reason ? mb_substr($reason, 0, 1000) : null,
            'created_at' => $now,
            'expires_at' => $now->addDays((int) config('monitoring.retention.audit_days', 365)),
        ]);
    }
}
