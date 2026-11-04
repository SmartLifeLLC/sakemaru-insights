<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\LogEntry;
use App\Models\Monitoring\Node;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Resources\ThresholdEvaluator;
use App\Monitoring\Services\IncidentQueryService;
use App\Monitoring\Services\LogQueryService;
use App\Monitoring\Services\StatsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/** ダッシュボード（v2 第6.3）。元データは counters・未解決インシデント（索引）・最新ログ 5 件・ノードの直近値だけ。 */
class Dashboard extends MonitorPage
{
    /** システム負荷ブロックの表示範囲（ノード一覧と同じ段階） */
    #[\Livewire\Attributes\Url]
    public string $nodeRange = '1d';

    public function setNodeRange(string $r): void
    {
        $this->nodeRange = isset(\App\Monitoring\Resources\NodeSeries::LABELS[$r]) ? $r : '1d';
    }

    /** @return array<int,array<string,mixed>> */
    public function nodeRows(): array
    {
        return \App\Monitoring\Resources\NodeOverview::rows($this->nodeRange);
    }

    protected static ?string $title = 'ダッシュボード';

    protected static ?string $slug = '/';

    protected static string $navKey = 'dashboard';

    protected static string $description = 'システムの状態を把握し、必要な対応へ。';

    protected string $view = 'monitor.pages.dashboard';

    #[Url]
    public string $period = '24h';

    #[Url]
    public ?string $site = null;

    #[Url]
    public bool $compare = true;

    public function setPeriod(string $p): void
    {
        $this->period = array_key_exists($p, StatsService::PERIODS) ? $p : '24h';
    }

    /** @return int[]|null */
    public function scopeSiteIds(): ?array
    {
        $ids = $this->viewableSiteIds();
        if ($this->site) {
            $s = $this->sites()->site($this->site);

            return $s && in_array((int) $s->id, $ids, true) ? [(int) $s->id] : $ids;
        }

        return $ids;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $now = CarbonImmutable::now('UTC');
        $stats = app(StatsService::class);
        $ids = $this->scopeSiteIds();
        $kpi = $stats->kpis($this->period, $ids, $now);
        $series = $stats->series($this->period, $ids, $now);
        $prev = $stats->series($this->period, $ids, $now->subHours(StatsService::hours($this->period)));
        $anom = fn ($b) => $b['WARNING'] + $b['ERROR'] + $b['CRITICAL'];
        $labelFmt = $this->period === '24h' ? 'H:i' : ($this->period === '7d' ? 'm/d H時' : 'm/d');

        $unresolved = Incident::query()->selectRaw('severity, COUNT(*) as c')->whereIn('site_id', $ids)
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->groupBy('severity')->pluck('c', 'severity')->all();
        $critical = Incident::query()->select(IncidentQueryService::COLUMNS)->whereIn('site_id', $ids)
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->where('severity', 'CRITICAL')->orderByDesc('id')->limit(3)->get();
        $attention = Incident::query()->select(IncidentQueryService::COLUMNS)->whereIn('site_id', $ids)
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())
            ->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 0 WHEN 'ERROR' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN workflow_status = 'OPEN' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(5)->get();
        $logs = LogEntry::query()->select(LogQueryService::COLUMNS)->whereIn('site_id', $ids)->orderByDesc('id')->limit(5)->get();

        // サービス別（稼働バー・最悪の未解決）
        $bars = $stats->serviceBars($now);
        $worst = Incident::query()->selectRaw('site_id, MIN(CASE severity WHEN \'CRITICAL\' THEN 0 WHEN \'ERROR\' THEN 1 WHEN \'WARNING\' THEN 2 ELSE 3 END) AS w, COUNT(*) AS c')
            ->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->groupBy('site_id')->get()->keyBy('site_id');
        $services = [];
        foreach ($this->siteOptions() as $s) {
            if ($this->site && $s['code'] !== $this->site) {
                continue;
            }
            $w = $worst->get($s['id']);
            $state = match (true) {
                ! $this->sites()->siteById($s['id'])?->enabled => ['neutral', '停止'],
                $w && (int) $w->w === 0 => ['red', '重大'],
                $w && (int) $w->w === 1 => ['red', '障害'],
                $w && (int) $w->w === 2 => ['amber', '警告'],
                collect($bars[$s['id']] ?? [])->every(fn ($b) => $b['state'] === 'none') => ['neutral', '未監視'],
                default => ['green', '正常'],
            };
            $services[] = $s + ['bars' => $bars[$s['id']] ?? [], 'state' => $state, 'open' => (int) ($w->c ?? 0)];
        }

        // ノードカード
        $nodes = [];
        foreach (Node::query()->where('enabled', true)->orderBy('node_code')->limit(12)->get() as $n) {
            $l = (array) $n->latest_json;
            $fresh = isset($l['ts']) && ($now->getTimestamp() - (int) $l['ts']) <= 300;
            $th = ThresholdEvaluator::resolve($n->thresholds_json);
            $eval = $fresh ? ThresholdEvaluator::evaluate($l, $th, $now->getTimestamp()) : [];
            $level = fn (string $k) => self::worstLevel($eval, $k);
            $cores = max(1, (int) ($l['cores'] ?? 1));
            $nodes[] = [
                'node' => $n, 'fresh' => $fresh,
                'cpu' => $l['current']['cpu'] ?? null, 'mem' => $l['current']['mem_used_pct'] ?? null,
                'disk' => $l['current']['disk_used_pct_max'] ?? null,
                'load' => isset($l['current']['load1']) ? round($l['current']['load1'] / $cores * 100, 0) : null,
                'levels' => ['cpu' => $level('cpu_pct'), 'mem' => $level('mem_available_pct'), 'disk' => $level('disk_used_pct'), 'load' => $level('load_per_core')],
                'spark' => (array) ($l['recent']['cpu'] ?? []),
                'burst' => (bool) ($l['burst'] ?? false),
            ];
        }

        return [
            'kpi' => $kpi,
            'unresolved' => ['CRITICAL' => (int) ($unresolved['CRITICAL'] ?? 0), 'ERROR' => (int) ($unresolved['ERROR'] ?? 0), 'WARNING' => (int) ($unresolved['WARNING'] ?? 0)],
            'critical' => $critical,
            'trend' => [
                'cur' => array_map($anom, $series),
                'prev' => $this->compare ? array_map($anom, $prev) : null,
                'labels' => array_map(fn ($b) => CarbonImmutable::createFromTimestamp($b['ts'], 'Asia/Tokyo')->format($labelFmt), $series),
                'errors' => array_map(fn ($b) => $b['ERROR'] + $b['CRITICAL'], $series),
                'events' => array_map(fn ($b) => $b['INFO'] + $b['WARNING'] + $b['ERROR'] + $b['CRITICAL'], $series),
            ],
            'flow' => $stats->flow($this->period, $ids, $now),
            'services' => $services,
            'attention' => $attention,
            'logs' => $logs,
            'nodes' => $nodes,
            'range' => StatsService::window($this->period, $now),
        ];
    }

    /** しきい値判定結果のうち、指標名が prefix で始まるものの最悪レベル */
    public static function worstLevel(array $eval, string $prefix): string
    {
        $order = ['ok' => 0, 'warning' => 1, 'error' => 2, 'critical' => 3];
        $worst = 'ok';
        foreach ($eval as $key => $r) {
            if (str_starts_with((string) $key, $prefix) && ($order[$r['level']] ?? 0) > $order[$worst]) {
                $worst = $r['level'];
            }
        }

        return $worst;
    }
}
