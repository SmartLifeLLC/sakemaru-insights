<?php

namespace App\Monitoring\Resources;

use App\Filament\Monitor\Pages\Dashboard;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Node;
use App\Monitoring\Enums\WorkflowStatus;
use Carbon\CarbonImmutable;

/** ノード一覧・ダッシュボードの「システム負荷」ブロック用のデータ（最大 2 台を並べて表示）。監視テーブルだけを読む。 */
class NodeOverview
{
    /** @return array<int,array<string,mixed>> */
    public static function rows(string $range): array
    {
        $now = CarbonImmutable::now('UTC');
        $open = Incident::query()->whereIn('check_key', ['node.resources', 'monitor.metrics_stale'])->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->orderByDesc('id')->limit(200)->get(['public_id', 'title', 'severity', 'scope_json', 'last_seen_at']);
        $out = [];
        foreach (Node::query()->orderByDesc('enabled')->orderBy('node_code')->get() as $n) {
            $l = (array) $n->latest_json;
            $fresh = isset($l['ts']) && ($now->getTimestamp() - (int) $l['ts']) <= 300;
            $eval = $fresh ? ThresholdEvaluator::evaluate($l, ThresholdEvaluator::resolve($n->thresholds_json), $now->getTimestamp()) : [];
            $cores = max(1, (int) ($l['cores'] ?? 1));
            $series = $n->enabled ? NodeSeries::agg($n, $range, $now, $cores) : ['labels' => []];
            $openList = $open->filter(fn ($i) => ($i->scope_json['node'] ?? null) === $n->node_code);
            $out[] = [
                'series' => $series, 'cores' => $cores, 'eval' => $eval, 'open_list' => $openList,
                'required_procs' => array_filter((array) ($l['processes'] ?? []), fn ($p) => ! empty($p['required'])),
                'node' => $n, 'fresh' => $fresh, 'latest' => $l,
                'cpu' => $l['current']['cpu'] ?? null, 'mem' => $l['current']['mem_used_pct'] ?? null, 'disk' => $l['current']['disk_used_pct_max'] ?? null,
                'load' => isset($l['current']['load1']) ? round($l['current']['load1'] / $cores * 100) : null,
                'levels' => ['cpu' => Dashboard::worstLevel($eval, 'cpu_pct'), 'mem' => Dashboard::worstLevel($eval, 'mem_available_pct'), 'disk' => Dashboard::worstLevel($eval, 'disk_used_pct'), 'load' => Dashboard::worstLevel($eval, 'load_per_core')],
                'breaches' => collect($eval)->where('level', '!=', 'ok')->count(),
                'open' => $openList->count(),
            ];
        }

        return $out;
    }
}
