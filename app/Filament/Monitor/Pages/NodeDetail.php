<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Node;
use App\Models\Monitoring\ServerMetric;
use App\Monitoring\Resources\MetricFile;
use App\Monitoring\Resources\ThresholdEvaluator;
use Carbon\CarbonImmutable;
use Filament\Panel;
use Livewire\Attributes\Url;

/**
 * ノード詳細（v2 第5章 / V-106）。24 時間・7 日・1 年は 5 分集約（DB）、「詳細」は指定範囲の 30 秒値と 1 秒バースト（ローカルファイル）。
 * ローカルファイルは各サーバーにあるため、このサーバー自身のノードでだけ表示できる。
 */
class NodeDetail extends MonitorPage
{
    protected static ?string $title = 'ノード詳細';

    protected static ?string $slug = 'nodes/detail';

    protected static string $navKey = 'nodes';

    protected string $view = 'monitor.pages.node-detail';

    public string $code = '';

    #[Url]
    public string $range = '24h';

    #[Url]
    public ?string $from = null; // JST Y-m-d\TH:i（詳細）

    #[Url]
    public ?string $to = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/nodes/{code}';
    }

    public function mount(string $code = ''): void
    {
        parent::mount();
        $this->code = $code;
        abort_unless($this->node(), 404);
    }

    public function node(): ?Node
    {
        return Node::query()->where('node_code', $this->code)->first();
    }

    public function description(): string
    {
        return 'ノード '.$this->code.' の資源の推移と、しきい値の超過履歴。';
    }

    public function setRange(string $r): void
    {
        $this->range = in_array($r, ['24h', '7d', '1y', 'raw'], true) ? $r : '24h';
        if ($this->range === 'raw' && ! $this->from) {
            $now = CarbonImmutable::now('Asia/Tokyo');
            $this->from = $now->subHour()->format('Y-m-d\TH:i');
            $this->to = $now->format('Y-m-d\TH:i');
        }
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $n = $this->node();
        $now = CarbonImmutable::now('UTC');
        $latest = (array) $n->latest_json;
        $cores = max(1, (int) ($latest['cores'] ?? ($n->os_info_json['cores'] ?? 1)));
        $th = ThresholdEvaluator::resolve($n->thresholds_json);
        $series = $this->range === 'raw' ? $this->rawSeries($n) : $this->aggSeries($n, $now, $cores);

        return [
            'node' => $n, 'latest' => $latest, 'cores' => $cores, 'thresholds' => $th,
            'eval' => $latest ? ThresholdEvaluator::evaluate($latest, $th, $now->getTimestamp()) : [],
            'series' => $series,
            'incidents' => Incident::query()->whereIn('check_key', ['node.resources', 'monitor.metrics_stale'])->orderByDesc('id')->limit(200)->get()
                ->filter(fn ($i) => ($i->scope_json['node'] ?? null) === $n->node_code)->take(20)->values(),
            'local' => is_dir(dirname(MetricFile::forNode($n->node_code)->path('x'))),
        ];
    }

    /** 5 分集約を表示点数（最大 400）に間引く（平均・最大を保持） */
    private function aggSeries(Node $n, CarbonImmutable $now, int $cores): array
    {
        $from = match ($this->range) {
            '7d' => $now->subDays(7),
            '1y' => $now->subYear(),
            default => $now->subDay(),
        };
        $step = match ($this->range) {
            '7d' => 3600,
            '1y' => 86400,
            default => 300,
        };
        // 7 日・1 年は DB 側で時間/日に集約してから読む（1 年分 10 万行を毎回転送しない）
        $q = ServerMetric::query()->where('node_id', $n->id)->where('bucket_start', '>=', $from);
        $driver = $q->getConnection()->getDriverName();
        $bucketExpr = $driver === 'sqlite'
            ? "(CAST(strftime('%s', bucket_start) AS INTEGER) / {$step}) * {$step}"
            : "FLOOR(UNIX_TIMESTAMP(bucket_start) / {$step}) * {$step}";
        $rows = $q->selectRaw("{$bucketExpr} AS b, COUNT(*) AS n, AVG(cpu_avg) AS cpu_avg, MAX(cpu_max) AS cpu_max, AVG(iowait_avg) AS iowait_avg, AVG(load1_avg) AS load1_avg, AVG(mem_used_pct_avg) AS mem_used_pct_avg, MAX(mem_used_pct_max) AS mem_used_pct_max, MAX(disk_used_pct_max) AS disk_used_pct_max, MAX(swap_used_pct_max) AS swap_used_pct_max")
            ->groupByRaw($bucketExpr)->orderByRaw('b')->limit(400)->get();
        $buckets = [];
        foreach ($rows as $r) {
            $buckets[(int) $r->b] = ['n' => (int) $r->n, 'cpu_avg' => (float) $r->cpu_avg * (int) $r->n, 'cpu_max' => (float) $r->cpu_max, 'iowait_avg' => (float) $r->iowait_avg * (int) $r->n,
                'load1_avg' => (float) $r->load1_avg * (int) $r->n, 'mem_used_pct_avg' => (float) $r->mem_used_pct_avg * (int) $r->n, 'mem_used_pct_max' => (float) $r->mem_used_pct_max,
                'disk_used_pct_max' => (float) $r->disk_used_pct_max, 'swap_used_pct_max' => (float) $r->swap_used_pct_max];
        }
        $labelFmt = $this->range === '24h' ? 'H:i' : ($this->range === '7d' ? 'm/d H時' : 'm/d');
        $out = ['labels' => [], 'cpu' => [], 'cpu_max' => [], 'mem' => [], 'disk' => [], 'load' => [], 'iowait' => [], 'source' => '5 分集約（DB）', 'burst' => null];
        foreach ($buckets as $ts => $b) {
            $out['labels'][] = CarbonImmutable::createFromTimestamp($ts, 'Asia/Tokyo')->format($labelFmt);
            $out['cpu'][] = round($b['cpu_avg'] / $b['n'], 1);
            $out['cpu_max'][] = round($b['cpu_max'], 1);
            $out['mem'][] = round($b['mem_used_pct_avg'] / $b['n'], 1);
            $out['disk'][] = round($b['disk_used_pct_max'], 1);
            $out['load'][] = round($b['load1_avg'] / $b['n'] / $cores * 100, 1);
            $out['iowait'][] = round($b['iowait_avg'] / $b['n'], 1);
        }

        return $out;
    }

    /** 指定範囲（最大 6 時間）の 30 秒値と 1 秒バースト（このサーバー上のファイル） */
    private function rawSeries(Node $n): array
    {
        $tz = 'Asia/Tokyo';
        try {
            $to = $this->to ? CarbonImmutable::parse($this->to, $tz) : CarbonImmutable::now($tz);
            $from = $this->from ? CarbonImmutable::parse($this->from, $tz) : $to->subHour();
        } catch (\Throwable) {
            $to = CarbonImmutable::now($tz);
            $from = $to->subHour();
            $this->from = $from->format('Y-m-d\TH:i');
            $this->to = $to->format('Y-m-d\TH:i');
        }
        if ($from->greaterThanOrEqualTo($to)) {
            $from = $to->subHour();
        }
        if ($to->diffInHours($from, true) > 6) {
            $from = $to->subHours(6);
        }
        $file = MetricFile::forNode($n->node_code);
        $d = $file->read($from->getTimestamp(), $to->getTimestamp());
        $b = $file->readBurst($from->getTimestamp(), $to->getTimestamp());
        $cores = max(1, (int) (((array) $n->latest_json)['cores'] ?? 1));

        return [
            'labels' => array_map(fn ($t) => CarbonImmutable::createFromTimestamp($t, $tz)->format('H:i:s'), $d['t']),
            'cpu' => $d['cols']['cpu'], 'cpu_max' => [], 'mem' => $d['cols']['mem_used_pct'], 'disk' => $d['cols']['disk_used_pct_max'],
            'load' => array_map(fn ($v) => $v === null ? null : round($v / $cores * 100, 1), $d['cols']['load1']), 'iowait' => $d['cols']['iowait'],
            'source' => '30 秒値（このサーバーのローカルファイル）',
            'burst' => $b['t'] ? ['labels' => array_map(fn ($t) => CarbonImmutable::createFromTimestamp($t, $tz)->format('H:i:s'), $b['t']), 'cpu' => $b['cols']['cpu'], 'iowait' => $b['cols']['iowait'], 'mem' => $b['cols']['mem_used_pct']] : null,
        ];
    }
}
