<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Services\CheckContext;

/**
 * Trade チェック共通。業務テーブルは ret_ プレフィックス、DB 保存時刻はアプリ時刻（Asia/Tokyo）。
 * 店舗コードはゼロ埋め差（'000001' と '001'）があるため数値正規化して比較する。
 */
abstract class TradeCheck extends AbstractCheck
{
    protected function normalizeStoreCode(?string $code): string
    {
        $c = trim((string) $code);

        return ctype_digit($c) ? (string) (int) $c : $c;
    }

    /** 対象営業日の daily_close 受信が完了しているか（watermark）。 */
    protected function dailyCloseWatermark(CheckContext $ctx, string $date): array
    {
        $rows = $ctx->source()->select(
            "SELECT execution_status, MAX(id) AS max_id, COUNT(*) AS c FROM ret_tm_receive_requests WHERE receive_mode = 'daily_close' AND (target_business_date = ? OR (target_business_date IS NULL AND scheduled_at >= ? AND scheduled_at < ?)) GROUP BY execution_status",
            [$date, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +2 day')).' 00:00:00'],
        );
        $byStatus = [];
        $maxId = 0;
        foreach ($rows as $r) {
            $byStatus[$r->execution_status] = (int) $r->c;
            $maxId = max($maxId, (int) $r->max_id);
        }

        return ['by_status' => $byStatus, 'max_id' => $maxId, 'complete' => ($byStatus['SUCCESS'] ?? 0) > 0 && ! array_diff_key($byStatus, ['SUCCESS' => 1, 'CANCELLED' => 1])];
    }

    /**
     * 状態別件数のうち「完了」とみなす件数。表記ゆれ（SUCCESS / sent / succeeded / completed …）を大文字小文字を無視して数える。
     * 本番（華）の実値: ret_report_mail_send_runs / ret_intage_pos_send_runs = sent|failed、ret_company_daily_sales_report_runs = succeeded|running|failed。
     */
    public static function completedCount(array $byStatus): int
    {
        $n = 0;
        foreach ($byStatus as $status => $count) {
            if (in_array(strtolower((string) $status), ['success', 'succeeded', 'sent', 'completed', 'done'], true)) {
                $n += (int) $count;
            }
        }

        return $n;
    }

    protected function nowLocalString(CheckContext $ctx): string
    {
        return $ctx->nowLocal()->format('Y-m-d H:i:s');
    }
}
