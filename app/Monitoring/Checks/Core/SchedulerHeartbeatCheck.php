<?php

namespace App\Monitoring\Checks\Core;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * core.scheduler_heartbeat: 各ノードの Laravel スケジューラ（schedule:run）の生存。core_scheduler_heartbeats.last_tick_at が止まる → ERROR（ノード単位）。
 * 全ノード停止 → CRITICAL。算盤の収集器の生存とは別に判定する（仕様 v2.3）。
 */
class SchedulerHeartbeatCheck extends BusinessCheck
{
    use CoreJobRuns;

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('core.scheduler_heartbeat', 'CORE', 'スケジューラの生存', '各サーバーの cron（schedule:run）が 1 分ごとに動いているか', 1, 60, 60, 'RB-21', false, false, 2, true,
            ['stale_seconds' => 180, 'forget_after_hours' => 168], ['stale_seconds', 'forget_after_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $stale = (int) $ctx->config('stale_seconds', 180);
        $forget = (int) $ctx->config('forget_after_hours', 168);
        try {
            $rows = $ctx->source()->select('SELECT node, last_tick_at FROM core_scheduler_heartbeats ORDER BY node');
        } catch (\Throwable $e) {
            if ($this->isTableMissing($e)) {
                return $this->notInstalled($ctx);
            }
            throw $e;
        }
        $now = $ctx->nowLocal();
        $obs = [];
        $alive = 0;
        $down = [];
        foreach ($rows as $r) {
            $tick = $this->localTime($r->last_tick_at);
            $age = $tick ? (int) $tick->diffInSeconds($now) : PHP_INT_MAX;
            if ($age > $forget * 3600) {
                continue; // 廃止ノード
            }
            $scope = ['node' => $r->node];
            if ($age > $stale) {
                $down[] = $r->node;
                $obs[] = Observation::violation("勘定衆 {$r->node} のスケジューラ（cron）が ".intdiv($age, 60).' 分間動いていません。定時処理がすべて止まります。', $scope, null, ['age_seconds' => $age], [], 'CORE_SCHEDULER_STALE');
            } else {
                $alive++;
                $obs[] = Observation::healthy("勘定衆 {$r->node} のスケジューラは稼働（{$age} 秒前）。", $scope, null, ['age_seconds' => $age]);
            }
        }
        if ($rows === []) {
            $obs[] = Observation::unknown('スケジューラの生存記録がまだありません（Core の schedule:run が一度も heartbeat を書いていない）。', ['node' => 'all'], null, [], 'CORE_SCHEDULER_NO_RECORD');
        } elseif ($alive === 0) {
            $obs[] = Observation::violation('勘定衆の全ノードでスケジューラが停止しています（'.implode(', ', $down).'）。定時集計・確定・同期が実行されません。', ['node' => 'all'], null, ['down' => count($down)], [], 'CORE_SCHEDULER_DOWN', Severity::CRITICAL);
        } else {
            $obs[] = Observation::healthy("勘定衆のスケジューラは {$alive} ノードで稼働。", ['node' => 'all'], null, ['alive' => $alive]);
        }

        return new CheckResult($obs);
    }
}
