<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Node;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/** monitor.metrics_stale: 有効ノードから資源データが届いているか（V-069）。指紋はノード（日付なし）。 */
class MetricsStaleCheck extends AbstractCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.metrics_stale', 'INSIGHTS', '資源収集の停止', '各ノードから資源データが届いているか', 1, 120, 300, 'RB-19', false, false, 1, true,
            ['warning_seconds' => (int) config('monitoring.resources.metrics_stale_warning_seconds', 180), 'error_seconds' => (int) config('monitoring.resources.metrics_stale_error_seconds', 600)],
            ['warning_seconds', 'error_seconds']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $warn = (int) $ctx->config('warning_seconds', 180);
        $err = (int) $ctx->config('error_seconds', 600);
        $obs = [];
        foreach (Node::query()->where('enabled', true)->orderBy('node_code')->get() as $n) {
            $scope = ['node' => $n->node_code];
            $age = app(\App\Monitoring\Services\DeployWindow::class)->effectiveAge($n->last_heartbeat_at ? (int) CarbonImmutable::instance($n->last_heartbeat_at)->diffInSeconds($ctx->nowUtc) : null, $ctx->nowUtc);
            $name = $n->display_name ?: $n->node_code;
            if ($age === null || $age > $err) {
                $obs[] = Observation::violation("ノード {$name} から資源データが".($age === null ? '届いていません' : intdiv($age, 60).' 分届いていません').'（サーバー停止または収集器停止）。', $scope, null, ['age_seconds' => $age], [], 'MONITOR_METRICS_STALE');
            } elseif ($age > $warn) {
                $obs[] = Observation::warning("ノード {$name} の資源データが ".intdiv($age, 60).' 分遅れています。', $scope, null, ['age_seconds' => $age], [], 'MONITOR_METRICS_STALE');
            } else {
                $obs[] = Observation::healthy("ノード {$name} の資源データは {$age} 秒前に受信。", $scope, null, ['age_seconds' => $age]);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('資源収集ノードは登録されていません。', ['node' => 'none'])]);
    }
}
