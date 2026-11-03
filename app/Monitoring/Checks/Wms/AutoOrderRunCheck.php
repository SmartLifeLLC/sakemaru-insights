<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.auto_order_run: 自動発注の定時実行（wms_auto_order_runs, slot_key = "YYYY-MM-DD:HH:MM"）。
 * 有効倉庫があるのに slot 時刻＋猶予までに COMPLETED が無い → ERROR。failed_count>0・一部倉庫未完了 → WARNING。preview は除外。
 */
class AutoOrderRunCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.auto_order_run', 'WMS', '自動発注の定時実行', '有効倉庫の自動発注が予定時刻＋猶予までに完了しているか', 1, 900, 900, 'RB-11', false, true, 1, true,
            ['slots' => ['01:00'], 'grace_minutes' => 90], ['slots', 'grace_minutes'], '予定枠は本番実績（01:00）から仮置き。正式スケジュール確認後に slots を確定');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $enabled = (int) $src->scalar('SELECT COUNT(*) FROM wms_warehouse_auto_order_settings WHERE is_auto_order_enabled = 1', [], 0);
        if ($enabled === 0) {
            return CheckResult::single(Observation::healthy('自動発注が有効な倉庫はありません（対象なし）。', ['slot' => 'none']));
        }
        $slots = (array) $ctx->config('slots', ['01:00']);
        $grace = (int) $ctx->config('grace_minutes', 90);
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $key = $slot['date'].':'.$slot['time'];
            $rows = $src->select('SELECT status, COUNT(*) AS c, COUNT(DISTINCT warehouse_id) AS wh, SUM(failed_count) AS failed, SUM(generated_count) AS generated_total, SUM(held_count) AS held FROM wms_auto_order_runs WHERE slot_key = ? AND is_preview = 0 GROUP BY status', [$key]);
            $by = [];
            $completedWh = 0;
            $failed = 0;
            $generated = 0;
            foreach ($rows as $r) {
                $by[$r->status] = (int) $r->c;
                if ($r->status === 'COMPLETED') {
                    $completedWh = (int) $r->wh;
                }
                $failed += (int) $r->failed;
                $generated += (int) $r->generated_total;
            }
            $scope = ['business_date' => $slot['date'], 'slot' => $slot['time']];
            $metrics = ['enabled_warehouses' => $enabled, 'completed_warehouses' => $completedWh, 'generated' => $generated, 'failed' => $failed] + $by;
            if ($completedWh === 0) {
                $obs[] = Observation::violation("自動発注 {$slot['date']} {$slot['time']} が予定＋{$grace}分までに完了していません（".($by ? json_encode($by, JSON_UNESCAPED_UNICODE) : '実行記録なし').'）。', $scope, $slot['date'], $metrics, [], 'AUTO_ORDER_NOT_COMPLETED');
            } elseif ($failed > 0 || $completedWh < $enabled) {
                $obs[] = Observation::warning("自動発注 {$slot['date']} {$slot['time']} は完了したが、失敗 {$failed} 件・完了倉庫 {$completedWh}/{$enabled}。", $scope, $slot['date'], $metrics, [], 'AUTO_ORDER_PARTIAL');
            } else {
                $obs[] = Observation::healthy("自動発注 {$slot['date']} {$slot['time']} は {$completedWh} 倉庫で完了（発注 {$generated} 件）。", $scope, $slot['date'], $metrics);
            }
        }
        if (! $obs) {
            $next = $this->nextSlot($ctx, $slots, $grace);
            $obs[] = Observation::notDue('次の判定は '.($next ? $next['date'].' '.$next['time'].'＋'.$grace.'分' : '—').'。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
