<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\ProbeResult;
use App\Models\Monitoring\Target;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Enums\TargetKind;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\FeatureRegistry;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/** monitor.probe_stale: 有効なプローブ対象があるのに、直近 5 分（設定）プローブ結果が記録されていない（V-052）。 */
class ProbeStaleCheck extends AbstractCheck
{
    public function __construct(private readonly FeatureRegistry $features) {}

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.probe_stale', 'INSIGHTS', 'HTTP プローブの停止', 'HTTP プローブが定期的に実行されているか', 1, 120, 300, 'RB-20', false, false, 1, true,
            ['stale_minutes' => (int) config('monitoring.probe.stale_minutes', 5)], ['stale_minutes']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $ids = Target::query()->where('kind', TargetKind::HTTP_PROBE->value)->where('enabled', true)->get()
            ->filter(fn (Target $t) => $this->features->targetActive($t))->pluck('id')->all();
        $probeFeature = \App\Models\Monitoring\Feature::query()->where('feature_key', 'insights.probe')->first();
        if (! $ids || ($probeFeature && ! $probeFeature->enabled)) {
            return new CheckResult([Observation::healthy('有効な HTTP プローブ対象はありません。', ['probe' => 'stale'])]);
        }
        $stale = (int) $ctx->config('stale_minutes', 5);
        $last = ProbeResult::query()->whereIn('target_id', $ids)->max('checked_at');
        $age = app(\App\Monitoring\Services\DeployWindow::class)->effectiveAge($last ? (int) CarbonImmutable::parse($last, 'UTC')->diffInSeconds($ctx->nowUtc) : null, $ctx->nowUtc);
        if ($age === null || $age > $stale * 60) {
            return new CheckResult([Observation::violation('HTTP プローブが'.($age === null ? '一度も実行されていません' : intdiv($age, 60).' 分実行されていません').'（対象 '.count($ids).' 件）。',
                ['probe' => 'stale'], null, ['age_seconds' => $age, 'targets' => count($ids)], [], 'MONITOR_PROBE_STALE')]);
        }

        return new CheckResult([Observation::healthy("HTTP プローブは {$age} 秒前に実行されています。", ['probe' => 'stale'], null, ['age_seconds' => $age])]);
    }
}
