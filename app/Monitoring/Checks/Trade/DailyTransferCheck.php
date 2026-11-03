<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/** trade.daily_transfer: 日次締め受信（daily_close）の成功履歴。予定 00:10 ＋ 2 時間後に未完了なら ERROR。 */
class DailyTransferCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.daily_transfer', 'TRADE', '日次送受信の完了', '日次締め受信（daily_close）が予定時刻＋2時間以内に成功しているか', 1, 900, 900, 'RB-04', false, true, 1, true, ['scheduled_time' => '00:10', 'grace_minutes' => 120], ['scheduled_time', 'grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $date = $ctx->targetBusinessDate(1);
        $scope = ['business_date' => $date];
        $deadline = $this->deadlineUtc(date('Y-m-d', strtotime($date.' +1 day')), (string) $ctx->config('scheduled_time', '00:10'), (int) $ctx->config('grace_minutes', 120));
        if ($ctx->nowUtc->lessThan($deadline)) {
            return CheckResult::single(Observation::notDue("対象営業日 {$date} の判定開始前です。", $scope, $date));
        }
        $wm = $this->dailyCloseWatermark($ctx, $date);
        if (($wm['by_status']['SUCCESS'] ?? 0) > 0 && $wm['complete']) {
            return CheckResult::single(Observation::healthy("対象営業日 {$date} の日次締め受信は成功。", $scope, $date, $wm['by_status'], 'daily_close:'.$wm['max_id']));
        }
        if (! $wm['by_status']) {
            return CheckResult::single(Observation::violation("対象営業日 {$date} の日次締め受信リクエストが存在しません。", $scope, $date, [], [], 'DAILY_TRANSFER_MISSING'));
        }

        return CheckResult::single(Observation::violation("対象営業日 {$date} の日次締め受信が未完了（".json_encode($wm['by_status'], JSON_UNESCAPED_UNICODE).'）。', $scope, $date, $wm['by_status'], [], 'DAILY_TRANSFER_INCOMPLETE'));
    }
}
