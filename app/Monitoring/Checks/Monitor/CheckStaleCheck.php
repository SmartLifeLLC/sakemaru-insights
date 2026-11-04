<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Check as CheckModel;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use App\Monitoring\Services\SiteRegistry;
use Carbon\CarbonImmutable;

/** monitor.check_stale: 有効チェックの最終完了が interval＋grace 内。未完了 ERROR。 */
class CheckStaleCheck extends AbstractCheck
{
    public function __construct(private readonly SiteRegistry $sites) {}

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.check_stale', 'INSIGHTS', 'チェック実行の遅延', '有効なチェックが interval＋grace 内に完了しているか', 1, 300, 300, 'RB-08', false, false, 1);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $obs = [];
        $sourceConfigured = (bool) config('database.connections.monitor_source.database');
        foreach (CheckModel::query()->where('enabled', true)->where('implemented', true)->get() as $c) {
            if ($c->check_key === $this->definition()->key) {
                continue;
            }
            $site = $this->sites->siteById((int) $c->site_id)?->application_code ?? '?';
            if (! app(\App\Monitoring\Services\FeatureRegistry::class)->gate($c)['active']) {
                continue; // 未監視（サービス停止・機能未使用・項目停止）は遅延扱いしない
            }
            if (! $sourceConfigured && $site !== 'INSIGHTS') {
                continue; // 業務DB未接続は MONITOR_SOURCE_NOT_CONFIGURED で別途表示
            }
            $scope = ['check' => $c->check_key, 'site' => $site];
            $limit = (int) $c->interval_seconds + (int) $c->grace_seconds;
            $last = $c->last_completed_at ? CarbonImmutable::instance($c->last_completed_at) : null;
            $age = app(\App\Monitoring\Services\DeployWindow::class)->effectiveAge($last ? (int) $last->diffInSeconds($ctx->nowUtc) : null, $ctx->nowUtc); // デプロイ窓の分は差し引く
            $created = $c->created_at ? (int) CarbonImmutable::instance($c->created_at)->diffInSeconds($ctx->nowUtc) : 0;
            if ($age === null && $created < $limit) {
                continue; // 登録直後
            }
            if ($c->paused_until && CarbonImmutable::instance($c->paused_until)->greaterThan($ctx->nowUtc)) {
                $obs[] = Observation::warning("{$site} {$c->check_key} は {$c->pause_reason} のため休止中（".CarbonImmutable::instance($c->paused_until)->setTimezone('Asia/Tokyo')->format('H:i').' まで）。', $scope, null, [], [], 'MONITOR_CHECK_PAUSED');
            } elseif ($age === null || $age > $limit) {
                $obs[] = Observation::violation("{$site} {$c->check_key} が ".($age === null ? '一度も' : intdiv($age, 60).'分間').'完了していません（上限 '.intdiv($limit, 60).'分）。', $scope, null, ['age_seconds' => $age], [], 'MONITOR_CHECK_STALE');
            } else {
                $obs[] = Observation::healthy("{$site} {$c->check_key} は期限内に完了（".intdiv($age, 60).'分前）。', $scope, null, ['age_seconds' => $age]);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('監視対象のチェックはありません。', ['check' => 'none'])]);
    }
}
