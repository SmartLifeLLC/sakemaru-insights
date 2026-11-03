<?php

namespace App\Monitoring\Checks\Monitor;

use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\MonitorHealthService;
use App\Monitoring\Services\Observation;

/** monitor.storage: 退避量・削除遅延・容量（仕様書 15.3 の閾値）。 */
class StorageCheck extends AbstractCheck
{
    public function __construct(private readonly MonitorHealthService $health) {}

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.storage', 'INSIGHTS', '保存・削除・容量', '退避領域の使用率、正常ログ削除の遅延、緊急ファイル', 1, 900, 900, 'RB-10', false, false, 1);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $obs = [];
        $u = $this->health->spoolUsage();
        $ratio = $u['spool_ratio'];
        $pct = round($ratio * 100, 1);
        $scope = ['resource' => 'spool'];
        if ($ratio >= (float) config('monitoring.collector.spool_error_ratio', 0.85)) {
            $obs[] = Observation::violation("退避領域の使用率が {$pct}% です。", $scope, null, $u, [], 'MONITOR_SPOOL_FULL');
        } elseif ($ratio >= (float) config('monitoring.collector.spool_warning_ratio', 0.70)) {
            $obs[] = Observation::warning("退避領域の使用率が {$pct}% です。", $scope, null, $u, [], 'MONITOR_SPOOL_HIGH');
        } else {
            $obs[] = Observation::healthy("退避領域の使用率は {$pct}% です。", $scope, null, ['spool_bytes' => $u['spool_bytes']]);
        }
        $lag = $this->health->retentionLagHours($ctx->nowUtc);
        $scope = ['resource' => 'retention'];
        if ($lag > (int) config('monitoring.retention.backlog_grace_hours', 24)) {
            $obs[] = Observation::violation("正常ログの削除が {$lag} 時間遅延しています。", $scope, null, ['lag_hours' => $lag], [], 'RETENTION_BACKLOG');
        } else {
            $obs[] = Observation::healthy('正常ログの削除は予算内で追い付いています。', $scope, null, ['lag_hours' => $lag]);
        }
        $em = $this->health->emergencyLogTail();
        $scope = ['resource' => 'emergency'];
        if ($em) {
            $obs[] = Observation::violation('緊急ファイルに保存不能の記録があります。', $scope, null, [], ['last_line' => mb_substr($em, 0, 300)], 'MONITOR_STORE_FAILED');
        } else {
            $obs[] = Observation::healthy('緊急ファイルに記録はありません。', $scope);
        }

        return new CheckResult($obs);
    }
}
