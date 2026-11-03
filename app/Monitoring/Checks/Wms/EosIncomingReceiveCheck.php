<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.eos_incoming_receive: EOS 入荷予定受信。予定は業務側のスケジュール表（wms_eos_incoming_receive_schedules, is_enabled）をそのまま使う。
 * DAILY は毎日、WEEKLY_EXTRA は day_of_week（1=月 … 7/0=日）。receive_time＋猶予までに SUCCEEDED が無い → ERROR、PARTIAL_FAILED・error_count>0 → WARNING。
 */
class EosIncomingReceiveCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.eos_incoming_receive', 'WMS', 'EOS 入荷予定受信', 'スケジュールどおりに EOS 入荷予定の受信が完了しているか', 1, 600, 600, 'RB-13', false, true, 1, true,
            ['grace_minutes' => 30], ['grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $schedules = $src->select('SELECT schedule_type, day_of_week, receive_time FROM wms_eos_incoming_receive_schedules WHERE is_enabled = 1');
        if (! $schedules) {
            return CheckResult::single(Observation::healthy('有効な EOS 受信スケジュールはありません（対象なし）。', ['slot' => 'none']));
        }
        $slots = [];
        foreach ($schedules as $s) {
            $time = substr((string) $s->receive_time, 0, 5);
            if (strtoupper((string) $s->schedule_type) === 'DAILY') {
                $slots['daily'][] = $time;
            } else {
                $dow = (int) $s->day_of_week;
                $slots[self::DOW[$dow % 7]][] = $time;
            }
        }
        $grace = (int) $ctx->config('grace_minutes', 30);
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $rows = $src->select('SELECT status, COUNT(*) AS c, SUM(error_count) AS errs, MAX(SUBSTR(error_summary, 1, 300)) AS summary FROM wms_eos_incoming_receive_runs WHERE execution_date = ? AND scheduled_time = ? GROUP BY status', [$slot['date'], $slot['time'].':00']);
            $by = [];
            $errs = 0;
            $summary = null;
            foreach ($rows as $r) {
                $by[$r->status] = (int) $r->c;
                $errs += (int) $r->errs;
                $summary = $summary ?: $r->summary;
            }
            $scope = ['business_date' => $slot['date'], 'slot' => $slot['time']];
            if (($by['SUCCEEDED'] ?? 0) === 0 && ($by['PARTIAL_FAILED'] ?? 0) === 0) {
                $obs[] = Observation::violation("EOS 入荷予定受信 {$slot['date']} {$slot['time']} が予定＋{$grace}分までに完了していません（".($by ? json_encode($by) : '実行記録なし').'）。', $scope, $slot['date'], $by, ['error' => $summary], 'EOS_RECEIVE_MISSING');
            } elseif (($by['PARTIAL_FAILED'] ?? 0) > 0 || $errs > 0) {
                $obs[] = Observation::warning("EOS 入荷予定受信 {$slot['date']} {$slot['time']} は一部失敗（エラー {$errs} 件）。", $scope, $slot['date'], $by + ['error_count' => $errs], ['error' => $summary], 'EOS_RECEIVE_PARTIAL');
            } else {
                $obs[] = Observation::healthy("EOS 入荷予定受信 {$slot['date']} {$slot['time']} は完了。", $scope, $slot['date'], $by);
            }
        }
        if (! $obs) {
            $next = $this->nextSlot($ctx, $slots, $grace);
            $obs[] = Observation::notDue('次の判定は '.($next ? $next['date'].' '.$next['time'].'＋'.$grace.'分' : '—').'。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
