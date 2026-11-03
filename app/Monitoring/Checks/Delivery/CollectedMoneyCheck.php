<?php

namespace App\Monitoring\Checks\Delivery;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * delivery.collected_money_check: 集金データの不正検知・管理者確認待ち（dlv_item_delivery_collected_monies）。
 * 不正検知ステータス（領収書の重複印刷・金額不一致・未印刷）→ WARNING（日別に件数集約）。need_admin_check=1 で 48 時間未確認 → WARNING。
 */
class CollectedMoneyCheck extends BusinessCheck
{
    public const ALERT_STATUSES = ['RECEIPT_PRINTED_MULTIPLE_TIME', 'AMOUNT_NOT_MATCHED_RECEIPT', 'RECEIPT_NOT_PRINTED'];

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('delivery.collected_money_check', 'DELIVERY', '集金チェック', '集金の不正検知・管理者の確認待ち', 1, 1800, 1800, 'RB-17', false, true, 1, true,
            ['unchecked_hours' => 48], ['unchecked_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $date = $ctx->nowLocal()->subDay()->toDateString();
        $placeholders = implode(',', array_fill(0, count(self::ALERT_STATUSES), '?'));
        $rows = $src->select("SELECT cash_fraud_detection_status AS s, COUNT(*) AS c FROM dlv_item_delivery_collected_monies WHERE deleted_at IS NULL AND admin_approved = 0 AND cash_fraud_detection_status IN ({$placeholders}) AND created_at >= ? AND created_at < ? GROUP BY cash_fraud_detection_status",
            array_merge(self::ALERT_STATUSES, [$date.' 00:00:00', $ctx->nowLocal()->toDateString().' 00:00:00']));
        $by = [];
        foreach ($rows as $r) {
            $by[$r->s] = (int) $r->c;
        }
        $obs = [];
        $scope = ['business_date' => $date, 'state' => 'fraud_detection'];
        $obs[] = $by
            ? Observation::warning("集金の確認が必要なデータが {$date} に ".array_sum($by).' 件あります（'.implode(' / ', array_map(fn ($k, $v) => self::label($k).' '.$v, array_keys($by), $by)).'）。', $scope, $date, $by, [], 'COLLECTED_MONEY_ALERT')
            : Observation::healthy("{$date} の集金に要確認データはありません。", $scope, $date);

        $h = (int) $ctx->config('unchecked_hours', 48);
        $unchecked = (int) $src->scalar('SELECT COUNT(*) FROM dlv_item_delivery_collected_monies WHERE deleted_at IS NULL AND need_admin_check = 1 AND admin_check_date IS NULL AND created_at >= ? AND created_at < ?', [$this->jst($ctx->nowUtc->subDays(90)), $this->jst($ctx->nowUtc->subHours($h))], 0);
        $obs[] = $unchecked > 0
            ? Observation::warning("管理者確認が {$h} 時間以上されていない集金データが {$unchecked} 件あります。", ['state' => 'admin_check'], null, ['unchecked' => $unchecked], [], 'COLLECTED_MONEY_UNCHECKED')
            : Observation::healthy('管理者確認待ちの集金データはありません。', ['state' => 'admin_check']);

        return new CheckResult($obs);
    }

    public static function label(string $s): string
    {
        return match ($s) {
            'RECEIPT_PRINTED_MULTIPLE_TIME' => '領収書の重複印刷',
            'AMOUNT_NOT_MATCHED_RECEIPT' => '領収書と金額不一致',
            'RECEIPT_NOT_PRINTED' => '領収書未印刷',
            default => $s,
        };
    }
}
