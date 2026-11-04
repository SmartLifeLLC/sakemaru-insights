@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Svg')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\IncidentDetail')
@use('App\Filament\Monitor\Pages\Incidents')
@use('App\Filament\Monitor\Pages\Services')
@use('App\Filament\Monitor\Pages\Logs')
@use('App\Filament\Monitor\Pages\Notifications')
@use('App\Filament\Monitor\Pages\NodeDetail')
@php
    $d = $this->data();
    $k = $d['kpi']['current'];
    $delta = $d['kpi']['delta'];
    $pname = ['24h' => '24時間', '7d' => '7日間', '30d' => '30日間'][$this->period];
    $fmtDelta = function ($v, $suffix = '', $goodWhenDown = true) {
        if ($v === null) return '<span class="text-muted">前期間データなし</span>';
        if ($v == 0) return '<span class="flat">前期間と同じ</span>';
        $good = $goodWhenDown ? $v < 0 : $v > 0;
        return '<span class="'.($good ? 'good' : 'bad').'">'.($v > 0 ? '+' : '').number_format($v, is_float($v) && fmod($v, 1) ? 1 : 0).$suffix.'</span> 前期間比';
    };
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:buttons>
        <button type="button" class="btn" x-data x-on:click="$dispatch('open-widgets')">{!! Icon::svg('grid') !!}表示をカスタマイズ</button>
        <button type="button" class="btn" wire:click="$refresh">{!! Icon::svg('refresh') !!}更新</button>
    </x-slot:buttons>
    <x-slot:toolbar>@include('monitor.partials.toolbar', ['range' => $d['range']])</x-slot:toolbar>

    <div x-data="{
            hidden: (() => { try { return JSON.parse(localStorage.getItem('sakemaru-monitor-widgets') || '[]') } catch (e) { return [] } })(),
            picker: false,
            toggle(w) { this.hidden = this.hidden.includes(w) ? this.hidden.filter(x => x !== w) : [...this.hidden, w]; try { localStorage.setItem('sakemaru-monitor-widgets', JSON.stringify(this.hidden)) } catch (e) {} },
            shown(w) { return ! this.hidden.includes(w) },
            tabs: @js(array_keys($this->sectionTabs())),
            tab: (() => { try { return localStorage.getItem('sakemaru-monitor-dash-tab') || 'incidents' } catch (e) { return 'incidents' } })(),
            setTab(t) { this.tab = t; try { localStorage.setItem('sakemaru-monitor-dash-tab', t) } catch (e) {} },
            current() { return this.shown(this.tab) ? this.tab : (this.tabs.find(t => this.shown(t)) || this.tab) },
         }" x-on:open-widgets.window="picker = true" wire:poll.{{ (int) config('monitoring.ui.auto_refresh_seconds', 60) }}s.visible>

        <div class="dash-top">{{-- 重大障害の帯と KPI 4 枚は固定。以降がスクロール --}}
        @if ($d['critical']->isNotEmpty())
            <div class="alert-strip" role="alert">
                {!! Icon::svg('alert') !!}
                <div><b>{{ $d['critical']->count() }}件の重大な障害が発生しています</b><span>{{ $this->siteLabel((int) $d['critical'][0]->site_id) }}: {{ \Illuminate\Support\Str::limit($d['critical'][0]->title, 80) }}</span></div>
                <a class="btn link" href="{{ IncidentDetail::getUrl(['publicId' => $d['critical'][0]->public_id]) }}">詳細を確認 {!! Icon::svg('arrow') !!}</a>
            </div>
        @endif

        @php($adv = $this->advisoryHeadline())
        <a class="adv-strip level-{{ $adv['level'] }}" href="{{ \App\Filament\Monitor\Pages\Advisories::getUrl(['tab' => ($adv['summary']['urgent'] ?? 0) > 0 ? 'urgent' : 'new']) }}" wire:navigate>
            {!! Icon::svg($adv['level'] === 'ok' ? 'shield' : ($adv['level'] === 'muted' ? 'info' : 'alert')) !!}<b>脆弱性情報</b><span>{{ $adv['text'] }}</span><span class="adv-go">{!! Icon::svg('arrow') !!}</span>
        </a>

        <div class="kpi-grid" x-show="shown('kpi')">
            <div class="kpi">
                <div class="kpi-top"><span>監視実行数（{{ $pname }}）</span>{!! Icon::svg('pulse') !!}</div>
                <div class="kpi-value">{{ number_format($k['checks_judged']) }}<small>回</small></div>
                <div class="kpi-meta">{!! $fmtDelta($delta['checks_judged'], '回', false) !!}</div>
                {!! Svg::spark($d['trend']['events']) !!}
            </div>
            <div class="kpi {{ $k['healthy_rate'] !== null && $k['healthy_rate'] < 95 ? 'warning' : '' }}">
                <div class="kpi-top"><span>正常判定率</span>{!! Icon::svg('check') !!}</div>
                <div class="kpi-value">{{ $k['healthy_rate'] === null ? '—' : number_format($k['healthy_rate'], 1) }}<small>%</small></div>
                <div class="kpi-meta">{!! $fmtDelta($delta['healthy_rate'], 'pt', false) !!}</div>
                <div class="kpi-caption"><span>判定前・未監視は除く</span></div>
            </div>
            <div class="kpi {{ $d['unresolved']['CRITICAL'] + $d['unresolved']['ERROR'] > 0 ? 'warning' : '' }}">
                <div class="kpi-top"><span>未解決のインシデント</span>{!! Icon::svg('alert') !!}</div>
                <div class="kpi-value">{{ array_sum($d['unresolved']) }}<small>件</small></div>
                <div class="kpi-split"><span class="text-red">重大 <b>{{ $d['unresolved']['CRITICAL'] }}</b></span><span class="text-red">エラー <b>{{ $d['unresolved']['ERROR'] }}</b></span><span class="text-amber">警告 <b>{{ $d['unresolved']['WARNING'] }}</b></span></div>
                {!! Svg::spark($d['trend']['errors'], 86, 28, 'line-err') !!}
            </div>
            <div class="kpi">
                <div class="kpi-top"><span>通知成功率</span>{!! Icon::svg('bell') !!}</div>
                <div class="kpi-value">{{ $k['delivery_rate'] === null ? '—' : number_format($k['delivery_rate'], 1) }}<small>%</small></div>
                <div class="kpi-meta">送信 {{ number_format($k['notifications_delivered']) }} / 失敗 <span class="{{ $k['notifications_failed'] ? 'bad' : '' }}">{{ number_format($k['notifications_failed']) }}</span></div>
            </div>
        </div>
        {{-- KPI 以下はタブ区切り（スクロールを減らす、v2.2）。タブは KPI と一緒に固定 --}}
        <div class="tabs dash-tabs" role="tablist">
            @foreach ($this->sectionTabs() as $key => [$label, $count])
                <button type="button" role="tab" x-show="shown('{{ $key }}')" :class="{ active: current() === '{{ $key }}' }" :aria-selected="current() === '{{ $key }}' ? 'true' : 'false'" x-on:click="setTab('{{ $key }}')">{{ $label }}@if ($count !== null)<small>{{ $count }}</small>@endif</button>
            @endforeach
        </div>
        </div>{{-- /dash-top --}}

        <div class="dash-sections">
        @php($nodeRows = $this->nodeRows())
        <section class="dash-nodes" x-show="current() === 'nodes'" x-cloak>
            @if ($nodeRows)
                <x-monitor.node-panels :rows="$nodeRows" :range="$this->nodeRange" setter="setNodeRange" />
            @else
                <div class="panel"><div class="empty">資源収集ノードからの受信がありません。</div></div>
            @endif
        </section>
            <section class="panel" x-show="current() === 'trend'" x-cloak>
                <div class="panel-head">
                    <div><h2 class="panel-title">異常検知数の推移</h2><p class="panel-sub">警告・エラー・重大の合計（{{ $pname }}）</p></div>
                </div>
                <div class="chart-legend"><div class="legend"><span class="legend-item"><i class="dot blue"></i>今回</span>@if ($d['trend']['prev'])<span class="legend-item"><i class="dash-line"></i>前期間</span>@endif</div><span class="chart-hint">件</span></div>
                <div class="chart-shell">{!! Svg::line($d['trend']['cur'], $d['trend']['prev'], $d['trend']['labels']) !!}</div>
                <div class="chart-foot"><span>元データ: 時間別カウンタ（全件集計なし）</span><span>合計 {{ number_format(array_sum($d['trend']['cur'])) }} 件</span></div>
            </section>
            <section class="panel" x-show="current() === 'services'" x-cloak>
                <div class="panel-head"><div><h2 class="panel-title">サービスの稼働状況</h2><p class="panel-sub">直近 24 時間（1 本 = 1 時間の最悪判定）</p></div><a class="btn link" href="{{ Services::getUrl() }}">一覧 {!! Icon::svg('arrow') !!}</a></div>
                <div class="service-head-stats"><span class="legend-item"><i class="dot green"></i>正常</span><span class="legend-item"><i class="dot amber"></i>警告</span><span class="legend-item"><i class="dot red"></i>障害</span><span class="legend-item"><i class="dot" style="background:var(--border)"></i>判定なし</span></div>
                <div class="service-list">
                    @forelse ($d['services'] as $s)
                        <a class="service-row" href="{{ Services::getUrl(['site' => $s['code']]) }}">
                            <span class="service-monogram">{{ mb_substr($s['name'], 0, 1) }}</span>
                            <span class="service-name">{{ $s['name'] }}<small>{{ $s['code'] }}{{ $s['open'] ? ' · 未解決 '.$s['open'] : '' }}</small></span>
                            <span class="service-bars" aria-hidden="true">@foreach (array_slice($s['bars'], -12) as $b)<i class="s-{{ $b['state'] }}" style="height:{{ $b['state'] === 'none' ? 6 : ($b['state'] === 'healthy' ? 12 : 17) }}px" title="{{ P::jst(\Carbon\CarbonImmutable::createFromTimestamp($b['ts']), 'H:00') }}"></i>@endforeach</span>
                            <span class="status-label"><i class="dot {{ $s['state'][0] === 'neutral' ? '' : $s['state'][0] }}" @if ($s['state'][0] === 'neutral') style="background:var(--faint)" @endif></i>{{ $s['state'][1] }}</span>
                        </a>
                    @empty
                        <div class="empty">表示できるサービスがありません。</div>
                    @endforelse
                </div>
            </section>
            @php($f = $d['flow'])
            <section class="panel" x-show="current() === 'flow'" x-cloak>
                <div class="panel-head"><div><h2 class="panel-title">異常イベントの処理フロー</h2><p class="panel-sub">検知 → インシデント → 通知 → 配送結果（{{ $pname }}）</p></div><span class="badge neutral">{{ number_format($f['anomalies']) }} 件</span></div>
                <div class="flow">
                    <svg viewBox="0 0 680 200" role="img" aria-label="異常 {{ $f['anomalies'] }} 件、インシデント {{ $f['incidents'] }} 件、集約 {{ $f['deduplicated'] }} 件、通知 {{ $f['queued'] }} 件、配送完了 {{ $f['delivered'] }} 件、失敗 {{ $f['failed'] }} 件">
                        <text class="flow-label" x="5" y="15">検知</text><text class="flow-label" x="190" y="15">インシデント</text><text class="flow-label" x="390" y="15">通知判定</text><text class="flow-label" x="574" y="15">配送結果</text>
                        <path d="M15 45 C95 45 105 45 190 45 L190 155 C105 155 95 155 15 155 Z" fill="var(--blue)" opacity=".18"/>
                        <path d="M200 45 C280 45 305 45 390 45 L390 137 C305 137 280 137 200 137Z" fill="var(--amber)" opacity=".17"/>
                        <path d="M200 137 C280 137 305 169 390 169 L390 187 C305 187 280 155 200 155Z" fill="var(--faint)" opacity=".17"/>
                        <path d="M400 45 C480 45 490 45 575 45 L575 155 C490 155 480 155 400 155Z" fill="var(--green)" opacity=".18"/>
                        <path d="M400 155 C480 155 490 177 575 177 L575 181 C490 181 480 159 400 159Z" fill="var(--red)" opacity=".25"/>
                        <a href="{{ Logs::getUrl(['tab' => 'warning']) }}"><g class="flow-node"><rect x="5" y="45" width="10" height="132" rx="2" fill="var(--blue)"/><text class="flow-label" x="24" y="99">異常イベント</text><text class="flow-value" x="24" y="119">{{ number_format($f['anomalies']) }}</text></g></a>
                        <a href="{{ Incidents::getUrl() }}"><g class="flow-node"><rect x="190" y="45" width="10" height="110" rx="2" fill="var(--amber)"/><text class="flow-label" x="211" y="87">新規</text><text class="flow-value" x="211" y="107">{{ number_format($f['incidents']) }}</text><rect x="190" y="167" width="10" height="22" rx="2" fill="var(--faint)"/><text class="flow-label" x="210" y="183">既存へ集約 {{ number_format($f['deduplicated']) }}</text></g></a>
                        <a href="{{ Notifications::getUrl() }}"><g class="flow-node"><rect x="390" y="45" width="10" height="114" rx="2" fill="var(--blue)"/><text class="flow-label" x="410" y="90">通知対象</text><text class="flow-value" x="410" y="110">{{ number_format($f['queued']) }}</text></g></a>
                        <a href="{{ Notifications::getUrl() }}"><g class="flow-node"><rect x="575" y="45" width="10" height="110" rx="2" fill="var(--green)"/><text class="flow-label" x="597" y="88">配送完了</text><text class="flow-value" x="597" y="109">{{ number_format($f['delivered']) }}</text><rect x="575" y="177" width="10" height="4" rx="1" fill="var(--red)"/><text class="flow-label" x="597" y="183">失敗 {{ number_format($f['failed']) }}</text></g></a>
                    </svg>
                </div>
                <div class="flow-note">{!! Icon::svg('info') !!}異常 = 警告＋エラー＋重大。既存へ集約 = 同じ障害の再検知（通知しない）。通知は Slack・メール・電話の合計。</div>
            </section>
            <section class="panel" x-show="current() === 'incidents'" x-cloak>
                <div class="panel-head"><div><h2 class="panel-title">対応が必要な問題 <span class="count">{{ array_sum($d['unresolved']) }}</span></h2><p class="panel-sub">重大度・未確認の順に 5 件</p></div></div>
                <div class="incident-list">
                    @forelse ($d['attention'] as $i)
                        <a class="incident-row" href="{{ IncidentDetail::getUrl(['publicId' => $i->public_id]) }}">
                            <span class="severity-icon {{ $i->severity->value === 'WARNING' ? 'warn' : '' }}">{!! Icon::svg('alert') !!}</span>
                            <span class="incident-body"><b>{{ $i->title }}</b><small>{{ $i->public_id }} · {{ $this->siteLabel((int) $i->site_id) }} · {{ $i->workflow_status->label() }}{{ $i->observation_state->value === 'RECOVERED' ? ' · 復旧確認済み' : '' }}</small></span>
                            <span class="incident-time">{{ P::ago($i->last_seen_at) }}</span>
                        </a>
                    @empty
                        <div class="empty">未解決の問題はありません。</div>
                    @endforelse
                </div>
                <div class="panel-bottom"><span>未確認 {{ $this->shell()['unack'] }} 件</span><a class="btn link" href="{{ Incidents::getUrl() }}">エラー管理を開く {!! Icon::svg('arrow') !!}</a></div>
            </section>
        <section class="panel table-panel" x-show="current() === 'logs'" x-cloak>
            <div class="panel-head"><div><h2 class="panel-title">最新の監視ログ</h2><p class="panel-sub">受信の新しい順に 5 件</p></div><a class="btn link" href="{{ Logs::getUrl() }}">監視ログを開く {!! Icon::svg('arrow') !!}</a></div>
            @include('monitor.partials.log-table', ['rows' => $d['logs'], 'clickable' => false])
        </section>
        </div>{{-- /dash-sections --}}

        <template x-if="picker">
            <div>
                <div class="drawer-backdrop" x-on:click="picker = false"></div>
                <aside class="drawer" role="dialog" aria-modal="true" aria-label="表示をカスタマイズ" x-on:keydown.escape.window="picker = false">
                    <div class="drawer-top"><h2>表示をカスタマイズ</h2><button type="button" class="icon-btn" x-on:click="picker = false" aria-label="閉じる">{!! Icon::svg('close') !!}</button></div>
                    <p class="drawer-intro">表示するセクションを選びます。設定はこのブラウザーにだけ保存されます（保存できない環境では既定の表示）。</p>
                    @foreach (['kpi' => ['主要指標', '監視実行数・正常判定率・未解決・通知成功率'], 'trend' => ['異常検知数の推移', '今回と前期間'], 'services' => ['サービスの稼働状況', '24 本の時間バー'], 'flow' => ['処理フロー', '検知から配送結果まで'], 'incidents' => ['対応が必要な問題', '重大度順 5 件'], 'nodes' => ['システム負荷', 'サーバーごとの時系列（2 台横並び）'], 'logs' => ['最新の監視ログ', '5 件']] as $w => [$t, $sub])
                        <div class="widget-picker"><div><b>{{ $t }}</b><span>{{ $sub }}</span></div><button type="button" class="switch" role="switch" :aria-checked="shown('{{ $w }}') ? 'true' : 'false'" x-on:click="toggle('{{ $w }}')" aria-label="{{ $t }}"></button></div>
                    @endforeach
                    <div class="drawer-actions"><button type="button" class="btn" x-on:click="picker = false">変更せず閉じる</button></div>
                </aside>
            </div>
        </template>
    </div>
</x-monitor.shell>
</div>
