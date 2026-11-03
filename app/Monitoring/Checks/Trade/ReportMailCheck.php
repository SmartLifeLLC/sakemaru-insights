<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/** trade.mail: 有効な帳票メールスケジュールごとに、予定時刻＋30分までに SUCCESS の送信履歴があるか。成功再試行を考慮（先行失敗があっても後続成功で完了）。 */
class ReportMailCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.mail', 'TRADE', '帳票メール送信', '有効スケジュールの送信完了（予定＋30分）', 1, 600, 600, 'RB-05', false, true, 1, true, ['grace_minutes' => 30], ['grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $local = $ctx->nowLocal();
        $grace = (int) $ctx->config('grace_minutes', 30);
        $schedules = $src->select("SELECT id, report_type, slot_key, schedule_time, business_date_offset FROM ret_report_mail_schedules WHERE schedule_enabled = 1 AND paused_at IS NULL AND schedule_type = 'daily'");
        $obs = [];
        foreach ($schedules as $s) {
            $today = $local->toDateString();
            $deadline = \Carbon\CarbonImmutable::parse($today.' '.$s->schedule_time, 'Asia/Tokyo')->addMinutes($grace);
            // 本日の締切前なら前日分を評価
            $evalDate = $local->lessThan($deadline) ? $local->subDay()->toDateString() : $today;
            $deadline = \Carbon\CarbonImmutable::parse($evalDate.' '.$s->schedule_time, 'Asia/Tokyo')->addMinutes($grace);
            $businessDate = \Carbon\CarbonImmutable::parse($evalDate)->addDays((int) $s->business_date_offset)->toDateString();
            $scope = ['business_date' => $businessDate, 'schedule' => $s->report_type.'/'.$s->slot_key];
            $runs = $src->select('SELECT status, COUNT(*) AS c FROM ret_report_mail_send_runs WHERE report_mail_schedule_id = ? AND business_date = ? GROUP BY status', [$s->id, $businessDate]);
            $by = [];
            foreach ($runs as $r) {
                $by[$r->status] = (int) $r->c;
            }
            if (($by['SUCCESS'] ?? 0) > 0) {
                $obs[] = Observation::healthy("帳票メール {$s->report_type}/{$s->slot_key}（{$businessDate}）は送信完了。", $scope, $businessDate, $by);
            } else {
                $obs[] = Observation::violation("帳票メール {$s->report_type}/{$s->slot_key}（{$businessDate}）が予定 {$s->schedule_time}＋{$grace}分までに完了していません（".json_encode($by, JSON_UNESCAPED_UNICODE).'）。', $scope, $businessDate, $by, ['schedule_id' => (int) $s->id], 'REPORT_MAIL_INCOMPLETE');
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('有効な日次帳票メールスケジュールはありません。', ['schedule' => 'none'])]);
    }
}
