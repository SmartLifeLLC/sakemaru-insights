<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Monitoring\Services\StatsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/** 統計・比較（v2 第6.3 / V-099）。ドーナツ・比較表・ヒートマップ・サービス別件数を同じ期間条件で counters から集計。 */
class Analytics extends MonitorPage
{
    protected static ?string $title = '統計・比較';

    protected static ?string $slug = 'analytics';

    protected static string $navKey = 'analytics';

    protected static string $description = '監視結果の変化を、期間・サービスごとに確認します。';

    protected string $view = 'monitor.pages.analytics';

    #[Url]
    public string $period = '7d';

    #[Url]
    public ?string $site = null;

    #[Url]
    public bool $compare = true;

    public function setPeriod(string $p): void
    {
        $this->period = array_key_exists($p, StatsService::PERIODS) ? $p : '7d';
    }

    /** @return int[] */
    public function scopeSiteIds(): array
    {
        $ids = $this->viewableSiteIds();
        if ($this->site && ($s = $this->sites()->site($this->site)) && in_array((int) $s->id, $ids, true)) {
            return [(int) $s->id];
        }

        return $ids;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $now = CarbonImmutable::now('UTC');
        $stats = app(StatsService::class);
        $ids = $this->scopeSiteIds();
        $k = $stats->kpis($this->period, $ids, $now);
        $cur = $stats->bySite($this->period, $now);
        $prev = $stats->bySite($this->period, $now->subHours(StatsService::hours($this->period)));
        $bars = [];
        foreach ($this->siteOptions() as $s) {
            if (! in_array($s['id'], $ids, true)) {
                continue;
            }
            $a = fn ($x) => (int) (($x['WARNING'] ?? 0) + ($x['ERROR'] ?? 0) + ($x['CRITICAL'] ?? 0));
            $bars[] = ['label' => $s['label'], 'code' => $s['code'], 'current' => $a($cur[$s['id']] ?? []), 'previous' => $a($prev[$s['id']] ?? [])];
        }
        $metrics = [
            ['監視実行数', 'checks_judged', '回', false], ['正常判定率', 'healthy_rate', '%', false], ['受信イベント', 'events', '件', null],
            ['警告', 'warning', '件', true], ['エラー', 'error', '件', true], ['重大', 'critical', '件', true],
            ['新規インシデント', 'incidents_new', '件', true], ['既存へ集約', 'deduplicated', '件', null],
            ['通知（作成）', 'notifications_queued', '件', null], ['通知成功率', 'delivery_rate', '%', false],
            ['HTTP 可用性', 'probe_availability', '%', false], ['平均応答時間', 'probe_avg_ms', 'ms', true],
        ];

        return ['kpi' => $k, 'metrics' => $metrics, 'heatmap' => $stats->heatmap($this->period === '24h' ? '7d' : $this->period, $ids, $now),
            'heatmap_period' => $this->period === '24h' ? '7d' : $this->period, 'bars' => $bars, 'range' => StatsService::window($this->period, $now)];
    }
}
