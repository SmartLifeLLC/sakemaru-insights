<?php

namespace App\Filament\Monitor\Pages;

use App\Filament\Monitor\MonitorPage;
use App\Monitoring\Resources\NodeSeries;
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
        return \App\Monitoring\Resources\NodeOverview::rows($this->range);
    }
}
