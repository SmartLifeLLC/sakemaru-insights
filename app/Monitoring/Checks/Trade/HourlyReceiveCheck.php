<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * trade.hourly_receive: 時刻指定（target_hour）受信リクエストの完了。締切 = 対象時刻+1h+猶予。
 * 専用リクエストだけの欠番で、5分受信（target_hour なし）が同時間帯に成功していれば WARNING（実データ欠落と断定しない）。
 */
class HourlyReceiveCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.hourly_receive', 'TRADE', '時刻指定受信の完了', '専用の時刻指定受信リクエストが締切までに完了しているか', 1, 300, 300, 'RB-03', false, true, 2, true, ['grace_minutes' => 15], ['grace_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $local = $ctx->nowLocal();
        $grace = (int) $ctx->config('grace_minutes', 15);
        // 直近 3 時間に締切を迎えた専用リクエストを対象
        $since = $local->subHours(3)->format('Y-m-d H:i:s');
        $rows = $src->select(
            "SELECT id, target_business_date, target_hour, execution_status, scheduled_at, completed_at, last_error_message FROM ret_tm_receive_requests WHERE receive_mode = 'hourly_inspection' AND target_hour IS NOT NULL AND scheduled_at >= ? AND scheduled_at <= ? ORDER BY id",
            [$since, $local->format('Y-m-d H:i:s')],
        );
        $obs = [];
        foreach ($rows as $r) {
            $deadline = \Carbon\CarbonImmutable::parse($r->scheduled_at, 'Asia/Tokyo')->addMinutes(60 + $grace);
            if ($local->lessThan($deadline)) {
                continue;
            }
            $date = $r->target_business_date ?: $local->toDateString();
            $scope = ['business_date' => $date, 'target_hour' => (int) $r->target_hour];
            if ($r->execution_status === 'SUCCESS') {
                $obs[] = Observation::healthy("時刻指定受信 {$date} {$r->target_hour}時 は完了。", $scope, $date, ['request_id' => (int) $r->id]);

                continue;
            }
            $covered = (int) $src->scalar(
                "SELECT COUNT(*) FROM ret_tm_receive_requests WHERE receive_mode = 'hourly_inspection' AND target_hour IS NULL AND execution_status = 'SUCCESS' AND scheduled_at >= ? AND scheduled_at < ?",
                [\Carbon\CarbonImmutable::parse($r->scheduled_at, 'Asia/Tokyo')->format('Y-m-d H:i:s'), $deadline->format('Y-m-d H:i:s')],
            );
            $ev = ['request_id' => (int) $r->id, 'status' => $r->execution_status, 'other_route_success' => $covered, 'error' => mb_substr((string) $r->last_error_message, 0, 200)];
            if ($covered > 0) {
                $obs[] = Observation::warning("時刻指定受信 {$date} {$r->target_hour}時 が未完了（{$r->execution_status}）。5分受信は同時間帯に {$covered} 回成功しており、実データ欠落とは断定しません。", $scope, $date, [], $ev, 'DEDICATED_RECEIVE_MISSING');
            } else {
                $obs[] = Observation::violation("時刻指定受信 {$date} {$r->target_hour}時 が未完了（{$r->execution_status}）で、他経路の受信成功もありません。", $scope, $date, [], $ev, 'RECEIVE_MISSING');
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('直近3時間に締切を迎えた時刻指定受信はありません。', ['window' => 'recent'])]);
    }
}
