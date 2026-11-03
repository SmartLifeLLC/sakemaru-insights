<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.stats_sales_performance: 販売実績集計ラン（stats_sales_performance_runs、completed / failed / expired / 実行中）。
 * 直近 24h の failed → ERROR（ラン単位、自動復旧なし）。実行中で progress_updated_at が 30 分更新なし → WARNING。
 */
class SalesPerformanceCheck extends BusinessCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.stats_sales_performance', 'CORE', '販売実績集計', '販売実績集計ランの失敗・停滞', 1, 600, 600, 'RB-14', false, false, 99, true,
            ['lookback_hours' => 24, 'stall_minutes' => 30], ['lookback_hours', 'stall_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $src = $ctx->source();
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $stall = $this->jst($ctx->nowUtc->subMinutes((int) $ctx->config('stall_minutes', 30)));
        $obs = [];
        foreach ($src->select("SELECT id, name, SUBSTR(error_message, 1, 300) AS err, completed_at FROM stats_sales_performance_runs WHERE status = 'failed' AND updated_at >= ? ORDER BY id DESC LIMIT 20", [$since]) as $r) {
            $obs[] = Observation::violation("販売実績集計 #{$r->id}（".mb_substr((string) $r->name, 0, 40).'）が失敗しました。', ['run_id' => (int) $r->id], null, [], ['error' => $r->err], 'SALES_PERFORMANCE_FAILED');
        }
        $stalled = $src->select("SELECT id, progress_stage, progress_updated_at FROM stats_sales_performance_runs WHERE status NOT IN ('completed', 'failed', 'expired') AND COALESCE(progress_updated_at, started_at, created_at) < ? AND created_at >= ? ORDER BY id DESC LIMIT 20", [$stall, $since]);
        $obs[] = $stalled
            ? Observation::warning('販売実績集計 '.count($stalled).' 件の進捗が止まっています。', ['state' => 'stalled'], null, ['stalled' => count($stalled)], ['runs' => $this->sample($stalled)], 'SALES_PERFORMANCE_STALLED')
            : Observation::healthy('進捗が止まっている販売実績集計はありません。', ['state' => 'stalled']);

        return new CheckResult($obs);
    }
}
