<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * trade.member_daily: 完了した元データ（会員購買）がある営業日・店舗の会員日別統計行の存在。翌 00:45 以降。
 * 元データ自体が不明（受信未完了）なら UNKNOWN。現在の会員マスターで過去値を再計算しない。
 */
class MemberDailyCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.member_daily', 'TRADE', '会員日別統計の存在', '元データがある店舗・営業日の会員統計行が存在するか', 1, 1800, 1800, 'RB-02', true, true, 1, true, ['deadline_time' => '00:45'], ['deadline_time']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $date = $ctx->targetBusinessDate(1);
        $scope = ['business_date' => $date];
        $deadline = $this->deadlineUtc(date('Y-m-d', strtotime($date.' +1 day')), (string) $ctx->config('deadline_time', '00:45'));
        if ($ctx->nowUtc->lessThan($deadline)) {
            return CheckResult::single(Observation::notDue("対象営業日 {$date} の判定開始前です。", $scope, $date));
        }
        $src = $ctx->source();
        $wm = $this->dailyCloseWatermark($ctx, $date);
        if (! $wm['complete']) {
            return CheckResult::single(Observation::unknown("対象営業日 {$date} の受信が完了していないため判定できません。", $scope, $date, ['receive' => $wm['by_status']], 'SOURCE_INCOMPLETE'));
        }
        $expected = $src->selectDetail('SELECT store_id, COUNT(*) AS c FROM ret_pos_member_purchases WHERE business_date = ? AND store_id IS NOT NULL GROUP BY store_id', [$date]);
        $actual = $src->select('SELECT ret_store_id FROM ret_app_member_daily_statistics WHERE business_date = ?', [$date]);
        $have = array_flip(array_map(fn ($r) => (int) $r->ret_store_id, $actual));
        $missing = [];
        foreach ($expected as $r) {
            if (! isset($have[(int) $r->store_id])) {
                $missing[] = (int) $r->store_id;
            }
        }
        $metrics = ['expected_stores' => count($expected), 'stored_rows' => count($actual), 'missing' => count($missing)];
        $watermark = 'daily_close:'.$wm['max_id'];
        if (! $missing) {
            return CheckResult::single(Observation::healthy(count($expected) ? "対象営業日 {$date} の会員統計は対象 ".count($expected).' 店舗すべて存在。' : "対象営業日 {$date} は会員購買データなし（正常）。", $scope, $date, $metrics, $watermark));
        }

        return CheckResult::single(Observation::violation("会員日別統計が欠落: 対象営業日 {$date} / 欠落 ".count($missing).' 店舗（期待 '.count($expected).'）', $scope, $date, $metrics, ['missing_store_ids' => array_slice($missing, 0, 20)], 'MEMBER_STATS_MISSING', \App\Monitoring\Enums\Severity::ERROR, $watermark));
    }
}
