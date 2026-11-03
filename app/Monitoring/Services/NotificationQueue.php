<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Notification;
use App\Monitoring\Enums\NotificationKind;
use App\Monitoring\Enums\NotificationStatus;
use App\Monitoring\Enums\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * 保存してから送る方式（仕様書 13.4）。
 * 通知待ちは incident_id + generation + kind + slot の一意キーで重複作成を防ぐ。
 */
class NotificationQueue
{
    public const ROUTE_MAIL = 'mail';

    public const ROUTE_PHONE = 'phone';

    public function __construct(private readonly NotificationSettings $settings, private readonly CounterService $counters) {}

    /**
     * Slack（既定経路）に加えて、条件を満たせばメール・電話の通知待ちも作る（v2 第7.1）。
     * 経路ごとに notification_key を分けるので、どれかの失敗が他の経路を止めない。
     */
    public function enqueue(Incident $incident, NotificationKind $kind, string $slot = '0', ?string $routeKey = null): ?Notification
    {
        $n = $this->enqueueRoute($incident, $kind, $slot, $routeKey);
        if ($routeKey === null && $n) {
            if ($this->mailWanted($incident, $kind)) {
                $this->enqueueRoute($incident, $kind, $slot, self::ROUTE_MAIL);
            }
            if ($this->phoneWanted($incident, $kind, CarbonImmutable::now('UTC'))) {
                $this->enqueueRoute($incident, $kind, $slot, self::ROUTE_PHONE);
            }
        }

        return $n;
    }

    public function mailWanted(Incident $incident, NotificationKind $kind): bool
    {
        $m = $this->settings->mail();
        if (! ($m['enabled'] ?? false) || ! $incident->severity->isErrorOrAbove() && $kind !== NotificationKind::RECOVERY) {
            return false;
        }

        return in_array($kind->value, (array) ($m['kinds'] ?? []), true)
            || (in_array($kind, [NotificationKind::REMINDER, NotificationKind::SUPPRESSION_EXPIRED], true) && ! empty($m['include_reminder']))
            || ($kind === NotificationKind::RECOVERY && ! empty($m['include_recovery']));
    }

    public function phoneWanted(Incident $incident, NotificationKind $kind, CarbonImmutable $now): bool
    {
        $p = $this->settings->phone();

        if (! ($p['enabled'] ?? false)
            || ! in_array($kind->value, (array) ($p['kinds'] ?? []), true)
            || ! in_array($incident->severity->value, (array) ($p['severities'] ?? []), true)
            || ! $this->settings->phoneAllowedHere()
            || ! $this->settings->inPhoneWindow($now)
            || ! \App\Models\Monitoring\Contact::query()->where('enabled', true)->exists()) {
            return false;
        }
        // 同じ案件は per_incident_cooldown_hours に 1 回まで（再発・再検知で何度も電話しない）
        $hours = (int) ($p['per_incident_cooldown_hours'] ?? 12);
        if ($hours > 0 && Notification::query()->where('incident_id', $incident->id)->where('route_key', self::ROUTE_PHONE)
            ->where('created_at', '>=', $now->subHours($hours))->exists()) {
            return false;
        }

        return true;
    }

    /** Slack が配送不可・長時間未送信のときの代替メール（既にメールがあれば何もしない） */
    public function ensureMailFallback(Notification $slack): ?Notification
    {
        if (! $slack->incident_id || in_array($slack->route_key, [self::ROUTE_MAIL, self::ROUTE_PHONE], true) || ! ($this->settings->mail()['enabled'] ?? false)) {
            return null;
        }
        $key = $slack->notification_key.':'.self::ROUTE_MAIL;
        if (Notification::query()->where('notification_key', $key)->exists()) {
            return null;
        }
        $incident = Incident::query()->find($slack->incident_id);

        return $incident ? $this->enqueueRoute($incident, $slack->kind, explode(':', $slack->notification_key)[3] ?? '0', self::ROUTE_MAIL, true) : null;
    }

