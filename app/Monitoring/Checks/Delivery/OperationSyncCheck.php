<?php

namespace App\Monitoring\Checks\Delivery;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * delivery.operation_sync: 配送端末 → 飛脚サーバーの同期（dlv_operations.status）。
 * FAILED_TO_SYNC → ERROR。IN_PROGRESS_SYNC 30 分超 → ERROR。READY_TO_SYNC / WAIT_SYNC 60 分超 → WARNING。
 * 前日以前に作成され FINISHED でない運行が判定時刻（既定 06:00）を過ぎて残る → WARNING（本番では通常 0 件）。
 */
class OperationSyncCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('delivery.operation_sync', 'DELIVERY', '配送端末の同期', '配送端末から飛脚サーバーへの同期の失敗・滞留', 1, 300, 300, 'RB-17', false, false, 2, true,
            ['waiting_minutes' => 60, 'in_progress_minutes' => 30, 'unfinished_check_time' => '06:00', 'unfinished_lookback_days' => 14], ['waiting_minutes', 'in_progress_minutes', 'unfinished_check_time', 'unfinished_lookback_days']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $obs = [];

        $failed = $src->select("SELECT id, user_id, app_version, updated_at FROM dlv_operations WHERE status = 'FAILED_TO_SYNC' AND deleted_at IS NULL AND updated_at >= ? ORDER BY id DESC LIMIT 50", [$this->jst($ctx->nowUtc->subDays(2))]);
        $obs[] = $failed
            ? Observation::violation('配送端末の同期失敗（FAILED_TO_SYNC）が '.count($failed).' 件あります。', ['state' => 'failed_to_sync'], null, ['failed' => count($failed)], ['operations' => $this->sample($failed)], 'DELIVERY_SYNC_FAILED')
            : Observation::healthy('同期失敗の運行はありません。', ['state' => 'failed_to_sync']);

        $stuck = $src->select("SELECT id, user_id, updated_at FROM dlv_operations WHERE status = 'IN_PROGRESS_SYNC' AND deleted_at IS NULL AND updated_at < ? ORDER BY id LIMIT 50", [$this->jst($ctx->nowUtc->subMinutes((int) $ctx->config('in_progress_minutes', 30)))]);
        $obs[] = $stuck
            ? Observation::violation('同期処理中のまま止まっている運行が '.count($stuck).' 件あります。', ['state' => 'in_progress_stuck'], null, ['stuck' => count($stuck)], ['operations' => $this->sample($stuck)], 'DELIVERY_SYNC_STUCK')
            : Observation::healthy('同期処理中で止まっている運行はありません。', ['state' => 'in_progress_stuck']);

        $waiting = $src->select("SELECT id, status, user_id, updated_at FROM dlv_operations WHERE status IN ('READY_TO_SYNC', 'WAIT_SYNC') AND deleted_at IS NULL AND updated_at < ? ORDER BY id LIMIT 50", [$this->jst($ctx->nowUtc->subMinutes((int) $ctx->config('waiting_minutes', 60)))]);
        $obs[] = $waiting
            ? Observation::warning('同期待ちのまま '.(int) $ctx->config('waiting_minutes', 60).' 分を過ぎた運行が '.count($waiting).' 件あります。', ['state' => 'waiting'], null, ['waiting' => count($waiting)], ['operations' => $this->sample($waiting)], 'DELIVERY_SYNC_WAITING')
            : Observation::healthy('長時間の同期待ちはありません。', ['state' => 'waiting']);

        $checkAt = \Carbon\CarbonImmutable::parse($now->toDateString().' '.$ctx->config('unfinished_check_time', '06:00'), 'Asia/Tokyo');
        if ($now->greaterThanOrEqualTo($checkAt)) {
            $date = $now->subDay()->toDateString();
            $unfinished = $src->select("SELECT id, status, user_id FROM dlv_operations WHERE status <> 'FINISHED' AND deleted_at IS NULL AND created_at >= ? AND created_at < ? ORDER BY id LIMIT 50", [$now->subDays((int) $ctx->config('unfinished_lookback_days', 14))->startOfDay()->format('Y-m-d H:i:s'), $now->startOfDay()->format('Y-m-d H:i:s')]);
            $obs[] = $unfinished
                ? Observation::warning('前日以前の運行で完了していないものが '.count($unfinished).' 件あります。', ['state' => 'unfinished', 'business_date' => $date], $date, ['unfinished' => count($unfinished)], ['operations' => $this->sample($unfinished)], 'DELIVERY_UNFINISHED')
                : Observation::healthy('前日以前の未完了運行はありません。', ['state' => 'unfinished', 'business_date' => $date], $date);
        }

        return new CheckResult($obs);
    }
}
