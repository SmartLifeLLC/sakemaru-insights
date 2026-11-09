<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.closing_process: 締め処理（closing_processes: supplier_bill / buyer_bill 等、status completed / failed）。
 * 処理種別×締め日ごとの最新状態: failed → ERROR、その後 completed → 復旧。
 *
 * 操作上の拒否（Core の StatusCode。closing_process_logs の error 行の context.status_code）は障害ではないので通知しない:
 *   74130 ALREADY_CLOSED_BILL_PARTNER_EXISTS（既に締め済みの取引先 = 二重締めの防止）
 *   72001 CLOSING_TRADES_NOT_EXISTS（締め対象の取引なし）
 *   75100 NO_TARGET（対象なし）
 *   76010 CLOSING_PROCESS_RUNNING（別の締め処理が実行中）
 *   76011 CLOSING_CANCELED（利用者が取り消した）
 * 2026-11-09 MON-000084（取消前に再締め → 74130）で通知されたため。直近 120 日の失敗 59 件はすべて 74130 / 72001。
 */
class ClosingProcessCheck extends BusinessCheck
{
    public const REJECTION_CODES = [
        74130 => '既に締め済みの取引先（二重締めの防止）',
        72001 => '締め対象の取引なし',
        75100 => '対象なし',
        76010 => '別の締め処理が実行中',
        76011 => '利用者による取消',
    ];

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.closing_process', 'CORE', '締め処理', '仕入・得意先の締め処理の失敗（二重締めの防止など操作上の拒否は除く）', 1, 600, 600, 'RB-15', false, true, 1, true,
            ['lookback_hours' => 24, 'ignore_status_codes' => array_keys(self::REJECTION_CODES)], ['lookback_hours', 'ignore_status_codes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $rows = $ctx->source()->select('SELECT c.id, c.process_type, c.closing_date, c.status, c.error_count, c.total_count, SUBSTR(c.error_message, 1, 300) AS err FROM closing_processes c
            JOIN (SELECT MAX(id) AS max_id FROM closing_processes WHERE updated_at >= ? GROUP BY process_type, closing_date) m ON m.max_id = c.id', [$since]);
        $codes = $this->statusCodes($ctx, array_values(array_map(fn ($r) => (int) $r->id, array_filter($rows, fn ($r) => $r->status === 'failed'))));
        $ignore = array_map('intval', (array) $ctx->config('ignore_status_codes', array_keys(self::REJECTION_CODES)));
        $obs = [];
        foreach ($rows as $r) {
            $date = $r->closing_date ? (string) $r->closing_date : null;
            $scope = ['process' => $r->process_type, 'business_date' => $date];
            $label = match ($r->process_type) {
                'supplier_bill' => '仕入締め', 'buyer_bill' => '得意先締め', default => $r->process_type
            };
            if ($r->status === 'failed') {
                $code = $codes[(int) $r->id] ?? null;
                if ($code !== null && in_array($code, $ignore, true)) {
                    // 操作上の拒否: 正常として記録（監視ログには残る）。既存の案件があれば復旧扱いになる
                    $why = self::REJECTION_CODES[$code] ?? 'status_code '.$code;
                    $obs[] = Observation::healthy("{$label}（締め日 {$date}）の最新の実行 #{$r->id} は操作上の拒否でした（{$why}、status_code {$code}）。障害ではありません。", $scope, $date, ['status_code' => $code]);

                    continue;
                }
                $obs[] = Observation::violation("{$label}（締め日 {$date}）が失敗しました（#{$r->id}、エラー {$r->error_count}/{$r->total_count}".($code !== null ? "、status_code {$code}" : '').'）。', $scope, $date, ['error_count' => (int) $r->error_count, 'total' => (int) $r->total_count] + ($code !== null ? ['status_code' => $code] : []), ['error' => $r->err], 'CLOSING_FAILED');
            } elseif ($r->status === 'completed') {
                $obs[] = Observation::healthy("{$label}（締め日 {$date}）は完了。", $scope, $date);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('直近の締め処理はありません。', ['process' => 'none'])]);
    }

    /**
     * 失敗した実行の Core status_code（closing_process_logs の error 行 context.status_code）。索引 closing_process_logs_process_idx を使う。
     *
     * @param  list<int>  $ids
     * @return array<int,int>
     */
    private function statusCodes(CheckContext $ctx, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $place = implode(',', array_fill(0, count($ids), '?'));
        try {
            $logs = $ctx->source()->select("SELECT closing_process_id, context FROM closing_process_logs WHERE closing_process_id IN ({$place}) AND level = 'error' ORDER BY id", $ids);
        } catch (\Illuminate\Database\QueryException) {
            return []; // ログ表が無い Core（旧版）では従来どおり、失敗はすべて ERROR
        }
        foreach ($logs as $l) {
            $c = json_decode((string) $l->context, true);
            if (is_array($c) && isset($c['status_code']) && is_numeric($c['status_code'])) {
                $out[(int) $l->closing_process_id] = (int) $c['status_code'];
            }
        }

        return $out;
    }
}
