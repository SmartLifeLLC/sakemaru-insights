<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/**
 * core.stats_daily_finalize: 前日分の日次確定（仕様 v2.3）。対象日＝前日として、
 * (1) daily_finalize が対象日で succeeded、(2) 顧客別日次統計に行があるなら部門日次統計にも対象日の行がある、を期限（deadline_time、既定 02:45）までに満たす。
 * 別の日付の成功で代用しない。finalized_at は参考値（華 本番では確定後の再集計で行が作り直され NULL に戻るため、条件にしない）。
 * 指紋は対象日。未解決は判定範囲を外れても同じ対象日で再確認する。
 */
class StatsDailyFinalizeCheck extends BusinessCheck
{
    use CoreJobRuns;

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_daily_finalize', 'CORE', '統計の日次確定', '前日分の統計確定と部門統計が期限までに完了しているか', 2, 900, 900, 'RB-22', false, true, 1, true,
            ['slots' => ['02:00'], 'deadline_time' => '02:45', 'lookback_days' => 3], ['slots', 'deadline_time', 'lookback_days']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $deadlineTime = (string) $ctx->config('deadline_time', '02:45');
        $obs = [];
        $targets = [];
        // 期限を過ぎた前日分（直近 lookback_days 日）
        for ($d = (int) $ctx->config('lookback_days', 3); $d >= 1; $d--) {
            $day = $now->subDays($d)->startOfDay();                     // 対象日（前日・前々日…）
            $deadline = CarbonImmutable::parse($day->addDay()->toDateString().' '.$deadlineTime, 'Asia/Tokyo'); // 翌日の期限
            if ($deadline->lessThanOrEqualTo($now)) {
                $targets[$day->toDateString()] = $deadline;
            }
        }
        foreach ($this->trackedScopes('core.stats_daily_finalize') as $scope) {
            if (! empty($scope['business_date']) && ! isset($targets[$scope['business_date']])) {
                $targets[$scope['business_date']] = CarbonImmutable::parse($scope['business_date'], 'Asia/Tokyo')->addDay()->setTimeFromTimeString($deadlineTime);
            }
        }
        foreach ($targets as $target => $deadline) {
            $scope = ['business_date' => $target];
            $fin = $src->select("SELECT status, COUNT(*) AS c, MAX(SUBSTR(error_message, 1, 300)) AS err, MAX(finished_at) AS last FROM stats_import_runs WHERE job_type = 'daily_finalize' AND period_from = ? AND period_to = ? GROUP BY status", [$target, $target]);
            $ok = null;
            $failed = null;
            foreach ($fin as $r) {
                if ($r->status === 'succeeded') {
                    $ok = $r;
                } elseif ($r->status === 'failed') {
                    $failed = $r;
                }
            }
            $missing = [];
            if (! $ok) {
                $missing[] = '確定処理（daily_finalize）の成功';
            }
            $buyer = (int) ($src->select('SELECT COUNT(*) AS c FROM stats_daily_buyer_trade_profits WHERE stats_date = ? AND finalized_at IS NOT NULL', [$target])[0]->c ?? 0);
            $buyerAny = $buyer > 0 ? $buyer : (int) ($src->select('SELECT COUNT(*) AS c FROM stats_daily_buyer_trade_profits WHERE stats_date = ?', [$target])[0]->c ?? 0);
            $dept = (int) ($src->select('SELECT COUNT(*) AS c FROM stats_daily_department_trade_profits WHERE stats_date = ?', [$target])[0]->c ?? 0);
            if ($buyerAny > 0 && $dept === 0) {
                $missing[] = '部門日次統計の行';
            }
            $metrics = ['buyer_rows' => $buyerAny, 'buyer_finalized' => $buyer, 'department_rows' => $dept];
            if ($failed && ! $ok) {
                $obs[] = Observation::violation("前日分（{$target}）の日次確定が失敗しています: ".($failed->err ?: '詳細なし'), $scope, $target, $metrics, ['error' => $failed->err], 'STATS_FINALIZE_FAILED');
            } elseif ($missing) {
                $obs[] = Observation::violation("前日分（{$target}）の日次確定が {$deadline->format('m/d H:i')} までに完了していません（未完了: ".implode('・', $missing).'）。別の日付の成功では解消しません。', $scope, $target, $metrics, [], 'STATS_FINALIZE_MISSING');
            } else {
                $obs[] = Observation::healthy("前日分（{$target}）の日次確定は完了（顧客別 {$buyer} 行・部門 {$dept} 行）。", $scope, $target, $metrics);
            }
        }
        if (! $obs) {
            $obs[] = Observation::notDue('本日の日次確定の期限（'.$deadlineTime.'）前です。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
