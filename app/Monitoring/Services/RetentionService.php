<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\AuditEvent;
use App\Models\Monitoring\CheckAttempt;
use App\Models\Monitoring\CheckRun;
use App\Models\Monitoring\Counter;
use App\Models\Monitoring\DeliveryBatch;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\IncidentEvent;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Notification;
use App\Models\Monitoring\NotificationAttempt;
use App\Models\Monitoring\RecheckRequest;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 期限到来データの小分け削除（仕様書 第15章）。
 * - 索引 (retention_class, expires_at, id) から最大500行だけ選び、条件を再確認して DELETE。
 * - 未解決インシデントに紐付くログ、未送信・BLOCKED 通知、未解決案件は削除しない。
 * - 1起動の上限（行数・秒）を超えたら次回へ持ち越し、24時間以上遅延したら RETENTION_BACKLOG を作る。
 */
class RetentionService
{
    public function __construct(private readonly SelfMonitor $selfMonitor) {}

    /** @return array<string,int> table => deleted rows */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $cfg = config('monitoring.retention');
        $batch = (int) $cfg['delete_batch_rows'];
        $maxRows = (int) $cfg['delete_max_rows_per_run'];
        $maxSec = (int) $cfg['delete_max_seconds_per_run'];
        $pauseUs = (int) $cfg['delete_pause_ms'] * 1000;
        $deadline = microtime(true) + $maxSec;
        $deleted = [];
        $total = 0;

        $targets = $this->targets($now);
        foreach ($targets as $name => $builderFactory) {
            $deleted[$name] = 0;
            while ($total < $maxRows && microtime(true) < $deadline) {
                /** @var Builder $q */
                $q = $builderFactory();
                $ids = (clone $q)->orderBy('id')->limit($batch)->pluck('id')->all();
                if (! $ids) {
                    break;
                }
                // 選択ID と期限条件を再確認して削除（削除直前の再発・未解決化と競合しない）
                $n = DB::connection('monitor_store')->transaction(fn () => (clone $q)->whereIn('id', $ids)->delete(), 2);
                $deleted[$name] += $n;
                $total += $n;
                if ($n < count($ids)) {
                    break;
                }
                usleep($pauseUs);
            }
        }

        $this->detectBacklog($now);

        return $deleted;
    }

    /** @return array<string,callable():Builder> */
    private function targets(CarbonImmutable $now): array
    {
        $unresolved = fn () => Incident::query()->select('id')->whereIn('workflow_status', WorkflowStatus::unresolvedValues());

        return [
            'logs_info' => fn () => LogEntry::query()->where('retention_class', 'INFO')->where('expires_at', '<=', $now)
                ->where(fn ($q) => $q->whereNull('incident_id')->orWhereNotIn('incident_id', $unresolved())),
            'logs_warning' => fn () => LogEntry::query()->where('retention_class', 'WARNING')->where('expires_at', '<=', $now)
                ->where(fn ($q) => $q->whereNull('incident_id')->orWhereNotIn('incident_id', $unresolved())),
            'logs_error' => fn () => LogEntry::query()->where('retention_class', 'ERROR')->where('expires_at', '<=', $now)
                ->where(fn ($q) => $q->whereNull('incident_id')->orWhereNotIn('incident_id', $unresolved())),
            'check_attempts' => fn () => CheckAttempt::query()->where('expires_at', '<=', $now),
            'check_runs' => fn () => CheckRun::query()->where('expires_at', '<=', $now),
            'notification_attempts' => fn () => NotificationAttempt::query()->where('expires_at', '<=', $now),
            'notifications' => fn () => Notification::query()->whereIn('status', ['DELIVERED', 'CANCELLED', 'UNANSWERED', 'BLOCKED'])->where('expires_at', '<=', $now),
            'delivery_batches' => fn () => DeliveryBatch::query()->where('expires_at', '<=', $now),
            'incident_events' => fn () => IncidentEvent::query()->where('expires_at', '<=', $now)
                ->whereNotIn('incident_id', $unresolved()),
            'incidents' => fn () => Incident::query()->where('workflow_status', WorkflowStatus::CLOSED->value)->where('expires_at', '<=', $now),
            'rechecks' => fn () => RecheckRequest::query()->where('expires_at', '<=', $now),
            'audit' => fn () => AuditEvent::query()->where('expires_at', '<=', $now),
            'probe_results' => fn () => \App\Models\Monitoring\ProbeResult::query()->where('expires_at', '<=', $now),
            'server_metrics' => fn () => \App\Models\Monitoring\ServerMetric::query()->where('expires_at', '<=', $now),
            'call_attempts' => fn () => \App\Models\Monitoring\CallAttempt::query()->where('expires_at', '<=', $now),
            'counters' => fn () => Counter::query()->where('bucket_start', '<', $now->subDays((int) config('monitoring.retention.counter_days', 365))),
        ];
    }

    private function detectBacklog(CarbonImmutable $now): void
    {
        $grace = (int) config('monitoring.retention.backlog_grace_hours', 24);
        $limit = $now->subHours($grace);
        $oldest = LogEntry::query()->where('retention_class', 'INFO')->where('expires_at', '<=', $limit)->orderBy('expires_at')->first(['expires_at']);
        if ($oldest) {
            $lag = CarbonImmutable::instance($oldest->expires_at)->diffInHours($now);
            $this->selfMonitor->record('RETENTION_BACKLOG', Severity::ERROR,
                "正常ログの削除が約{$lag}時間遅延しています。予算内で追い付かないため、保持設定か実行予算の見直しが必要です。",
                ['class' => 'INFO'], [], ['lag_hours' => $lag], 'RB-10');
        }
    }
}
