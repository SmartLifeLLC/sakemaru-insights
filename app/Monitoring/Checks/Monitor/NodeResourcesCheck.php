<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Node;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Resources\ThresholdEvaluator;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * node.resources: ノード別しきい値（継続時間付き）で CPU・I/O 待ち・負荷・メモリ・スワップ・ディスク・inode を判定（v2 第5章）。
 * 指紋 = node + metric（+ mount）、日付なし。連続 3 回の正常で RECOVERED（V-067）。資源データが古いノードは判定しない（monitor.metrics_stale が扱う）。
 */
class NodeResourcesCheck extends AbstractCheck
{
    use ActiveScopes;

    public function definition(): CheckDefinition
    {
        return new CheckDefinition('node.resources', 'INSIGHTS', 'サーバー資源・プロセス', 'CPU・メモリ・ディスク・負荷がしきい値内か、Apache・PHP-FPM・supervisord が動いているか', 1, 60, 180, 'RB-19', false, false, 3, true, [], []);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $active = $this->activeScopes('node.resources');
        $staleAfter = (int) config('monitoring.resources.metrics_stale_warning_seconds', 180);
        $nowTs = $ctx->nowUtc->getTimestamp();
        $obs = [];
        foreach (Node::query()->where('enabled', true)->orderBy('node_code')->get() as $node) {
            $latest = (array) $node->latest_json;
            if (! $latest || ($nowTs - (int) ($latest['ts'] ?? 0)) > $staleAfter) {
                continue;
            }
            $name = $node->display_name ?: $node->node_code;
            $results = ThresholdEvaluator::evaluate($latest, ThresholdEvaluator::resolve($node->thresholds_json), $nowTs);
            $bad = 0;
            foreach ($results as $r) {
                $scope = $r['metric'] === 'process_missing'
                    ? ['node' => $node->node_code, 'metric' => 'process', 'process' => $r['mount']]
                    : array_filter(['node' => $node->node_code, 'metric' => $r['metric'], 'mount' => $r['mount']]);
                $unit = match ($r['metric']) {
                    'load_per_core' => '',
                    'process_missing' => ' 件',
                    default => '%',
                };
                $metrics = ['value' => $r['value'], 'threshold' => $r['threshold'], 'duration_seconds' => $r['duration']];
                $dur = $r['duration'] > 0 ? '（'.intdiv($r['duration'], 60).' 分継続）' : '';
                $cmp = $r['metric'] === 'mem_available_pct' ? '下回っています' : '超えています';
                if ($r['level'] === 'ok') {
                    if (isset($active[$this->scopeKey($scope)])) {
                        $obs[] = Observation::healthy("{$name} の{$r['label']}は正常（{$r['value']}{$unit}）。", $scope, null, $metrics);
                    }

                    continue;
                }
                $bad++;
                if ($r['metric'] === 'process_missing') {
                    $obs[] = Observation::violation("{$name} で {$r['label']}が確認できません（".intdiv((int) $r['missing_seconds'], 60).' 分 '.((int) $r['missing_seconds'] % 60).' 秒）。', $scope, null,
                        ['process_count' => 0, 'missing_seconds' => $r['missing_seconds']], ['process' => $r['mount']], 'NODE_PROCESS_DOWN', Severity::CRITICAL);

                    continue;
                }
                $summary = "{$name} の{$r['label']}が {$r['value']}{$unit} で、しきい値 {$r['threshold']}{$unit} を{$cmp}{$dur}。";
                $obs[] = $r['level'] === 'warning'
                    ? Observation::warning($summary, $scope, null, $metrics, [], 'NODE_'.strtoupper($r['metric']))
                    : Observation::violation($summary, $scope, null, $metrics, [], 'NODE_'.strtoupper($r['metric']), $r['level'] === 'critical' ? Severity::CRITICAL : Severity::ERROR);
            }
            if ($bad === 0) {
                $obs[] = Observation::healthy("{$name} の資源は全てしきい値内です。", ['node' => $node->node_code, 'metric' => 'summary'], null, ['cpu' => $latest['current']['cpu'] ?? null, 'mem_used_pct' => $latest['current']['mem_used_pct'] ?? null]);
            }
        }

        return new CheckResult($obs ?: [Observation::healthy('判定できる資源データはありません（ノード未登録または収集停止）。', ['node' => 'none'])]);
    }
}
