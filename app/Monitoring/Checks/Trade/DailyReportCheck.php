<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/** trade.daily_report: 日報スケジュールが有効なら、対象営業日の日報処理（company_daily_sales_report_runs）が個別設定時刻＋猶予までに完了。設定履歴が不明なら断定しない。 */
class DailyReportCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.daily_report', 'TRADE', '日報処理の完了', '日報スケジュールの対象営業日の処理完了', 1, 900, 900, 'RB-05', false, true, 1, true, ['grace_minutes' => 60], ['grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $local = $ctx->nowLocal();
        $grace = (int) $ctx->config('grace_minutes', 60);
        $sched = $src->select('SELECT id, schedule_type, schedule_time, schedule_last_run_at FROM ret_daily_sales_report_schedules WHERE schedule_enabled = 1 ORDER BY id LIMIT 1');
        if (! $sched) {
            return CheckResult::single(Observation::healthy('日報スケジュールは無効（対象なし）。', ['schedule' => 'disabled']));
        }
        $s = $sched[0];
        if ($s->schedule_type !== 'daily' || ! $s->schedule_time) {
            return CheckResult::single(Observation::unknown("日報スケジュール種別 {$s->schedule_type} は初版で判定対象外です。", ['schedule' => $s->schedule_type], null, [], 'UNSUPPORTED_SCHEDULE'));
        }
        $today = $local->toDateString();
        $deadline = \Carbon\CarbonImmutable::parse($today.' '.$s->schedule_time, 'Asia/Tokyo')->addMinutes($grace);
        $evalDate = $local->lessThan($deadline) ? $local->subDay()->toDateString() : $today;
        $businessDate = \Carbon\CarbonImmutable::parse($evalDate)->subDay()->toDateString();
        $scope = ['business_date' => $businessDate];
        $runs = $src->select('SELECT status, COUNT(*) AS c FROM ret_company_daily_sales_report_runs WHERE business_date = ? GROUP BY status', [$businessDate]);
        $by = [];
        foreach ($runs as $r) {
            $by[$r->status] = (int) $r->c;
        }
        $done = self::completedCount($by) > 0;
        if ($done) {
            return CheckResult::single(Observation::healthy("日報処理（対象 {$businessDate}）は完了。", $scope, $businessDate, $by));
        }

        return CheckResult::single(Observation::violation("日報処理（対象 {$businessDate}）が予定 {$s->schedule_time}＋{$grace}分までに完了していません（".json_encode($by, JSON_UNESCAPED_UNICODE).'）。', $scope, $businessDate, $by, [], 'DAILY_REPORT_INCOMPLETE'));
    }
}
