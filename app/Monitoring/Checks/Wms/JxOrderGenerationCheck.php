<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.jx_order_generation: JX 発注データ生成（wms_jx_order_generation_runs）。
 * 本番実績: 月〜土 13:30、日曜 23:35。予定＋猶予までに SUCCESS が無い、または FAILED がある → ERROR。
 */
class JxOrderGenerationCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.jx_order_generation', 'WMS', 'JX 発注データ生成', '曜日別の予定時刻に JX 発注データ生成が完了しているか', 1, 600, 600, 'RB-12', false, true, 1, true,
            ['slots' => ['mon' => '13:30', 'tue' => '13:30', 'wed' => '13:30', 'thu' => '13:30', 'fri' => '13:30', 'sat' => '13:30', 'sun' => '23:35'], 'grace_minutes' => 30],
            ['slots', 'grace_minutes'], '予定枠は本番実績から仮置き（月〜土 13:30、日 23:35）');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $slots = (array) $ctx->config('slots', []);
        $grace = (int) $ctx->config('grace_minutes', 30);
        $obs = [];
        foreach ($this->dueSlots($ctx, $slots, $grace) as $slot) {
            $rows = $src->select('SELECT status, COUNT(*) AS c, SUM(generated_document_count) AS docs, MAX(SUBSTR(error_message, 1, 300)) AS err FROM wms_jx_order_generation_runs WHERE target_date = ? AND generation_time = ? GROUP BY status', [$slot['date'], $slot['time']]);
            $by = [];
            $docs = 0;
            $err = null;
            foreach ($rows as $r) {
                $by[$r->status] = (int) $r->c;
                $docs += (int) $r->docs;
                $err = $err ?: $r->err;
            }
            $scope = ['business_date' => $slot['date'], 'slot' => $slot['time']];
            $failed = array_sum(array_intersect_key($by, ['FAILED' => 1, 'ERROR' => 1]));
            if ($failed > 0) {
                $obs[] = Observation::violation("JX 発注データ生成 {$slot['date']} {$slot['time']} で失敗 {$failed} 件。", $scope, $slot['date'], $by + ['documents' => $docs], ['error' => $err], 'JX_GENERATION_FAILED');
            } elseif (($by['SUCCESS'] ?? 0) === 0) {
                $obs[] = Observation::violation("JX 発注データ生成 {$slot['date']} {$slot['time']} が予定＋{$grace}分までに完了していません（".($by ? json_encode($by) : '実行記録なし').'）。', $scope, $slot['date'], $by, [], 'JX_GENERATION_MISSING');
            } else {
                $obs[] = Observation::healthy("JX 発注データ生成 {$slot['date']} {$slot['time']} は完了（{$by['SUCCESS']} 件、文書 {$docs}）。", $scope, $slot['date'], $by + ['documents' => $docs]);
            }
        }
        if (! $obs) {
            $next = $this->nextSlot($ctx, $slots, $grace);
            $obs[] = Observation::notDue('次の判定は '.($next ? $next['date'].' '.$next['time'].'＋'.$grace.'分' : '—').'。', ['slot' => 'next']);
        }

        return new CheckResult($obs);
    }
}
