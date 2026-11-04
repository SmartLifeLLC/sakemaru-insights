<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Node;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Resources\NodeSeries;
use App\Monitoring\Resources\ThresholdEvaluator;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/** ノード一覧（v2 第5章 / 第6.3）。ノードは各サーバーの .env で自己登録される（画面から追加しない）。 */
class Nodes extends MonitorPage
{
    protected static ?string $title = 'ノード';

    protected static ?string $slug = 'nodes';

    protected static string $navKey = 'nodes';

    protected static string $description = 'サーバーごとの CPU・メモリ・ディスク・負荷。ノードは各サーバーの .env（MONITOR_NODE_CODE）から自動登録されます。';

    protected string $view = 'monitor.pages.nodes';

    /** 表示範囲（AWS のモニタリングと同じ段階: 1h / 3h / 12h / 1d / 3d / 1w） */
    #[Url]
    public string $range = '1d';

    public function setRange(string $r): void
    {
        $this->range = isset(NodeSeries::LABELS[$r]) ? $r : '1d';
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(): array
    {
        $now = CarbonImmutable::now('UTC');
        $open = Incident::query()->whereIn('check_key', ['node.resources', 'monitor.metrics_stale'])->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->orderByDesc('id')->limit(200)->get(['public_id', 'title', 'severity', 'scope_json', 'last_seen_at']);
        $out = [];
        foreach (Node::query()->orderByDesc('enabled')->orderBy('node_code')->get() as $n) {
            $l = (array) $n->latest_json;
            $fresh = isset($l['ts']) && ($now->getTimestamp() - (int) $l['ts']) <= 300;
            $eval = $fresh ? ThresholdEvaluator::evaluate($l, ThresholdEvaluator::resolve($n->thresholds_json), $now->getTimestamp()) : [];
            $cores = max(1, (int) ($l['cores'] ?? 1));
            $series = $n->enabled ? NodeSeries::agg($n, $this->range, $now, $cores) : ['labels' => []];
            $openList = $open->filter(fn ($i) => ($i->scope_json['node'] ?? null) === $n->node_code);
            $out[] = [
                'series' => $series, 'cores' => $cores, 'eval' => $eval, 'open_list' => $openList,
                'required_procs' => array_filter((array) ($l['processes'] ?? []), fn ($p) => ! empty($p['required'])),
                'node' => $n, 'fresh' => $fresh, 'latest' => $l,
                'cpu' => $l['current']['cpu'] ?? null, 'mem' => $l['current']['mem_used_pct'] ?? null, 'disk' => $l['current']['disk_used_pct_max'] ?? null,
                'load' => isset($l['current']['load1']) ? round($l['current']['load1'] / $cores * 100) : null,
                'levels' => ['cpu' => Dashboard::worstLevel($eval, 'cpu_pct'), 'mem' => Dashboard::worstLevel($eval, 'mem_available_pct'), 'disk' => Dashboard::worstLevel($eval, 'disk_used_pct'), 'load' => Dashboard::worstLevel($eval, 'load_per_core')],
                'breaches' => collect($eval)->where('level', '!=', 'ok')->count(),
                'open' => $open->filter(fn ($i) => ($i->scope_json['node'] ?? null) === $n->node_code)->count(),
            ];
        }

        return $out;
    }
}
