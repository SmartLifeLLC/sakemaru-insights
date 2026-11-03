<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\IncidentEvent;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Notification;
use App\Monitoring\Exceptions\InvalidTransition;
use App\Monitoring\Exceptions\VersionConflict;
use App\Monitoring\Services\IncidentService;
use App\Monitoring\Services\ObservationIngestor;
use App\Monitoring\Services\RetentionService;
use Carbon\CarbonImmutable;

/** 仕様書 AC-05/07/08/09/10/11/12/13/14/15/16/18/41/42/43 に対応する振る舞い。 */
class IncidentLifecycleTest extends MonitoringTestCase
{
    private ObservationIngestor $ingestor;

    private IncidentService $incidents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRegistries();
        $this->ingestor = app(ObservationIngestor::class);
        $this->incidents = app(IncidentService::class);
    }

    private function actor(): array
    {
        return ['id' => 1, 'name' => 'tester'];
    }

    public function test_error_creates_incident_and_notification_but_not_acknowledgement(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $this->assertSame('OPEN', $i->workflow_status->value);
        $this->assertSame('ACTIVE', $i->observation_state->value);
        $this->assertSame('MON-000001', $i->public_id);
        $n = Notification::sole();
        $this->assertSame('NEW', $n->kind->value);
        $this->assertSame('PENDING', $n->status->value);
        $this->assertNotNull($i->next_reminder_at);
    }

    public function test_warning_and_info_do_not_create_incidents_or_notifications(): void
    {
        $this->ingestor->ingestInternal([$this->event(['severity' => 'WARNING']), $this->event(['severity' => 'INFO', 'error_code' => 'LOG_INFO'])]);
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, Notification::count());
        $this->assertSame(2, LogEntry::count());
    }

    public function test_resend_with_same_event_id_is_deduplicated_and_conflict_rejected(): void
    {
        $ev = $this->event();
        $this->ingestor->ingestInternal([$ev]);
        $r = $this->ingestor->ingestInternal([$ev]);
        $this->assertSame([$ev['event_id']], $r['duplicate']);
        $ev['summary'] = 'changed';
        $r = $this->ingestor->ingestInternal([$ev]);
        $this->assertSame([$ev['event_id']], $r['conflict']);
        $this->assertSame(1, LogEntry::count());
        $this->assertSame(1, Incident::sole()->occurrence_count);
        $this->assertSame(1, Notification::count());
    }

    public function test_same_fingerprint_aggregates_into_one_incident(): void
    {
        $this->ingestor->ingestInternal([$this->event(), $this->event(['occurrence_delta' => 5]), $this->event()]);
        $this->assertSame(1, Incident::count());
        $this->assertSame(7, Incident::sole()->occurrence_count);
        $this->assertSame(1, Notification::count());
    }

    public function test_version_conflict_returns_error_and_does_not_overwrite(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $this->incidents->act($i, 'acknowledge', $i->version, $this->actor(), [], 'k1');
        $this->expectException(VersionConflict::class);
        $this->incidents->act(Incident::sole(), 'assign', 1, ['id' => 2, 'name' => 'other'], ['assignee_name' => 'other'], 'k2');
    }

    public function test_idempotency_key_prevents_double_apply(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $this->incidents->act($i, 'note', $i->version, $this->actor(), ['reason' => 'memo'], 'same-key');
        $this->incidents->act(Incident::sole(), 'note', 99, $this->actor(), ['reason' => 'memo'], 'same-key');
        $this->assertSame(1, IncidentEvent::query()->where('event_type', 'NOTE')->count());
    }

    public function test_resolve_requires_recovery_and_mandatory_fields_then_close_sets_expiry(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        try {
            $this->incidents->act($i, 'resolve', $i->version, $this->actor(), ['cause_category' => 'x', 'action_taken' => 'y', 'verification' => 'z'], 'r1');
            $this->fail('未復旧で解決できてはいけない');
        } catch (InvalidTransition) {
        }
        $i = $this->incidents->act(Incident::sole(), 'mark_recovered', $i->version, $this->actor(), ['reason' => '再確認済み'], 'r2');
        try {
            $this->incidents->act($i, 'resolve', $i->version, $this->actor(), ['cause_category' => 'x'], 'r3');
            $this->fail('必須項目なしで解決できてはいけない');
        } catch (InvalidTransition) {
        }
        $i = $this->incidents->act(Incident::sole(), 'resolve', $i->version, $this->actor(), ['cause_category' => 'undetermined', 'action_taken' => 'y', 'verification' => 'z'], 'r4');
        $this->assertSame('RESOLVED', $i->workflow_status->value);
        $this->assertNull($i->expires_at);
        $i = $this->incidents->act($i, 'close', $i->version, $this->actor(), [], 'r5');
        $this->assertSame('CLOSED', $i->workflow_status->value);
        $this->assertNotNull($i->expires_at);
    }

    public function test_recurrence_after_resolution_increments_generation_and_notifies(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $i = $this->incidents->act($i, 'mark_recovered', $i->version, $this->actor(), ['reason' => 'ok'], 'a');
        $i = $this->incidents->act($i, 'resolve', $i->version, $this->actor(), ['cause_category' => 'x', 'action_taken' => 'y', 'verification' => 'z'], 'b');
        $i = $this->incidents->act($i, 'close', $i->version, $this->actor(), [], 'c');
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $this->assertSame(2, $i->generation);
        $this->assertSame('OPEN', $i->workflow_status->value);
        $this->assertNull($i->assigned_name);
        $this->assertNull($i->expires_at);
        $this->assertSame(1, Notification::query()->where('kind', 'RECURRENCE')->where('generation', 2)->count());
    }

    public function test_reminders_are_created_once_per_slot_and_cancelled_on_resolve(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $later = CarbonImmutable::now('UTC')->addMinutes(31);
        $this->assertSame(1, $this->incidents->processReminders($later));
        $this->assertSame(1, $this->incidents->processReminders($later->addMinutes(1)) + 1); // 次回は2時間後なので作られない
        $this->assertSame(1, Notification::query()->where('kind', 'REMINDER')->count());
        $this->assertSame(1, $this->incidents->processReminders($later->addHours(2)));
        $this->assertSame(2, Notification::query()->where('kind', 'REMINDER')->count());
        $keys = Notification::query()->pluck('notification_key')->all();
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_critical_reminder_fires_after_10_minutes(): void
    {
        $this->ingestor->ingestInternal([$this->event(['severity' => 'CRITICAL'])]);
        $this->assertSame(0, $this->incidents->processReminders(CarbonImmutable::now('UTC')->addMinutes(9)));
        $this->assertSame(1, $this->incidents->processReminders(CarbonImmutable::now('UTC')->addMinutes(11)));
    }

    public function test_snooze_requires_reason_and_limit_and_renotifies_after_expiry(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        try {
            $this->incidents->act($i, 'snooze', $i->version, $this->actor(), ['hours' => 2], 's1');
            $this->fail('理由なしで抑止できてはいけない');
        } catch (InvalidTransition) {
        }
        try {
            $this->incidents->act($i, 'snooze', $i->version, $this->actor(), ['hours' => 48, 'reason' => 'x'], 's2');
            $this->fail('24時間超は抑止できてはいけない');
        } catch (InvalidTransition) {
        }
        $i = $this->incidents->act($i, 'snooze', $i->version, $this->actor(), ['hours' => 1, 'reason' => '保守中'], 's3');
        $this->assertNotNull($i->snoozed_until);
        $this->assertSame(0, $this->incidents->processReminders(CarbonImmutable::now('UTC')->addMinutes(30)));
        $this->assertSame(1, $this->incidents->processReminders(CarbonImmutable::now('UTC')->addMinutes(61)));
        $this->assertSame(1, Notification::query()->where('kind', 'SUPPRESSION_EXPIRED')->count());
    }

    public function test_stale_healthy_or_incomplete_check_does_not_recover_but_two_complete_healthy_do(): void
    {
        $base = ['source_kind' => 'CHECK', 'check_key' => 'trade.hourly_receive', 'application_code' => 'TRADE', 'business_date' => '2026-10-30', 'scope' => ['business_date' => '2026-10-30', 'target_hour' => 13], 'error_code' => null]; // 復旧条件 2 回連続
        $this->ingestor->ingestInternal([$this->event($base + ['outcome' => 'VIOLATION', 'run_id' => 10, 'summary' => 'mismatch'])]);
        $i = Incident::sole();
        $this->assertSame('ACTIVE', $i->observation_state->value);

        // 古い HEALTHY（run_id 5 < 10）
        $this->ingestor->ingestInternal([$this->event($base + ['severity' => 'INFO', 'outcome' => 'HEALTHY', 'run_id' => 5, 'summary' => 'ok', 'verification_complete' => true])]);
        $this->assertSame('ACTIVE', Incident::sole()->observation_state->value);
        // 未完了の HEALTHY
        $this->ingestor->ingestInternal([$this->event($base + ['severity' => 'INFO', 'outcome' => 'HEALTHY', 'run_id' => 11, 'summary' => 'partial', 'verification_complete' => false])]);
        $this->assertSame('ACTIVE', Incident::sole()->observation_state->value);
        // 完全 HEALTHY 1回目（復旧条件は2回連続）
        $this->ingestor->ingestInternal([$this->event($base + ['severity' => 'INFO', 'outcome' => 'HEALTHY', 'run_id' => 12, 'summary' => 'ok', 'verification_complete' => true])]);
        $this->assertSame('ACTIVE', Incident::sole()->observation_state->value);
        $this->assertSame(1, Incident::sole()->healthy_streak);
        // UNKNOWN は連続正常をリセットし、復旧にしない
        $this->ingestor->ingestInternal([$this->event($base + ['severity' => 'WARNING', 'outcome' => 'UNKNOWN', 'run_id' => 13, 'summary' => 'timeout'])]);
        $this->assertSame('UNKNOWN', Incident::sole()->observation_state->value);
        // 再検知 → ACTIVE
        $this->ingestor->ingestInternal([$this->event($base + ['outcome' => 'VIOLATION', 'run_id' => 14, 'summary' => 'mismatch'])]);
        $this->assertSame('ACTIVE', Incident::sole()->observation_state->value);
        $this->ingestor->ingestInternal([$this->event($base + ['severity' => 'INFO', 'outcome' => 'HEALTHY', 'run_id' => 15, 'summary' => 'ok', 'verification_complete' => true])]);
        $this->ingestor->ingestInternal([$this->event($base + ['severity' => 'INFO', 'outcome' => 'HEALTHY', 'run_id' => 16, 'summary' => 'ok', 'verification_complete' => true])]);
        $i = Incident::sole();
        $this->assertSame('RECOVERED', $i->observation_state->value);
        $this->assertSame('OPEN', $i->workflow_status->value, 'RECOVERED でも人の対応状態は維持');
        $this->assertSame(1, Notification::query()->where('kind', 'RECOVERY')->count());
        $this->assertSame(1, Incident::count(), '同じ指紋で別案件を作らない');
    }

    public function test_log_based_incident_does_not_recover_on_silence(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $this->incidents->processReminders(CarbonImmutable::now('UTC')->addDays(3));
        $this->assertSame('ACTIVE', Incident::sole()->observation_state->value);
        $this->assertSame('OPEN', Incident::sole()->workflow_status->value);
    }

    public function test_retention_deletes_expired_info_logs_but_protects_unresolved_incident_logs(): void
    {
        $this->ingestor->ingestInternal([$this->event(['severity' => 'INFO', 'error_code' => 'LOG_INFO']), $this->event()]);
        $past = CarbonImmutable::now('UTC')->subDay();
        LogEntry::query()->update(['expires_at' => $past]);
        Incident::query()->update(['expires_at' => $past]);
        $deleted = app(RetentionService::class)->run();
        $this->assertSame(1, $deleted['logs_info']);
        $this->assertSame(0, $deleted['logs_error'], '未解決案件に紐付くエラーログは削除しない');
        $this->assertSame(0, $deleted['incidents'], '未解決案件は期限でも削除しない');
        $this->assertSame(1, Incident::count());
    }

    public function test_retention_deletes_closed_incident_but_not_one_that_recurred_before_delete(): void
    {
        $this->ingestor->ingestInternal([$this->event()]);
        $i = Incident::sole();
        $i = $this->incidents->act($i, 'mark_recovered', $i->version, $this->actor(), ['reason' => 'ok'], 'a');
        $i = $this->incidents->act($i, 'resolve', $i->version, $this->actor(), ['cause_category' => 'x', 'action_taken' => 'y', 'verification' => 'z'], 'b');
        $i = $this->incidents->act($i, 'close', $i->version, $this->actor(), [], 'c');
        Incident::query()->update(['expires_at' => CarbonImmutable::now('UTC')->subDay()]);
        // 削除直前に再発
        $this->ingestor->ingestInternal([$this->event()]);
        $deleted = app(RetentionService::class)->run();
        $this->assertSame(0, $deleted['incidents']);
        $this->assertSame(2, Incident::sole()->generation);
    }

    public function test_secrets_are_redacted_everywhere(): void
    {
        $this->ingestor->ingestInternal([$this->event([
            'summary' => 'Authorization: Bearer abc.def password=hunter2 https://hooks.slack.com/services/T000/B000/XXXX user@example.com',
            'evidence' => ['cookie' => 'laravel_session=abc', 'nested' => ['db_password' => 'x'], 'sample' => 'token=zzz'],
        ])]);
        $log = LogEntry::sole();
        $this->assertStringNotContainsString('hunter2', $log->summary);
        $this->assertStringNotContainsString('abc.def', $log->summary);
        $this->assertStringNotContainsString('B000/XXXX', $log->summary);
        $this->assertStringNotContainsString('user@example.com', $log->summary);
        $this->assertSame('[REDACTED]', $log->evidence_json['cookie']);
        $this->assertSame('[REDACTED]', $log->evidence_json['nested']['db_password']);
        $this->assertStringNotContainsString('zzz', $log->evidence_json['sample']);
        $this->assertStringNotContainsString('hunter2', Incident::sole()->title);
    }

    public function test_invalid_events_are_rejected_not_silently_accepted(): void
    {
        $r = $this->ingestor->ingestInternal([
            ['schema_version' => 2] + $this->event(),
            $this->event(['event_id' => 'not-a-uuid']),
            $this->event(['application_code' => 'NOPE']),
            $this->event(['severity' => 'FATAL']),
            $this->event(['summary' => '']),
            $this->event(['source_kind' => 'CHECK']), // check_key/run_id 無し
        ]);
        $this->assertCount(6, $r['rejected']);
        $this->assertSame(['unsupported_schema_version', 'invalid_event_id', 'unknown_application_code', 'invalid_severity', 'summary_required', 'check_fields_required'], array_column($r['rejected'], 'reason'));
        $this->assertSame(0, LogEntry::count());
    }

    public function test_concurrent_identical_fingerprint_creates_single_incident(): void
    {
        // 同一指紋の同時取込を模擬: 1取引内で連続 store
        $this->ingestor->ingestInternal([$this->event(), $this->event(), $this->event()]);
        $this->assertSame(1, Incident::count());
        $this->assertSame(1, Notification::query()->where('kind', 'NEW')->count());
    }
}
