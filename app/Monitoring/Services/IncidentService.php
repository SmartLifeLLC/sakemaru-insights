<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\IncidentEvent;
use App\Models\Monitoring\Notification;
use App\Monitoring\Enums\NotificationKind;
use App\Monitoring\Enums\ObservationState;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Exceptions\InvalidTransition;
use App\Monitoring\Exceptions\VersionConflict;
use App\Monitoring\Support\CanonicalJson;
use App\Monitoring\Support\Fingerprint;
use App\Monitoring\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * 同一エラーの集約・再発・確認・担当・保留・復旧・解決（仕様書 第10章）。
 * 呼出し側（Ingestor / Filament アクション）が短い取引で囲む。外部通信はしない。
 */
class IncidentService
{
    public function __construct(
        private readonly NotificationQueue $queue,
        private readonly AuditService $audit,
    ) {}

    // ------------------------------------------------------------------
    // 自動（検知・再検査）
    // ------------------------------------------------------------------

    /**
     * ERROR 以上 / VIOLATION の観測を記録し、インシデントを作成または更新する。
     *
     * @param  array<string,mixed>  $attrs  site_id, application_code, check_id?, check_key?, error_code?, scope, business_date?, node_code?, severity, title, evidence, metrics, occurred_at, occurrence_delta, source_sequence?, runbook_key?, include_date(bool)
     * @return array{incident: Incident, created: bool, changed: string}
     */
    public function recordActive(array $attrs): array
    {
        $now = CarbonImmutable::now('UTC');
        $severity = $attrs['severity'] instanceof Severity ? $attrs['severity'] : Severity::from($attrs['severity']);
        $scope = Fingerprint::normalizeScope((array) ($attrs['scope'] ?? []));
        $businessDate = ($attrs['include_date'] ?? true) ? ($attrs['business_date'] ?? null) : null;
        $fingerprint = Fingerprint::compute(
            $attrs['application_code'], $attrs['check_key'] ?? null, $attrs['error_code'] ?? null,
            $scope, $businessDate, $attrs['node_scoped'] ?? false ? ($attrs['node_code'] ?? null) : null,
        );
        $occurredAt = CarbonImmutable::parse($attrs['occurred_at'] ?? $now)->utc();
        $delta = max(1, (int) ($attrs['occurrence_delta'] ?? 1));
        $seq = isset($attrs['source_sequence']) ? (int) $attrs['source_sequence'] : null;

        $incident = Incident::query()->where('fingerprint', $fingerprint)->first();

        if (! $incident) {
            try {
                $incident = Incident::query()->create([
                    'public_id' => 'TMP-'.Str::random(10),
                    'site_id' => $attrs['site_id'],
                    'check_id' => $attrs['check_id'] ?? null,
                    'check_key' => $attrs['check_key'] ?? null,
                    'fingerprint' => $fingerprint,
                    'error_code' => $attrs['error_code'] ?? null,
                    'severity' => $severity,
                    'title' => mb_substr((string) $attrs['title'], 0, 200),
                    'scope_json' => $scope,
                    'node_code' => $attrs['node_code'] ?? null,
                    'business_date' => $attrs['business_date'] ?? null,
                    'observation_state' => ObservationState::ACTIVE,
                    'workflow_status' => WorkflowStatus::OPEN,
                    'generation' => 1,
                    'first_seen_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    'occurrence_count' => $delta,
                    'last_source_sequence' => $seq,
                    'runbook_key' => $attrs['runbook_key'] ?? null,
                    'runbook_version' => $attrs['runbook_version'] ?? null,
                    'evidence_snapshot_json' => $this->snapshot($attrs),
                    'version' => 1,
                ]);
            } catch (QueryException $e) {
                // 同一指紋の同時作成競合: 既存行を読み直して同一案件へ統合（AC-15）
                $incident = Incident::query()->where('fingerprint', $fingerprint)->first();
                if (! $incident) {
                    throw $e;
                }

                return $this->recordActive($attrs);
            }
            $incident->public_id = PublicId::incident((int) $incident->id);
            $incident->next_reminder_at = $this->firstReminderAt($severity, $now);
            $incident->save();
            $this->event($incident, 'DETECTED', null, '初回検知', null, ['severity' => $severity->value, 'count' => $delta], 'detect:'.$fingerprint.':g1');
            $this->queue->enqueue($incident, NotificationKind::NEW);

            return ['incident' => $incident, 'created' => true, 'changed' => 'created'];
        }

        // 順序逆転: 古い世代の観測は現在状態へ適用しない（件数だけ加算）
        if ($seq !== null && $incident->last_source_sequence !== null && $seq < (int) $incident->last_source_sequence) {
            $incident->occurrence_count += $delta;
            $incident->save();

            return ['incident' => $incident, 'created' => false, 'changed' => 'stale_ignored'];
        }

        $changed = 'updated';
        $before = ['workflow_status' => $incident->workflow_status->value, 'observation_state' => $incident->observation_state->value, 'generation' => $incident->generation, 'severity' => $incident->severity->value];

        if (! $incident->workflow_status->isOpenish()) {
            // 解決後の再発: 世代を増やし、担当・確認を再要求（AC-09）
            $incident->generation += 1;
            $incident->workflow_status = WorkflowStatus::OPEN;
            $incident->observation_state = ObservationState::ACTIVE;
            $incident->assigned_user_id = null;
            $incident->assigned_name = null;
            $incident->acknowledged_at = null;
            $incident->acknowledged_user_id = null;
            $incident->acknowledged_name = null;
            $incident->recovered_at = null;
            $incident->resolved_at = null;
            $incident->closed_at = null;
            $incident->expires_at = null;
            $incident->snoozed_until = null;
            $incident->suppression_reason = null;
            $incident->reminder_slot = 0;
            $incident->healthy_streak = 0;
            $incident->resolution_json = null;
            $incident->severity = $severity;
            $incident->next_reminder_at = $this->firstReminderAt($severity, $now);
            $this->clearChildExpiry($incident);
            $changed = 'recurrence';
        } else {
            if ($incident->observation_state !== ObservationState::ACTIVE) {
                $incident->observation_state = ObservationState::ACTIVE;
                $incident->recovered_at = null;
                $changed = 'reactivated';
            }
            if ($severity->rank() > $incident->severity->rank()) {
                $incident->severity = $severity;
                $incident->next_reminder_at = $this->firstReminderAt($severity, $now);
                $changed = 'escalated';
            }
            $incident->healthy_streak = 0;
        }
        $incident->last_seen_at = $occurredAt->greaterThan($incident->last_seen_at) ? $occurredAt : $incident->last_seen_at;
        $incident->occurrence_count += $delta;
        if ($seq !== null) {
            $incident->last_source_sequence = $seq;
        }
        if (! empty($attrs['evidence']) || ! empty($attrs['metrics'])) {
            $incident->evidence_snapshot_json = $this->snapshot($attrs);
        }
        if (! empty($attrs['title']) && $changed !== 'updated') {
            $incident->title = mb_substr((string) $attrs['title'], 0, 200);
        }
        $incident->version += 1;
        $incident->save();

        $after = ['workflow_status' => $incident->workflow_status->value, 'observation_state' => $incident->observation_state->value, 'generation' => $incident->generation, 'severity' => $incident->severity->value];
        match ($changed) {
            'recurrence' => $this->event($incident, 'RECURRED', null, '解決後に再発', $before, $after, 'recur:'.$fingerprint.':g'.$incident->generation),
            'reactivated' => $this->event($incident, 'REACTIVATED', null, '復旧後に再検知', $before, $after, 'react:'.$fingerprint.':g'.$incident->generation.':'.$now->getTimestamp()),
            'escalated' => $this->event($incident, 'ESCALATED', null, '重大度が上昇', $before, $after, 'esc:'.$fingerprint.':g'.$incident->generation.':'.$severity->value),
            default => null,
        };
        match ($changed) {
            'recurrence' => $this->queue->enqueue($incident, NotificationKind::RECURRENCE),
            'escalated' => $this->queue->enqueue($incident, NotificationKind::ESCALATION),
            'reactivated' => $this->queue->enqueue($incident, NotificationKind::RECURRENCE, 'react'.$now->getTimestamp()),
            default => null,
        };

        return ['incident' => $incident, 'created' => false, 'changed' => $changed];
    }

