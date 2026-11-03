<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/** trade.transfer_pending: 店舗同期/受信の PENDING・BEFORE_RUNNING が15分超、RUNNING が2時間超でない。日付を含めず種別を scope にする。 */
class TransferPendingCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.transfer_pending', 'TRADE', '送受信リクエストの滞留', 'PENDING/BEFORE_RUNNING 15分超、RUNNING 2時間超の滞留がないか', 1, 300, 300, 'RB-04', false, false, 2, true, ['pending_minutes' => 15, 'running_minutes' => 120], ['pending_minutes', 'running_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $pm = (int) $ctx->config('pending_minutes', 15);
        $rm = (int) $ctx->config('running_minutes', 120);
        $obs = [];
        $targets = [
            'store_sync' => ['table' => 'ret_tm_store_sync_requests', 'time' => 'scheduled_at', 'run_time' => 'executed_at', 'extra' => ' AND deleted_at IS NULL'],
            'receive' => ['table' => 'ret_tm_receive_requests', 'time' => 'COALESCE(scheduled_at, created_at)', 'run_time' => 'started_at', 'extra' => ''],
        ];
        foreach ($targets as $kind => $t) {
            $scope = ['kind' => $kind];
            $pending = $src->select("SELECT id, execution_status, {$t['time']} AS t FROM {$t['table']} WHERE execution_status IN ('PENDING','BEFORE_RUNNING') AND {$t['time']} < ?{$t['extra']} ORDER BY id LIMIT 20", [$now->subMinutes($pm)->format('Y-m-d H:i:s')]);
            $running = $src->select("SELECT id, execution_status, {$t['run_time']} AS t FROM {$t['table']} WHERE execution_status = 'RUNNING' AND COALESCE({$t['run_time']}, {$t['time']}) < ?{$t['extra']} ORDER BY id LIMIT 20", [$now->subMinutes($rm)->format('Y-m-d H:i:s')]);
            if ($pending || $running) {
                $obs[] = Observation::violation(sprintf('%s: 待機 %d分超 %d件 / 実行中 %d分超 %d件が滞留しています。', $kind, $pm, count($pending), $rm, count($running)), $scope, null,
                    ['pending' => count($pending), 'running' => count($running)], ['pending' => $this->sample($pending), 'running' => $this->sample($running)], 'TRANSFER_PENDING');
            } else {
                $obs[] = Observation::healthy("{$kind}: 滞留なし。", $scope);
            }
        }

        return new CheckResult($obs);
    }
}
