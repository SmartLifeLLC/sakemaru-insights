<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.stats_daily_finalize: 日次確定（daily_finalize、02:00–02:15）と部門同期（daily_department_sync/mixed、02:20）。
 * 02:45（設定）までに両方 succeeded が無い → ERROR。
 */
class StatsDailyFinalizeCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_daily_finalize', 'CORE', '統計の日次確定', '前営業日の統計確定と部門同期が完了しているか', 1, 900, 900, 'RB-14', false, true, 1, true,
            ['slots' => ['02:00'], 'grace_minutes' => 45], ['slots', 'grace_minutes'], '予定枠は本番実績から仮置き（02:00 開始、02:20 部門同期）');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $slots = (array) $ctx->config('slots', ['02:00']);
        $grace = (int) $ctx->config('grace_minutes', 45);
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $rows = $src->select("SELECT job_type, source_system, status, COUNT(*) AS c, MAX(SUBSTR(error_message, 1, 300)) AS err FROM stats_import_runs WHERE ((job_type = 'daily_finalize') OR (job_type = 'daily_department_sync' AND source_system = 'mixed')) AND started_at >= ? AND started_at < ? GROUP BY job_type, source_system, status",
                [$slot['start']->subMinutes(5)->format('Y-m-d H:i:s'), $slot['deadline']->format('Y-m-d H:i:s')]);
            $ok = [];
            $failed = [];
            $err = null;
            foreach ($rows as $r) {
                $k = $r->job_type === 'daily_finalize' ? 'finalize' : 'department_sync';
                if ($r->status === 'succeeded') {
                    $ok[$k] = true;
                } elseif ($r->status === 'failed') {
                    $failed[$k] = true;
                    $err = $err ?: $r->err;
                }
            }
            $target = \Carbon\CarbonImmutable::parse($slot['date'])->subDay()->toDateString();
            $scope = ['business_date' => $target];
            if ($failed) {
                $obs[] = Observation::violation("統計の日次確定（対象 {$target}）で失敗があります（".implode(', ', array_keys($failed)).'）。', $scope, $target, [], ['error' => $err], 'STATS_FINALIZE_FAILED');
            } elseif (! isset($ok['finalize'], $ok['department_sync'])) {
                $missing = array_diff(['finalize', 'department_sync'], array_keys($ok));
                $obs[] = Observation::violation("統計の日次確定（対象 {$target}）が {$slot['deadline']->format('H:i')} までに完了していません（未完了: ".implode(', ', $missing).'）。', $scope, $target, [], [], 'STATS_FINALIZE_MISSING');
            } else {
                $obs[] = Observation::healthy("統計の日次確定（対象 {$target}）は完了。", $scope, $target);
            }
        }
        if (! $obs) {
            $obs[] = Observation::notDue('本日の日次確定の判定時刻前です。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