    /**
     * 完全な正常確認を記録する。連続正常回数が復旧条件に達したら RECOVERED。
     * 遅着した古い結果・未完了の検査では復旧しない（AC-11 / AC-12）。
     */
    public function recordHealthy(string $applicationCode, string $checkKey, array $scope, ?string $businessDate, bool $includeDate, int $requiredStreak, ?int $sourceSequence, CarbonImmutable $verifiedAt, bool $verificationComplete): ?Incident
    {
        if (! $verificationComplete) {
            return null;
        }
        $fp = Fingerprint::compute($applicationCode, $checkKey, null, Fingerprint::normalizeScope($scope), $includeDate ? $businessDate : null);
        $incident = Incident::query()->where('fingerprint', $fp)->first();
        if (! $incident) {
            return null;
        }
        if ($sourceSequence !== null && $incident->last_source_sequence !== null && $sourceSequence < (int) $incident->last_source_sequence) {
            return $incident; // 古い HEALTHY
        }
        if ($incident->observation_state === ObservationState::RECOVERED || ! $incident->workflow_status->isOpenish() && $incident->observation_state !== ObservationState::ACTIVE) {
            $incident->last_verified_at = $verifiedAt;
            $incident->save();

            return $incident;
        }
        $incident->healthy_streak += 1;
        $incident->last_verified_at = $verifiedAt;
        if ($sourceSequence !== null) {
            $incident->last_source_sequence = $sourceSequence;
        }
        if ($incident->healthy_streak >= max(1, $requiredStreak)) {
            $before = ['observation_state' => $incident->observation_state->value];
            $incident->observation_state = ObservationState::RECOVERED;
            $incident->recovered_at = $verifiedAt;
            $incident->next_reminder_at = null;
            $incident->version += 1;
            $incident->save();
            $this->event($incident, 'RECOVERED', null, '完全な再検査で正常を確認（連続'.$incident->healthy_streak.'回）', $before, ['observation_state' => 'RECOVERED'], 'recov:'.$fp.':g'.$incident->generation);
            $this->queue->enqueue($incident, NotificationKind::RECOVERY);
        } else {
            $incident->save();
        }

        return $incident;
    }

