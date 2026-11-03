<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/** trade.intage: インテージPOS送信が予定時刻＋30分までに SUCCESS。 */
class IntageSendCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.intage', 'TRADE', 'インテージPOS送信', '有効設定の送信完了（予定＋30分）', 1, 600, 600, 'RB-05', false, true, 1, true, ['grace_minutes' => 30], ['grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $local = $ctx->nowLocal();
        $grace = (int) $ctx->config('grace_minutes', 30);
        $setting = $src->select('SELECT id, schedule_enabled, schedule_time, business_date_offset FROM ret_intage_pos_send_settings ORDER BY id LIMIT 1');
        if (! $setting || ! (int) $setting[0]->schedule_enabled || ! $setting[0]->schedule_time) {
            return CheckResult::single(Observation::healthy('インテージ送信スケジュールは無効（対象なし）。', ['setting' => 'disabled']));
        }
        $s = $setting[0];
        $today = $local->toDateString();
        $deadline = \Carbon\CarbonImmutable::parse($today.' '.$s->schedule_time, 'Asia/Tokyo')->addMinutes($grace);
        $evalDate = $local->lessThan($deadline) ? $local->subDay()->toDateString() : $today;
        $target = \Carbon\CarbonImmutable::parse($evalDate)->addDays((int) $s->business_date_offset)->toDateString();
        $scope = ['business_date' => $target];
        $runs = $src->select('SELECT status, COUNT(*) AS c FROM ret_intage_pos_send_runs WHERE target_to_date = ? GROUP BY status', [$target]);
        $by = [];
        foreach ($runs as $r) {
            $by[$r->status] = (int) $r->c;
        }
        if (($by['SUCCESS'] ?? 0) > 0) {
            return CheckResult::single(Observation::healthy("インテージ送信（対象 {$target}）は完了。", $scope, $target, $by));
        }

        return CheckResult::single(Observation::violation("インテージ送信（対象 {$target}）が予定 {$s->schedule_time}＋{$grace}分までに完了していません（".json_encode($by, JSON_UNESCAPED_UNICODE).'）。', $scope, $target, $by, [], 'INTAGE_SEND_INCOMPLETE'));
    }
}
