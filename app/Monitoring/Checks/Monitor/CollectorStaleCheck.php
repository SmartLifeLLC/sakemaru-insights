<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Collector;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use App\Monitoring\Services\SiteRegistry;
use Carbon\CarbonImmutable;

/** monitor.collector_stale: 登録収集器の heartbeat 継続。5分超 WARNING、10分超 ERROR。日付を含めず collector を scope にする。 */
class CollectorStaleCheck extends AbstractCheck
{
    public function __construct(private readonly SiteRegistry $sites) {}

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.collector_stale', 'INSIGHTS', '収集器 heartbeat', '登録収集器の heartbeat が継続しているか', 1, 300, 300, 'RB-08', false, false, 1, true, ['warning_seconds' => 300, 'error_seconds' => 600], ['warning_seconds', 'error_seconds']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $obs = [];
        $warn = (int) $ctx->config('warning_seconds', 300);
        $err = (int) $ctx->config('error_seconds', 600);
        foreach (Collector::query()->get() as $c) {
            if (str_contains((string) $c->node_code, '@')) {
                continue; // 内部収集器は対象外
            }
            $site = $this->sites->siteById((int) $c->site_id)?->application_code ?? '?';
            $scope = ['collector' => $c->collector_uuid, 'node' => $c->node_code, 'site' => $site];
            $age = $c->last_heartbeat_at ? (int) CarbonImmutable::instance($c->last_heartbeat_at)->diffInSeconds($ctx->nowUtc) : null;
            if ($age === null) {
                $obs[] = Observation::violation("収集器 {$site}@{$c->node_code} から heartbeat を一度も受信していません。", $scope, null, [], [], 'MONITOR_COLLECTOR_NEVER');
            } elseif ($age > $err) {
                $obs[] = Observation::violation("収集器 {$site}@{$c->node_code} の heartbeat が".intdiv($age, 60).'分停止しています。', $scope, null, ['age_seconds' => $age], [], 'MONITOR_COLLECTOR_STALE');
            } elseif ($age > $warn) {
                $obs[] = Observation::warning("収集器 {$site}@{$c->node_code} の heartbeat が".intdiv($age, 60).'分遅延しています。', $scope, null, ['age_seconds' => $age], [], 'MONITOR_COLLECTOR_STALE');
            } else {
                $obs[] = Observation::healthy("収集器 {$site}@{$c->node_code} は正常（{$age}秒前）。", $scope, null, ['age_seconds' => $age]);
            }
        }
        if (! $obs) {
            $obs[] = Observation::healthy('登録済みの外部収集器はありません（アプリログ収集は未設定）。', ['collector' => 'none']);
        }

        return new CheckResult($obs);
    }
}