    /** 検査不能。既存 ACTIVE を HEALTHY に戻さず、観測状態を UNKNOWN にする。 */
    public function recordUnknown(string $applicationCode, string $checkKey, array $scope, ?string $businessDate, bool $includeDate, CarbonImmutable $at): ?Incident
    {
        $fp = Fingerprint::compute($applicationCode, $checkKey, null, Fingerprint::normalizeScope($scope), $includeDate ? $businessDate : null);
        $incident = Incident::query()->where('fingerprint', $fp)->first();
        if (! $incident || ! $incident->workflow_status->isOpenish() || $incident->observation_state !== ObservationState::ACTIVE) {
            return $incident;
        }
        $incident->observation_state = ObservationState::UNKNOWN;
        $incident->healthy_streak = 0;
        $incident->version += 1;
        $incident->save();
        $this->event($incident, 'UNKNOWN', null, '検査不能のため現在状態を確認できない', ['observation_state' => 'ACTIVE'], ['observation_state' => 'UNKNOWN'], 'unk:'.$fp.':g'.$incident->generation.':'.$at->format('YmdHi'));

        return $incident;
    }

    // ------------------------------------------------------------------
    // 人の操作（楽観ロック・冪等キー必須）
    // ------------------------------------------------------------------

    /**
     * @param  array{id:int|null,name:string}  $actor
     * @param  array<string,mixed>  $input
     */
    public function act(Incident $incident, string $action, int $expectedVersion, array $actor, array $input, string $idempotencyKey): Incident
    {
        if (IncidentEvent::query()->where('idempotency_key', hash('sha256', $idempotencyKey))->exists()) {
            return $incident->refresh();
        }
        if ((int) $incident->version !== $expectedVersion) {
            throw new VersionConflict('他の担当者が先に更新しました。画面を再読込みしてください。');
        }
        $now = CarbonImmutable::now('UTC');
        $before = $this->stateOf($incident);
        $reason = isset($input['reason']) ? mb_substr((string) $input['reason'], 0, 1000) : null;

        switch ($action) {
            case 'acknowledge':
                $this->assertOpenish($incident);
                if ($incident->workflow_status === WorkflowStatus::OPEN) {
                    $incident->workflow_status = WorkflowStatus::ACKNOWLEDGED;
                }
                $incident->acknowledged_at = $now;
                $incident->acknowledged_user_id = $actor['id'];
                $incident->acknowledged_name = $actor['name'];
                $incident->reminder_slot = 0;
                $incident->next_reminder_at = $this->nextReminderAt($incident, $now);
                break;

            case 'assign':
                $this->assertOpenish($incident);
                $incident->assigned_user_id = $input['assignee_id'] ?? $actor['id'];
                $incident->assigned_name = mb_substr((string) ($input['assignee_name'] ?? $actor['name']), 0, 100);
                break;

            case 'start':
                $this->assertOpenish($incident);
                if (! $incident->assigned_user_id && ! $incident->assigned_name) {
                    $incident->assigned_user_id = $actor['id'];
                    $incident->assigned_name = $actor['name'];
                }
                if ($incident->acknowledged_at === null) {
                    $incident->acknowledged_at = $now;
                    $incident->acknowledged_user_id = $actor['id'];
                    $incident->acknowledged_name = $actor['name'];
                }
                $incident->workflow_status = WorkflowStatus::IN_PROGRESS;
                $incident->reminder_slot = 0;
                $incident->next_reminder_at = $this->nextReminderAt($incident, $now);
                break;

            case 'note':
                if ($reason === null || $reason === '') {
                    throw new InvalidTransition('メモを入力してください。');
                }
                break;

            case 'snooze':
                $this->assertOpenish($incident);
                $hours = (float) ($input['hours'] ?? 0);
                $max = (float) config('monitoring.notification.max_suppression_hours', 24);
                if ($hours <= 0 || $hours > $max) {
                    throw new InvalidTransition("抑止時間は 0 より大きく {$max} 時間以内で指定してください。");
                }
                if ($reason === null || $reason === '') {
                    throw new InvalidTransition('抑止理由は必須です。');
                }
                $incident->snoozed_until = $now->addMinutes((int) round($hours * 60));
                $incident->suppression_reason = $reason;
                $incident->next_reminder_at = $incident->snoozed_until;
                $this->queue->cancelPending($incident, 'snoozed');
                break;

            case 'unsnooze':
                $incident->snoozed_until = null;
                $incident->suppression_reason = null;
                $incident->next_reminder_at = $this->nextReminderAt($incident, $now);
                break;

            case 'mark_recovered':
                // ログ由来など HEALTHY イベントが存在しない案件の人手による復旧確認
                $this->assertOpenish($incident);
                if ($reason === null || $reason === '') {
                    throw new InvalidTransition('再確認した内容を記録してください。');
                }
                $incident->observation_state = ObservationState::RECOVERED;
                $incident->recovered_at = $now;
                $incident->last_verified_at = $now;
                $incident->next_reminder_at = null;
                break;

            case 'resolve':
                $this->assertOpenish($incident);
                if ($incident->observation_state !== ObservationState::RECOVERED) {
                    throw new InvalidTransition('復旧が確認されていない案件は解決にできません。先に「復旧確認」を記録してください。');
                }
                foreach (['cause_category', 'action_taken', 'verification'] as $k) {
                    if (empty($input[$k])) {
                        throw new InvalidTransition('原因分類・実施内容・検証結果は必須です。');
                    }
                }
                $incident->resolution_json = [
                    'cause_category' => mb_substr((string) $input['cause_category'], 0, 64),
                    'action_taken' => mb_substr((string) $input['action_taken'], 0, 2000),
                    'verification' => mb_substr((string) $input['verification'], 0, 2000),
                    'resolved_by' => $actor['name'],
                ];
                $incident->workflow_status = WorkflowStatus::RESOLVED;
                $incident->resolved_at = $now;
                $incident->next_reminder_at = null;
                $this->queue->cancelPending($incident, 'resolved');
                break;

            case 'close':
                if ($incident->workflow_status !== WorkflowStatus::RESOLVED) {
                    throw new InvalidTransition('解決済みの案件だけを終了できます。');
                }
                if ($incident->observation_state !== ObservationState::RECOVERED) {
                    throw new InvalidTransition('未復旧の案件は終了できません。');
                }
                $incident->workflow_status = WorkflowStatus::CLOSED;
                $incident->closed_at = $now;
                $days = (int) config('monitoring.retention.resolved_incident_days', 365);
                $incident->expires_at = $now->addDays($days);
                $this->setChildExpiry($incident, $incident->expires_at);
                break;

            case 'reopen':
                if ($incident->workflow_status->isOpenish()) {
                    throw new InvalidTransition('未解決の案件です。');
                }
                $incident->workflow_status = WorkflowStatus::ACKNOWLEDGED;
                $incident->resolved_at = null;
                $incident->closed_at = null;
                $incident->expires_at = null;
                $this->clearChildExpiry($incident);
                break;

            default:
                throw new InvalidTransition("不明な操作です: {$action}");
        }

        $incident->version += 1;
        $incident->save();
        $after = $this->stateOf($incident);
        $this->event($incident, strtoupper($action), $actor, $reason, $before, $after === $before ? null : $after, $idempotencyKey);
        $this->audit->record($actor, 'incident.'.$action, 'incident', $incident->public_id, $before, $after, $reason);

        return $incident;
    }

