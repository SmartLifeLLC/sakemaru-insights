<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * trade.coupon_daily: 元POS（raw_sale_items の coupon_jan_code）と店舗・企画別クーポン日別統計の照合。
 * 判定開始: 翌 00:50。実使用0件・出力0件は正常。
 *
 * 実装注記: 参照実装（Python）の4指標の導出式を突合できていないため、初版の厳密比較は
 * transaction_count（= 取引数の一致）を主指標とし、他3指標は証拠として保存する。
 * strict_metrics=true にすると4指標すべての一致を要求する。
 */
class CouponDailyCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.coupon_daily', 'TRADE', 'クーポン日別統計の照合', '元POSのクーポン使用と店舗・企画別日別統計の一致', 1, 1800, 1800, 'RB-01', true, true, 1, true,
            ['deadline_time' => '00:50', 'strict_metrics' => false], ['deadline_time', 'strict_metrics'],
            '4指標の導出式は参照実装との突合待ち。初版は transaction_count を主指標とする。');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $date = $ctx->targetBusinessDate(1);
        $deadline = $this->deadlineUtc(date('Y-m-d', strtotime($date.' +1 day')), (string) $ctx->config('deadline_time', '00:50'));
        $scope = ['business_date' => $date];
        if ($ctx->nowUtc->lessThan($deadline)) {
            return CheckResult::single(Observation::notDue("対象営業日 {$date} の判定開始（".$deadline->setTimezone('Asia/Tokyo')->format('m/d H:i').'）前です。', $scope, $date));
        }
        $src = $ctx->source();
        $wm = $this->dailyCloseWatermark($ctx, $date);
        if (! $wm['complete']) {
            return CheckResult::single(Observation::unknown("対象営業日 {$date} の daily_close 受信が完了していないため判定できません（".json_encode($wm['by_status'], JSON_UNESCAPED_UNICODE).'）。', $scope, $date, ['receive' => $wm['by_status']], 'SOURCE_INCOMPLETE'));
        }
        $pos = $src->selectDetail(
            "SELECT store_code, coupon_jan_code, COUNT(DISTINCT transaction_no) AS txn_cnt, COUNT(*) AS line_cnt, SUM(quantity) AS qty, SUM(item_discount_amount) AS idisc
             FROM ret_tm_received_raw_sale_items
             WHERE business_date = ? AND coupon_jan_code IS NOT NULL AND coupon_jan_code <> '' AND coupon_jan_code <> '0000000000000' AND is_void = 0
             GROUP BY store_code, coupon_jan_code",
            [$date],
        );
        $stats = $src->selectDetail(
            'SELECT st.pos_store_code, c.instore_code, c.campaign_number, s.usage_count, s.coupon_quantity, s.transaction_count, s.item_discount_amount
             FROM ret_app_coupon_usage_daily_statistics s
             JOIN ret_stores st ON st.id = s.ret_store_id
             JOIN ret_coupon_campaigns c ON c.id = s.coupon_campaign_id
             WHERE s.business_date = ?',
            [$date],
        );
        $expected = [];
        foreach ($pos as $r) {
            $expected[$this->normalizeStoreCode($r->store_code).'|'.$r->coupon_jan_code] = $r;
        }
        $actual = [];
        foreach ($stats as $r) {
            $actual[$this->normalizeStoreCode($r->pos_store_code).'|'.$r->instore_code] = $r;
        }
        $strict = (bool) $ctx->config('strict_metrics', false);
        $missing = [];
        $mismatch = [];
        foreach ($expected as $k => $e) {
            $a = $actual[$k] ?? null;
            if (! $a) {
                $missing[] = ['key' => $k, 'pos_txn' => (int) $e->txn_cnt];

                continue;
            }
            if ((int) $a->transaction_count !== (int) $e->txn_cnt) {
                $mismatch[] = ['key' => $k, 'pos_txn' => (int) $e->txn_cnt, 'stat_txn' => (int) $a->transaction_count];
            } elseif ($strict && ((float) $a->coupon_quantity !== (float) $e->qty || abs((int) $a->item_discount_amount) !== abs((int) $e->idisc))) {
                $mismatch[] = ['key' => $k, 'pos_qty' => (float) $e->qty, 'stat_qty' => (float) $a->coupon_quantity, 'pos_disc' => (int) $e->idisc, 'stat_disc' => (int) $a->item_discount_amount];
            }
        }
        $metrics = ['expected_groups' => count($expected), 'stored_groups' => count($actual), 'missing' => count($missing), 'mismatch' => count($mismatch)];
        $watermark = 'daily_close:'.$wm['max_id'];
        if (! $missing && ! $mismatch) {
            $msg = count($expected) === 0 ? "対象営業日 {$date} はクーポン使用0件・集計0件（正常）。" : "対象営業日 {$date} のクーポン統計は元POSと一致（".count($expected).'組）。';

            return CheckResult::single(Observation::healthy($msg, $scope, $date, $metrics, $watermark));
        }
        $stores = array_values(array_unique(array_map(fn ($m) => explode('|', $m['key'])[0], array_merge($missing, $mismatch))));
        $summary = sprintf('クーポン日別統計が元POSと不一致: 期待 %d組 / 保存 %d組 / 欠落 %d / 不一致 %d（%d店舗）', count($expected), count($actual), count($missing), count($mismatch), count($stores));

        return CheckResult::single(Observation::violation($summary, $scope, $date, $metrics, ['missing' => array_slice($missing, 0, 20), 'mismatch' => array_slice($mismatch, 0, 20), 'stores' => $stores], 'COUPON_STATS_MISMATCH', \App\Monitoring\Enums\Severity::ERROR, $watermark));
    }
}
