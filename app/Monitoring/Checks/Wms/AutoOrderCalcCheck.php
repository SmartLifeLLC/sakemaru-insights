<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.auto_order_calc: 発注計算ジョブ（wms_auto_order_job_controls: ORDER_CALC / SALES_BASED_CALC 等）。
 * 処理種別ごとに「最新ジョブの状態」で判定する: FAILED → ERROR、RUNNING 60 分超 → ERROR、PENDING 30 分超 → WARNING、SUCCESS → 正常。
 */
class AutoOrderCalcCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.auto_order_calc', 'WMS', '発注計算ジョブ', '発注計算ジョブの失敗・滞留', 1, 300, 300, 'RB-11', false, false, 1, true,
            ['running_minutes' => 60, 'pending_minutes' => 30, 'lookback_hours' => 24], ['running_minutes', 'pending_minutes', 'lookback_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $rows = $src->select('SELECT j.id, j.process_name, j.status, j.warehouse_id, j.target_date, j.started_at, j.finished_at, j.created_at, SUBSTR(j.error_details, 1, 400) AS err
            FROM wms_auto_order_job_controls j
            JOIN (SELECT process_name, MAX(id) AS max_id FROM wms_auto_order_job_controls WHERE created_at >= ? GROUP BY process_name) m ON m.max_id = j.id', [$since]);
        $now = $ctx->nowLocal();
        $runMin = (int) $ctx->config('running_minutes', 60);
        $pendMin = (int) $ctx->config('pending_minutes', 30);
        $obs = [];
        foreach ($rows as $r) {
            $scope = ['process' => $r->process_name];
            $ev = ['job_id' => (int) $r->id, 'warehouse_id' => $r->warehouse_id, 'target_date' => $r->target_date, 'status' => $r->status];
            $started = $r->started_at ? \Carbon\CarbonImmutable::parse($r->started_at, 'Asia/Tokyo') : null;
            $created = \Carbon\CarbonImmutable::parse($r->created_at, 'Asia/Tokyo');
            if ($r->status === 'FAILED') {
                $obs[] = Observation::violation("発注計算 {$r->process_name} が失敗しました（job #{$r->id}）。", $scope, null, [], $ev + ['error' => $r->err], 'AUTO_ORDER_CALC_FAILED');
            } elseif ($r->status === 'RUNNING' && $started && $started->diffInMinutes($now) > $runMin) {
                $obs[] = Observation::violation("発注計算 {$r->process_name} が {$runMin} 分以上実行中のままです（job #{$r->id}）。", $scope, null, ['running_minutes' => (int) $started->diffInMinutes($now)], $ev, 'AUTO_ORDER_CALC_STUCK');
            } elseif ($r->status === 'PENDING' && $created->diffInMinutes($now) > $pendMin) {
                $obs[] = Observation::warning("発注計算 {$r->process_name} が {$pendMin} 分以上待機中です（job #{$r->id}）。", $scope, null, [], $ev, 'AUTO_ORDER_CALC_PENDING');
            } else {
                $obs[] = Observation::healthy("発注計算 {$r->process_name} の最新ジョブは {$r->status}。", $scope, null, ['job_id' => (int) $r->id]);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('直近の発注計算ジョブはありません。', ['process' => 'none'])]);
    }
}
