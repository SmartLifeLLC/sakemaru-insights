<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\Monitor\ActiveScopes;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\SourceReader;
use Carbon\CarbonImmutable;

/**
 * 勘定衆の定時処理の実行記録（core_scheduled_job_runs。仕様 v2.3 第2章）を読む共通部品。
 * 業務データは変更しない。判定範囲（直近 26 時間）を外れても、未解決案件の対象は再確認を続ける（継続追跡）。
 */
trait CoreJobRuns
{
    use ActiveScopes;

    public const JOB_LABELS = [
        'stats.refresh_updated' => '更新分の再集計',
        'stats.finalize_daily' => '前日分の日次確定',
        'stats.sync_departments' => '部門統計の同期',
        'stats.refresh_monthly' => '月次売上統計の再集計',
    ];

    /** @return array<int,object> その予定枠の親処理（id 昇順） */
    protected function runsForSlot(SourceReader $src, string $jobName, string $slotKey): array
    {
        return $src->select('SELECT id, status, skip_reason, node, started_at, finished_at, exit_code, target_count, target_dates_json, options_json, result_json, SUBSTR(error_message, 1, 300) AS err FROM core_scheduled_job_runs WHERE job_name = ? AND slot_key = ? ORDER BY id', [$jobName, $slotKey]);
    }

    /** @return array<int,object> 直近 lookback 時間に開始した親処理 */
    protected function recentRuns(SourceReader $src, string $jobName, CarbonImmutable $sinceLocal): array
    {
        return $src->select('SELECT id, slot_key, status, skip_reason, node, started_at, finished_at, target_count, target_dates_json, options_json, result_json, SUBSTR(error_message, 1, 300) AS err FROM core_scheduled_job_runs WHERE job_name = ? AND started_at >= ? ORDER BY id', [$jobName, $sinceLocal->format('Y-m-d H:i:s')]);
    }

    /** 未解決案件の scope 一覧（継続追跡用） @return array<int,array<string,mixed>> */
    protected function trackedScopes(string $checkKey): array
    {
        $out = [];
        foreach (\App\Models\Monitoring\Incident::query()->where('check_key', $checkKey)->where('observation_state', \App\Monitoring\Enums\ObservationState::ACTIVE->value)->limit(200)->pluck('scope_json') as $scope) {
            $out[] = is_array($scope) ? $scope : (array) json_decode((string) $scope, true);
        }

        return $out;
    }

    protected function jobLabel(string $jobName): string
    {
        return self::JOB_LABELS[$jobName] ?? $jobName;
    }

    /** JST の naive 日時文字列 → CarbonImmutable(JST) */
    protected function localTime(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, 'Asia/Tokyo') : null;
    }

    protected function isTableMissing(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), '1146');
    }

    protected function notInstalled(CheckContext $ctx): \App\Monitoring\Services\CheckResult
    {
        return \App\Monitoring\Services\CheckResult::single(\App\Monitoring\Services\Observation::unknown('勘定衆側の実行記録（core_scheduled_job_runs / core_scheduler_heartbeats）が未導入のため確認できません。Core のリリース（feature/scheduled-job-runs）後に判定を開始します。', ['resource' => 'core_scheduled_job_runs'], null, [], 'CORE_JOB_RECORDS_NOT_INSTALLED'));
    }
}
