<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.external_collaboration: 外部連携取込（external_collaboration_data）。種類（edi_type）×連携先ごとの最新状態で判定。
 * VALIDATION_ERROR / APPLY_ERROR → ERROR。APPLY_WAIT・VALIDATING 等が 2 時間超 → WARNING。成功でも failed_count>0 → WARNING。
 * 飛脚（MOBILE_COLLECT）分は飛脚側の delivery.external_sync で扱うため除外する。
 */
class ExternalCollaborationCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.external_collaboration', 'CORE', '外部連携取込', '外部連携データ（売上・入金・仕入・支払等）の取込失敗', 1, 600, 600, 'RB-18', false, false, 1, true,
            ['lookback_hours' => 24, 'wait_minutes' => 120, 'exclude_partners' => ['MOBILE_COLLECT']], ['lookback_hours', 'wait_minutes', 'exclude_partners']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $exclude = array_values(array_filter((array) $ctx->config('exclude_partners', [])));
        $placeholders = $exclude ? implode(',', array_fill(0, count($exclude), '?')) : "''";
        $rows = $ctx->source()->select("SELECT d.id, d.edi_type, d.external_collaboration_partner AS partner, d.status, d.total_lines, d.success_count, d.failed_count, d.created_at, SUBSTR(d.debug_message, 1, 300) AS msg
            FROM external_collaboration_data d
            JOIN (SELECT MAX(id) AS max_id FROM external_collaboration_data WHERE created_at >= ? AND COALESCE(external_collaboration_partner, '') NOT IN ({$placeholders}) GROUP BY edi_type, external_collaboration_partner) m ON m.max_id = d.id",
            array_merge([$since], $exclude));
        $now = $ctx->nowLocal();
        $obs = [];
        foreach ($rows as $r) {
            $kind = $r->edi_type.'/'.($r->partner ?: '-');
            $scope = ['collaboration' => $kind];
            $ev = ['data_id' => (int) $r->id, 'status' => $r->status, 'message' => $r->msg];
            $metrics = ['total_lines' => (int) $r->total_lines, 'success' => (int) $r->success_count, 'failed' => (int) $r->failed_count];
            if (in_array($r->status, ['VALIDATION_ERROR', 'APPLY_ERROR'], true)) {
                $obs[] = Observation::violation("外部連携 {$kind} の取込が {$r->status} です（#{$r->id}）。", $scope, null, $metrics, $ev, 'EXTERNAL_'.$r->status);
            } elseif ($r->status !== 'APPLYING_SUCCESS' && \Carbon\CarbonImmutable::parse($r->created_at, 'Asia/Tokyo')->diffInMinutes($now) > (int) $ctx->config('wait_minutes', 120)) {
                $obs[] = Observation::warning("外部連携 {$kind} が {$r->status} のまま止まっています（#{$r->id}）。", $scope, null, $metrics, $ev, 'EXTERNAL_STALLED');
            } elseif ((int) $r->failed_count > 0) {
                $obs[] = Observation::warning("外部連携 {$kind} は取込済みだが、失敗行 {$r->failed_count}/{$r->total_lines}。", ['collaboration' => $kind, 'state' => 'failed_lines'], null, $metrics, $ev, 'EXTERNAL_FAILED_LINES');
                $obs[] = Observation::healthy("外部連携 {$kind} の最新は取込済み。", $scope, null, $metrics);
            } else {
                $obs[] = Observation::healthy("外部連携 {$kind} の最新は取込済み。", $scope, null, $metrics);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('直近の外部連携取込はありません。', ['collaboration' => 'none'])]);
    }
}
