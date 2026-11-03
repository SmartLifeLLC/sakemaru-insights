<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Feature;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Target;
use App\Monitoring\Enums\WorkflowStatus;
use App\Monitoring\Services\FeatureRegistry;
use App\Monitoring\Services\ProbeRunner;
use App\Monitoring\Services\StatsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/** サービス一覧（v2 第6.3）。可用性・平均応答時間は HTTP プローブ、監視実行数・正常判定率は counters、機能と監視項目は台帳から。 */
class Services extends MonitorPage
{
    protected static ?string $title = 'サービス一覧';

    protected static ?string $slug = 'services';

    protected static string $navKey = 'services';

    protected static string $description = '各サービスの稼働状況・応答時間・監視している機能を確認します。';

    protected string $view = 'monitor.pages.services';

    #[Url]
    public ?string $site = null;

    public function openSite(string $code): void
    {
        $this->site = $code;
    }

    public function closeDrawer(): void
    {
        $this->site = null;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $now = CarbonImmutable::now('UTC');
        $stats = app(StatsService::class);
        $bars = $stats->serviceBars($now);
        $by = $stats->bySite('24h', $now);
        $avail = app(ProbeRunner::class)->availabilityBySite(24);
        $open = Incident::query()->selectRaw('site_id, COUNT(*) c')->whereIn('workflow_status', WorkflowStatus::unresolvedValues())->groupBy('site_id')->pluck('c', 'site_id')->all();
        $features = Feature::query()->get()->groupBy('site_id');
        $probes = Target::query()->where('kind', 'HTTP_PROBE')->get()->groupBy('site_id');
        $cards = [];
        foreach ($this->siteOptions() as $s) {
            $site = $this->sites()->siteById($s['id']);
            $b = $bars[$s['id']] ?? [];
            $judgedHours = collect($b)->reject(fn ($x) => $x['state'] === 'none')->count();
            $okHours = collect($b)->where('state', 'healthy')->count();
            $f = $features->get($s['id'], collect());
            $cards[] = $s + [
                'role' => (string) config('monitoring.sites.'.$s['code'].'.role', ''),
                'enabled' => (bool) $site?->enabled,
                'bars' => $b,
                // 可用性 = 正常の時間 ÷ 判定のあった時間（判定なしの時間は除く）
                'uptime' => $judgedHours ? round($okHours / $judgedHours * 100, 1) : null,
                'probe' => $avail[$s['id']] ?? null,
                'judged' => (int) ($by[$s['id']]['judged'] ?? 0),
                'healthy_rate' => ($by[$s['id']]['judged'] ?? 0) ? round($by[$s['id']]['healthy'] / $by[$s['id']]['judged'] * 100, 1) : null,
                'errors' => (int) (($by[$s['id']]['ERROR'] ?? 0) + ($by[$s['id']]['CRITICAL'] ?? 0)),
                'open' => (int) ($open[$s['id']] ?? 0),
                'features_on' => $f->where('enabled', true)->count(),
                'features_total' => $f->count(),
                'review' => $f->filter(fn ($x) => $x->needsReview())->count(),
                'probes' => $probes->get($s['id'], collect())->count(),
            ];
        }

        return ['cards' => $cards, 'detail' => $this->site ? $this->detail($this->site) : null];
    }

    /** @return array<string,mixed>|null */
    private function detail(string $code): ?array
    {
        $site = $this->sites()->site($code);
        if (! $site || ! in_array((int) $site->id, $this->viewableSiteIds(), true)) {
            return null;
        }
        $reg = app(FeatureRegistry::class);
        $checks = CheckModel::query()->where('site_id', $site->id)->orderBy('priority')->orderBy('check_key')->get();
        if ($code === 'INSIGHTS') {
            $checks = CheckModel::query()->where('site_id', $site->id)->orderBy('check_key')->get();
        }

        return [
            'site' => $site,
            'features' => Feature::query()->where('site_id', $site->id)->orderBy('sort_order')->get(),
            'checks' => $checks->map(fn ($c) => ['model' => $c, 'gate' => $reg->gate($c)]),
            'probes' => Target::query()->where('kind', 'HTTP_PROBE')->where('site_id', $site->id)->get(),
        ];
    }
}
