<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.jx_transmission: JX 送受信（wms_jx_transmission_logs、status success / failure）。
 * 送受信の種類（方向・操作・文書種別）ごとに最新の状態で判定。failure → ERROR。有効な JX 設定があるのに 48 時間送信ゼロ → WARNING。
 */
class JxTransmissionCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.jx_transmission', 'WMS', 'JX 送受信', 'JX の送信（PutDocument）・受信（GetDocument）の失敗', 1, 300, 300, 'RB-12', false, false, 1, true,
            ['lookback_hours' => 24, 'silence_hours' => 48], ['lookback_hours', 'silence_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $rows = $src->select("SELECT l.id, l.direction, l.operation_type, l.document_type, l.status, l.http_code, SUBSTR(l.error_message, 1, 300) AS err, l.created_at,
                (SELECT COUNT(*) FROM wms_jx_transmission_logs f WHERE f.status <> 'success' AND f.created_at >= ? AND COALESCE(f.direction,'') = COALESCE(l.direction,'') AND COALESCE(f.operation_type,'') = COALESCE(l.operation_type,'') AND COALESCE(f.document_type,'') = COALESCE(l.document_type,'')) AS failures
            FROM wms_jx_transmission_logs l
            JOIN (SELECT MAX(id) AS max_id FROM wms_jx_transmission_logs WHERE created_at >= ? GROUP BY direction, operation_type, document_type) m ON m.max_id = l.id", [$since, $since]);
        $obs = [];
        foreach ($rows as $r) {
            $kind = trim(($r->direction ?: '-').'/'.($r->operation_type ?: '-').'/'.($r->document_type ?: '-'));
            $scope = ['transmission' => $kind];
            if (strtolower((string) $r->status) !== 'success') {
                $obs[] = Observation::violation("JX {$kind} が失敗しています（最新 #{$r->id}、HTTP ".($r->http_code ?: '-').'）。', $scope, null, ['failures_in_window' => (int) $r->failures], ['error' => $r->err, 'log_id' => (int) $r->id], 'JX_TRANSMISSION_FAILED');
            } elseif ((int) $r->failures > 0) {
                $obs[] = Observation::warning("JX {$kind} は最新が成功だが、直近に失敗 {$r->failures} 件。", ['transmission' => $kind, 'state' => 'recent_failures'], null, ['failures_in_window' => (int) $r->failures]);
                $obs[] = Observation::healthy("JX {$kind} の最新は成功。", $scope);
            } else {
                $obs[] = Observation::healthy("JX {$kind} の最新は成功。", $scope);
            }
        }
        // 有効な JX 設定があるのに長時間送受信が無い
        $active = (int) $src->scalar('SELECT COUNT(*) FROM wms_order_jx_settings WHERE is_active = 1', [], 0);
        if ($active > 0) {
            $silence = (int) $ctx->config('silence_hours', 48);
            $last = $src->scalar('SELECT MAX(created_at) FROM wms_jx_transmission_logs WHERE created_at >= ?', [$this->jst($ctx->nowUtc->subHours($silence * 2))], null);
            $age = $last ? \Carbon\CarbonImmutable::parse($last, 'Asia/Tokyo')->diffInHours($ctx->nowLocal()) : null;
            $obs[] = ($age === null || $age > $silence)
                ? Observation::warning('有効な JX 設定 '.$active.' 件があるのに、'.($age === null ? ($silence * 2).' 時間以上送受信がありません' : (int) $age.' 時間送受信がありません').'。', ['transmission' => 'silence'], null, ['hours_since_last' => $age], [], 'JX_SILENCE')
                : Observation::healthy('JX 送受信は '.(int) $age.' 時間以内に行われています。', ['transmission' => 'silence']);
        }

        return new CheckResult($obs ?: [Observation::healthy('直近の JX 送受信はありません（JX 設定なし）。', ['transmission' => 'none'])]);
    }
}