    private function enqueueRoute(Incident $incident, NotificationKind $kind, string $slot, ?string $routeKey, bool $fallback = false): ?Notification
    {
        // INFO / WARNING は通知しない（AC-04）
        if (! $incident->severity->isErrorOrAbove() && $kind !== NotificationKind::RECOVERY) {
            return null;
        }
        if ($incident->snoozed_until && $incident->snoozed_until->isFuture() && ! in_array($kind, [NotificationKind::RECOVERY, NotificationKind::SUPPRESSION_EXPIRED], true)) {
            return null; // 抑止中。期限到来時に継続していれば SUPPRESSION_EXPIRED で再作成
        }
        $channel = in_array($routeKey, [self::ROUTE_MAIL, self::ROUTE_PHONE], true) ? $routeKey : 'slack';
        $routeKey ??= (string) config('monitoring.notification.default_route', 'error_channel');
        $key = implode(':', [$incident->id, $incident->generation, $kind->value, $slot]).($channel === 'slack' ? '' : ':'.$channel);
        $now = CarbonImmutable::now('UTC');
        try {
            $created = Notification::query()->create([
                'incident_id' => $incident->id,
                'generation' => $incident->generation,
                'notification_key' => $key,
                'kind' => $kind,
                'route_key' => $routeKey,
                'channel' => $channel,
                'severity' => $incident->severity->value,
                'payload_snapshot_json' => [
                    'public_id' => $incident->public_id,
                    'title' => $incident->title,
                    'severity' => $incident->severity->value,
                    'business_date' => $incident->business_date?->format('Y-m-d'),
                    'scope' => $incident->scope_json,
                    'metrics' => $incident->evidence_snapshot_json['metrics'] ?? [],
                    'occurrence_count' => $incident->occurrence_count,
                    'workflow_status' => $incident->workflow_status->value,
                    'assigned_name' => $incident->assigned_name,
                    'fallback' => $fallback ?: null,
                ],
                'status' => NotificationStatus::PENDING,
                // 電話は猶予（delay_minutes）の後に判定する。その間に復旧・確認されれば発信しない
                'available_at' => $channel === self::ROUTE_PHONE ? $now->addMinutes((int) ($this->settings->phone()['delay_minutes'] ?? 0)) : $now,
                'created_at' => $now,
            ]);
            $this->counters->add((int) $incident->site_id, CounterService::KEY_NOTIFICATION_HOURLY, $now, ['queued' => 1, $channel.'_queued' => 1]);
            $this->counters->flush();

            return $created;
        } catch (QueryException) {
            return Notification::query()->where('notification_key', $key)->first();
        }
    }

    /** システム通知（インシデント無し。テスト送信など） */
    public function enqueueSystem(NotificationKind $kind, string $key, array $payload, ?string $routeKey = null, Severity $severity = Severity::ERROR): ?Notification
    {
        $routeKey ??= (string) config('monitoring.notification.default_route', 'error_channel');
        $now = CarbonImmutable::now('UTC');
        try {
            return Notification::query()->create([
                'incident_id' => null,
                'generation' => 1,
                'notification_key' => 'sys:'.$key,
                'kind' => $kind,
                'route_key' => $routeKey,
                'severity' => $severity->value,
                'payload_snapshot_json' => $payload,
                'status' => NotificationStatus::PENDING,
                'available_at' => $now,
                'created_at' => $now,
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    public function cancelPending(Incident $incident, string $reason): int
    {
        return Notification::query()
            ->where('incident_id', $incident->id)
            ->whereIn('status', [NotificationStatus::PENDING->value, NotificationStatus::RETRY_WAIT->value])
            ->whereIn('kind', [NotificationKind::REMINDER->value, NotificationKind::SUPPRESSION_EXPIRED->value])
            ->update(['status' => NotificationStatus::CANCELLED->value, 'cancel_reason' => $reason]);
    }
}
