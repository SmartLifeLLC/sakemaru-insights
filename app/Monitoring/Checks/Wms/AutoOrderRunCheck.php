<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/**
 * wms.auto_order_run: 自動発注の定時実行（wms_auto_order_runs, slot_key = "YYYY-MM-DD:HH:MM"）。
 * 有効倉庫があるのに slot 時刻＋猶予までに COMPLETED が無い → ERROR。failed_count>0・一部倉庫未完了 → WARNING。preview は除外。
 */
class AutoOrderRunCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.auto_order_run', 'WMS', '自動発注の定時実行', '有効倉庫の自動発注が予定時刻＋猶予までに完了しているか', 1, 900, 900, 'RB-11', false, true, 1, true,
            ['slots' => 'auto', 'grace_minutes' => 90], ['slots', 'grace_minutes'], 'slots=auto で倉庫設定（replenishment_config の weekdays / execution_time / sunday_execution_time）から曜日ごとの予定を読む。固定したい場合だけ ["01:00"] や {"sun":["22:00"]} を指定');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $enabled = (int) $src->scalar('SELECT COUNT(*) FROM wms_warehouse_auto_order_settings WHERE is_auto_order_enabled = 1', [], 0);
        if ($enabled === 0) {
            return CheckResult::single(Observation::healthy('自動発注が有効な倉庫はありません（対象なし）。', ['slot' => 'none']));
        }
        $grace = (int) $ctx->config('grace_minutes', 90);
        $configured = $ctx->config('slots', 'auto');
        // 予定枠: 既定は倉庫設定から曜日ごとに導出（日曜だけ 22:00 など）。倉庫ごとに異なる場合は枠ごとの対象倉庫数で判定する
        $auto = $configured === 'auto' || $configured === null || $configured === [];
        [$slots, $warehousesBySlot] = $auto ? $this->slotsFromSettings($src) : [(array) $configured, []];
        if ($slots === []) {
            return CheckResult::single(Observation::healthy('自動発注の予定枠が設定されていません（倉庫設定に実行時刻なし）。', ['slot' => 'none']));
        }
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $key = $slot['date'].':'.$slot['time'];
            $dow = self::DOW[CarbonImmutable::parse($slot['date'], 'Asia/Tokyo')->dayOfWeek];
            $enabledForSlot = $auto ? count($warehousesBySlot[$dow][$slot['time']] ?? []) : $enabled;
            if ($enabledForSlot === 0) {
                continue; // この曜日・時刻に予定のある倉庫は無い
            }
            $enabled = $enabledForSlot;
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

    /**
     * 倉庫設定から予定枠を導出する。蔵の GenerationSchedule::dueSlot と同じ規則:
     * weekdays（0=日〜6=土）に含まれる曜日だけ、日曜は sunday_execution_time（無ければ execution_time）、他は execution_time。
     *
     * @return array{0:array<string,string[]>,1:array<string,array<string,int[]>>} [dow => times, dow => [time => warehouse_ids]]
     */
    private function slotsFromSettings(\App\Monitoring\Services\SourceReader $src): array
    {
        $rows = $src->select('SELECT warehouse_id, replenishment_config FROM wms_warehouse_auto_order_settings WHERE is_auto_order_enabled = 1 AND replenishment_config IS NOT NULL', []);
        $byDow = [];
        $wh = [];
        foreach ($rows as $r) {
            $cfg = json_decode((string) $r->replenishment_config, true) ?: [];
            $weekdays = array_map('intval', (array) ($cfg['weekdays'] ?? []));
            foreach ($weekdays as $d) {
                if ($d < 0 || $d > 6) {
                    continue;
                }
                $dow = self::DOW[$d];
                $time = $d === 0 ? ($cfg['sunday_execution_time'] ?? $cfg['execution_time'] ?? null) : ($cfg['execution_time'] ?? null);
                if (! is_string($time) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    continue;
                }
                $byDow[$dow][] = $time;
                $wh[$dow][$time][] = (int) $r->warehouse_id;
            }
        }
        foreach ($byDow as $dow => $times) {
            $byDow[$dow] = array_values(array_unique($times));
            sort($byDow[$dow]);
        }

        return [$byDow, $wh];
    }
}
