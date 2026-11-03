<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/** trade.promo: 販促レシートキャンペーンスケジュールの send_at＋2時間までに sent_at が記録されているか。 */
class PromoReceiptCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.promo', 'TRADE', '販促レシート送信', 'send_at＋2時間以内の送信完了（sent_at）', 1, 900, 900, 'RB-05', false, false, 1, true, ['grace_minutes' => 120], ['grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $local = $ctx->nowLocal();
        $grace = (int) $ctx->config('grace_minutes', 120);
        $rows = $src->select('SELECT id, name, send_at, sent_at FROM ret_promo_receipt_campaign_schedules WHERE schedule_enabled = 1 AND send_at IS NOT NULL AND send_at >= ? AND send_at <= ? ORDER BY id', [$local->subDays(2)->format('Y-m-d H:i:s'), $local->subMinutes($grace)->format('Y-m-d H:i:s')]);
        $obs = [];
        foreach ($rows as $r) {
            $scope = ['schedule_id' => (int) $r->id];
            $obs[] = $r->sent_at
                ? Observation::healthy("販促レシート schedule#{$r->id} は送信済み（{$r->sent_at}）。", $scope)
                : Observation::violation("販促レシート schedule#{$r->id}（{$r->name}）が send_at {$r->send_at}＋{$grace}分までに送信されていません。", $scope, null, [], ['send_at' => $r->send_at], 'PROMO_NOT_SENT');
        }

        return new CheckResult($obs ?: [Observation::healthy('直近2日に締切を迎えた販促レシート送信はありません。', ['window' => 'recent'])]);
    }
}
