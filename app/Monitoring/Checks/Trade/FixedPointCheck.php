<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * trade.fixed_point: 期間内の有効な定点POSスケジュールが、予定時刻＋2時間までに実行済み（schedule_last_run_at）。リクエスト生成だけを成功にしない（送信段階は評価対象外として明記）。
 * 当日の予定時刻より後に作成されたスケジュールは、当日の枠が存在しない（乃蓮は schedule_next_run_at を翌日に置く）ので判定しない（2026-11-13 MON-000089）。
 */
class FixedPointCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.fixed_point', 'TRADE', '定点POS送信スケジュール', '有効スケジュールの予定時刻＋2時間以内の実行', 1, 900, 900, 'RB-05', false, true, 1, true, ['grace_minutes' => 120], ['grace_minutes'],
            '送信段階（IRMN リクエスト）の SUCCESS 判定は参照実装の突合待ち。初版は schedule_last_run_at による実行確認。');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $local = $ctx->nowLocal();
        $grace = (int) $ctx->config('grace_minutes', 120);
        $today = $local->toDateString();
        $rows = $src->select('SELECT id, schedule_time, schedule_last_run_at, schedule_next_run_at, created_at FROM ret_fixed_point_pos_schedules WHERE schedule_enabled = 1 AND starts_on <= ? AND ends_on >= ?', [$today, $today]);
        $obs = [];
        foreach ($rows as $r) {
            $slot = \Carbon\CarbonImmutable::parse($today.' '.$r->schedule_time, 'Asia/Tokyo');
            $deadline = $slot->addMinutes($grace);
            if ($local->lessThan($deadline)) {
                continue;
            }
            if ($r->created_at && \Carbon\CarbonImmutable::parse($r->created_at, 'Asia/Tokyo')->greaterThan($slot)) {
                continue; // 当日の予定時刻を過ぎてから作成 → 初回は翌日以降
            }
            $scope = ['business_date' => $today, 'schedule_id' => (int) $r->id];
            $ran = $r->schedule_last_run_at && \Carbon\CarbonImmutable::parse($r->schedule_last_run_at, 'Asia/Tokyo')->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($today.' '.$r->schedule_time, 'Asia/Tokyo'));
            $obs[] = $ran
                ? Observation::healthy("定点POS schedule#{$r->id}（{$r->schedule_time}）は本日実行済み。", $scope, $today, ['last_run_at' => $r->schedule_last_run_at])
                : Observation::violation("定点POS schedule#{$r->id}（{$r->schedule_time}）が予定＋{$grace}分までに実行されていません。", $scope, $today, [], ['last_run_at' => $r->schedule_last_run_at], 'FIXED_POINT_NOT_RUN');
        }

        return new CheckResult($obs ?: [Observation::healthy('本日締切を迎えた定点POSスケジュールはありません。', ['business_date' => $today])]);
    }
}
