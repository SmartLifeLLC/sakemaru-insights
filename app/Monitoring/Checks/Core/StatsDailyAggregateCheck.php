<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.stats_daily_aggregate: 日次統計集計（stats_import_runs, source_system=core_earning）の定時実行。
 * 本番実績の枠: 02:00, 07:00, 08:10, 09:00, 12:00, 16:00, 19:00。各枠＋猶予内に daily_aggregate（02:00 以外は daily_department_sync も）が succeeded → 正常。
 */
class StatsDailyAggregateCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_daily_aggregate', 'CORE', '統計の定時集計', '定時枠ごとに日次統計集計が成功しているか', 1, 600, 600, 'RB-14', false, true, 1, true,
            ['slots' => ['02:00', '07:00', '08:10', '09:00', '12:00', '16:00', '19:00'], 'grace_minutes' => 30, 'department_sync_except' => ['02:00']],
            ['slots', 'grace_minutes', 'department_sync_except'], '予定枠は本番実績（10/27〜11/03）から仮置き');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $slots = (array) $ctx->config('slots', []);
        $grace = (int) $ctx->config('grace_minutes', 30);
        $except = (array) $ctx->config('department_sync_except', ['02:00']);
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $from = $slot['start']->subMinutes(5)->format('Y-m-d H:i:s');
            $to = $slot['deadline']->format('Y-m-d H:i:s');
            $rows = $src->select("SELECT job_type, status, COUNT(*) AS c, MAX(SUBSTR(error_message, 1, 300)) AS err FROM stats_import_runs WHERE source_system = 'core_earning' AND job_type IN ('daily_aggregate', 'daily_department_sync') AND started_at >= ? AND started_at < ? GROUP BY job_type, status", [$from, $to]);
            $ok = [];
            $failed = [];
            $err = null;
            foreach ($rows as $r) {
                if ($r->status === 'succeeded') {
                    $ok[$r->job_type] = (int) $r->c;
                } elseif ($r->status === 'failed') {
                    $failed[$r->job_type] = (int) $r->c;
                    $err = $err ?: $r->err;
                }
            }
            $required = in_array($slot['time'], $except, true) ? ['daily_aggregate'] : ['daily_aggregate', 'daily_department_sync'];
            $missing = array_values(array_diff($required, array_keys($ok)));
            $scope = ['business_date' => $slot['date'], 'slot' => $slot['time']];
            $metrics = ['succeeded' => array_sum($ok), 'failed' => array_sum($failed)];
            if ($failed) {
                $obs[] = Observation::violation("統計集計 {$slot['date']} {$slot['time']} で失敗があります（".implode(', ', array_keys($failed)).'）。', $scope, $slot['date'], $metrics, ['error' => $err], 'STATS_AGGREGATE_FAILED');
            } elseif ($missing) {
                $obs[] = Observation::violation("統計集計 {$slot['date']} {$slot['time']} が予定＋{$grace}分までに完了していません（未完了: ".implode(', ', $missing).'）。', $scope, $slot['date'], $metrics, [], 'STATS_AGGREGATE_MISSING');
            } else {
                $obs[] = Observation::healthy("統計集計 {$slot['date']} {$slot['time']} は完了（".array_sum($ok).' 件）。', $scope, $slot['date'], $metrics);
            }
        }
        if (! $obs) {
            $next = $this->nextSlot($ctx, $slots, $grace);
            $obs[] = Observation::notDue('次の判定は '.($next ? $next['date'].' '.$next['time'].'＋'.$grace.'分' : '—').'。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