    // ------------------------------------------------------------------
    // 再通知
    // ------------------------------------------------------------------

    /** 期限到来した未確認・対応中の継続通知と抑止期限切れを作成する。 */
    public function processReminders(CarbonImmutable $now): int
    {
        $count = 0;
        $rows = Incident::query()
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', $now)
            ->orderBy('next_reminder_at')
            ->limit(200)
            ->get();
        foreach ($rows as $incident) {
            if ($incident->observation_state !== ObservationState::ACTIVE) {
                $incident->next_reminder_at = null;
                $incident->save();

                continue;
            }
            if ($incident->snoozed_until && $incident->snoozed_until->lessThanOrEqualTo($now)) {
                $incident->snoozed_until = null;
                $incident->suppression_reason = null;
                $incident->reminder_slot += 1;
                $incident->next_reminder_at = $this->nextReminderAt($incident, $now);
                $incident->save();
                $this->queue->enqueue($incident, NotificationKind::SUPPRESSION_EXPIRED, (string) $incident->reminder_slot);
                $this->event($incident, 'SUPPRESSION_EXPIRED', null, '抑止期限が到来し、エラーが継続中', null, null, 'supexp:'.$incident->id.':g'.$incident->generation.':'.$incident->reminder_slot);
                $count++;

                continue;
            }
            if ($incident->snoozed_until) {
                continue;
            }
            $incident->reminder_slot += 1;
            $incident->next_reminder_at = $this->nextReminderAt($incident, $now);
            $incident->save();
            $this->queue->enqueue($incident, NotificationKind::REMINDER, (string) $incident->reminder_slot);
            $count++;
        }

        return $count;
    }

