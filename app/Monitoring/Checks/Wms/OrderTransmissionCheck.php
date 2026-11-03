<?php

namespace App\Monitoring\Checks\Wms;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * wms.order_transmission: 発注データ送信。
 * - wms_order_jx_documents の ERROR（直近 24h）→ ERROR
 * - wms_order_transmission_logs: 送信種別ごとの最新が FAILED → JX_FINET/FTP は ERROR、MANUAL_CSV は WARNING。PENDING 60 分超 → WARNING
 * - 未確認（confirmed_at）警告は本番で confirmed_at が使われていないため既定 OFF（unconfirmed_warning）
 */
class OrderTransmissionCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('wms.order_transmission', 'WMS', '発注データ送信', '発注データ（JX 文書・FTP・手動 CSV）の送信失敗・滞留', 1, 600, 600, 'RB-12', false, false, 1, true,
            ['lookback_hours' => 24, 'pending_minutes' => 60, 'unconfirmed_warning' => false, 'unconfirmed_hours' => 24], ['lookback_hours', 'pending_minutes', 'unconfirmed_warning', 'unconfirmed_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $now = $ctx->nowLocal();
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $obs = [];

        $err = $src->select("SELECT COUNT(*) AS c, MAX(id) AS last_id, MAX(SUBSTR(error_message, 1, 300)) AS msg FROM wms_order_jx_documents WHERE status = 'ERROR' AND updated_at >= ?", [$since]);
        $c = (int) ($err[0]->c ?? 0);
        $obs[] = $c > 0
            ? Observation::violation("発注 JX 文書のエラーが {$c} 件あります（直近）。", ['documents' => 'error'], null, ['error_documents' => $c], ['last_document_id' => (int) $err[0]->last_id, 'error' => $err[0]->msg], 'ORDER_JX_DOCUMENT_ERROR')
            : Observation::healthy('発注 JX 文書のエラーはありません。', ['documents' => 'error']);

        $rows = $src->select('SELECT l.id, l.transmission_type, l.status, l.created_at, l.error_code, SUBSTR(l.error_message, 1, 300) AS msg FROM wms_order_transmission_logs l
            JOIN (SELECT MAX(id) AS max_id FROM wms_order_transmission_logs WHERE created_at >= ? GROUP BY transmission_type) m ON m.max_id = l.id', [$since]);
        foreach ($rows as $r) {
            $scope = ['transmission_type' => $r->transmission_type];
            $created = \Carbon\CarbonImmutable::parse($r->created_at, 'Asia/Tokyo');
            if ($r->status === 'FAILED') {
                $manual = $r->transmission_type === 'MANUAL_CSV';
                $o = $manual
                    ? Observation::warning("発注送信（手動 CSV）の最新が失敗しています（#{$r->id}）。", $scope, null, [], ['error_code' => $r->error_code, 'error' => $r->msg], 'ORDER_TRANSMISSION_FAILED')
                    : Observation::violation("発注送信 {$r->transmission_type} の最新が失敗しています（#{$r->id}）。", $scope, null, [], ['error_code' => $r->error_code, 'error' => $r->msg], 'ORDER_TRANSMISSION_FAILED');
                $obs[] = $o;
            } elseif ($r->status === 'PENDING' && $created->diffInMinutes($now) > (int) $ctx->config('pending_minutes', 60)) {
                $obs[] = Observation::warning("発注送信 {$r->transmission_type} が送信待ちのままです（#{$r->id}）。", $scope, null, [], [], 'ORDER_TRANSMISSION_PENDING');
            } else {
                $obs[] = Observation::healthy("発注送信 {$r->transmission_type} の最新は {$r->status}。", $scope);
            }
        }

        if ($ctx->config('unconfirmed_warning', false)) {
            $h = (int) $ctx->config('unconfirmed_hours', 24);
            $n = (int) $src->scalar("SELECT COUNT(*) FROM wms_order_jx_documents WHERE status = 'TRANSMITTED' AND confirmed_at IS NULL AND transmitted_at < ? AND transmitted_at >= ?",
                [$this->jst($ctx->nowUtc->subHours($h)), $this->jst($ctx->nowUtc->subDays(7))], 0);
            $obs[] = $n > 0
                ? Observation::warning("送信後 {$h} 時間を過ぎても確認されていない発注 JX 文書が {$n} 件あります。", ['documents' => 'unconfirmed'], null, ['unconfirmed' => $n], [], 'ORDER_JX_UNCONFIRMED')
                : Observation::healthy('未確認の発注 JX 文書はありません。', ['documents' => 'unconfirmed']);
        }

        return new CheckResult($obs);
    }
}
