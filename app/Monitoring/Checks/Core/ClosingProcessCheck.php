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
 */
class ClosingProcessCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.closing_process', 'CORE', '締め処理', '仕入・得意先の締め処理の失敗', 1, 600, 600, 'RB-15', false, true, 1, true, ['lookback_hours' => 24], ['lookback_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $rows = $ctx->source()->select('SELECT c.id, c.process_type, c.closing_date, c.status, c.error_count, c.total_count, SUBSTR(c.error_message, 1, 300) AS err FROM closing_processes c
            JOIN (SELECT MAX(id) AS max_id FROM closing_processes WHERE updated_at >= ? GROUP BY process_type, closing_date) m ON m.max_id = c.id', [$since]);
        $obs = [];
        foreach ($rows as $r) {
            $date = $r->closing_date ? (string) $r->closing_date : null;
            $scope = ['process' => $r->process_type, 'business_date' => $date];
            $label = match ($r->process_type) {
                'supplier_bill' => '仕入締め', 'buyer_bill' => '得意先締め', default => $r->process_type
            };
            if ($r->status === 'failed') {
                $obs[] = Observation::violation("{$label}（締め日 {$date}）が失敗しました（#{$r->id}、エラー {$r->error_count}/{$r->total_count}）。", $scope, $date, ['error_count' => (int) $r->error_count, 'total' => (int) $r->total_count], ['error' => $r->err], 'CLOSING_FAILED');
            } elseif ($r->status === 'completed') {
                $obs[] = Observation::healthy("{$label}（締め日 {$date}）は完了。", $scope, $date);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('直近の締め処理はありません。', ['process' => 'none'])]);
    }
}