    public function firstReminderAt(Severity $severity, CarbonImmutable $now): ?CarbonImmutable
    {
        $cfg = $this->reminderConfig($severity);

        return $cfg ? $now->addSeconds((int) $cfg['unacknowledged_first']) : null;
    }

    public function nextReminderAt(Incident $incident, CarbonImmutable $now): ?CarbonImmutable
    {
        $cfg = $this->reminderConfig($incident->severity);
        if (! $cfg || ! $incident->workflow_status->isOpenish()) {
            return null;
        }
        $sec = match ($incident->workflow_status) {
            WorkflowStatus::OPEN => $incident->reminder_slot === 0 ? $cfg['unacknowledged_first'] : $cfg['unacknowledged_repeat'],
            default => $cfg['in_progress_repeat'],
        };

        return $now->addSeconds((int) $sec);
    }

    /** @return array{unacknowledged_first:int,unacknowledged_repeat:int,in_progress_repeat:int}|null */
    private function reminderConfig(Severity $severity): ?array
    {
        $cfg = config('monitoring.notification.reminder.'.$severity->value);

        return is_array($cfg) ? $cfg : null;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function assertOpenish(Incident $incident): void
    {
        if (! $incident->workflow_status->isOpenish()) {
            throw new InvalidTransition('解決済み・終了済みの案件です。再発時は自動で再オープンされます。');
        }
    }

    /** @return array<string,mixed> */
    private function stateOf(Incident $i): array
    {
        return [
            'workflow_status' => $i->workflow_status->value,
            'observation_state' => $i->observation_state->value,
            'assigned_name' => $i->assigned_name,
            'snoozed_until' => $i->snoozed_until ? CarbonImmutable::instance($i->snoozed_until)->setTimezone('Asia/Tokyo')->format('Y-m-d H:i').' JST' : null,
            'generation' => $i->generation,
        ];
    }

    /** @param array<string,mixed>|null $actor */
    public function event(Incident $incident, string $type, ?array $actor, ?string $reason, ?array $before, ?array $after, string $idempotencyKey): ?IncidentEvent
    {
        try {
            return IncidentEvent::query()->create([
                'incident_id' => $incident->id,
                'generation' => $incident->generation,
                'event_type' => $type,
                'actor_user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['name'] ?? null,
                'created_at' => CarbonImmutable::now('UTC'),
                'reason' => $reason,
                'before_json' => $before,
                'after_json' => $after,
                'idempotency_key' => hash('sha256', $idempotencyKey),
                'expires_at' => $incident->expires_at,
            ]);
        } catch (QueryException) {
            return null; // 冪等キー重複
        }
    }

    private function snapshot(array $attrs): array
    {
        $snap = [
            'metrics' => (array) ($attrs['metrics'] ?? []),
            'evidence' => (array) ($attrs['evidence'] ?? []),
            'summary' => (string) ($attrs['title'] ?? ''),
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
        $json = CanonicalJson::encode($snap);
        $max = (int) config('monitoring.ingest.evidence_max_bytes', 16384);
        if (strlen($json) > $max) {
            $snap['evidence'] = ['_truncated' => true, 'note' => '証拠が上限を超えたため要約のみ保存'];
        }

        return $snap;
    }

    private function setChildExpiry(Incident $incident, \DateTimeInterface $at): void
    {
        IncidentEvent::query()->where('incident_id', $incident->id)->update(['expires_at' => $at]);
        Notification::query()->where('incident_id', $incident->id)->whereIn('status', ['DELIVERED', 'CANCELLED'])->update(['expires_at' => $at]);
    }

    private function clearChildExpiry(Incident $incident): void
    {
        IncidentEvent::query()->where('incident_id', $incident->id)->update(['expires_at' => null]);
        Notification::query()->where('incident_id', $incident->id)->update(['expires_at' => null]);
    }

    /**
     * 監視システム由来（MONITOR_SYSTEM）の案件の自動復旧。チェックの HEALTHY が無い種類（休止・配送不可など）は、原因が解消した時点で呼ぶ。
     * 同じ error_code・scope の指紋で未復旧の案件を RECOVERED にし、復旧通知を積む。該当が無ければ何もしない。
     */
    public function recordSelfRecovered(string $errorCode, array $scope, string $reason, ?string $nodeCode = null): ?Incident
    {
        $fp = Fingerprint::compute('INSIGHTS', null, $errorCode, Fingerprint::normalizeScope($scope), null, $nodeCode);
        $incident = Incident::query()->where('fingerprint', $fp)->first();
        if (! $incident || $incident->observation_state === ObservationState::RECOVERED || ! $incident->workflow_status->isOpenish()) {
            return $incident;
        }
        $now = CarbonImmutable::now('UTC');
        $before = ['observation_state' => $incident->observation_state->value];
        $incident->observation_state = ObservationState::RECOVERED;
        $incident->recovered_at = $now;
        $incident->last_verified_at = $now;
        $incident->next_reminder_at = null;
        $incident->version += 1;
        $incident->save();
        $this->event($incident, 'RECOVERED', null, $reason, $before, ['observation_state' => 'RECOVERED'], 'recov:'.$fp.':g'.$incident->generation);
        $this->queue->enqueue($incident, NotificationKind::RECOVERY);

        return $incident;
    }
}
