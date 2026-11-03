@use('App\Monitoring\Support\Icon')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\Analytics')
@use('App\Filament\Monitor\Pages\Logs')
@use('App\Filament\Monitor\Pages\Incidents')
@use('App\Filament\Monitor\Pages\Rules')
@use('App\Monitoring\Services\FeatureRegistry')
@php($d = $this->data())
<div>
<x-monitor.shell :page="$this">
    <div class="data-notice">{!! Icon::svg('info') !!}可用性は HTTP プローブ（/up）の成功率と平均応答時間。プローブ未登録のサービスは「正常時間率」（エラーの無かった時間 ÷ 判定のあった時間、判定なしの時間は除外）を表示します。</div>
    <div class="service-cards">
        @foreach ($d['cards'] as $c)
            <section class="panel service-card">
                <div class="service-card-top">
                    <span class="service-monogram" style="width:34px;height:34px;font-size:14px">{{ mb_substr($c['name'], 0, 1) }}</span>
                    @if (! $c['enabled'])<span class="badge neutral">停止</span>
                    @elseif ($c['open'] > 0)<span class="badge error">未解決 {{ $c['open'] }}</span>
                    @elseif ($c['uptime'] === null)<span class="badge neutral">未監視</span>
                    @else<span class="badge ok">正常</span>@endif
                </div>
                <h3>{{ $c['label'] }}</h3>
                <p>{{ $c['role'] }}</p>
                <div class="service-bars" aria-label="直近 24 時間の稼働">@foreach ($c['bars'] as $b)<i class="s-{{ $b['state'] }}" style="height:{{ $b['state'] === 'none' ? 8 : ($b['state'] === 'healthy' ? 16 : 23) }}px" title="{{ P::jst(\Carbon\CarbonImmutable::createFromTimestamp($b['ts']), 'm/d H:00') }} {{ ['healthy' => '正常', 'warning' => '警告', 'violation' => '障害', 'unknown' => '判定不能', 'none' => '判定なし'][$b['state']] }}"></i>@endforeach</div>
                <div class="service-metrics">
                    @php($av = $c['probe']['availability'] ?? null)
                    <div><span>{{ $av !== null ? '可用性（HTTP・24h）' : '正常時間率（24h）' }}</span><b>{{ $av !== null ? $av.'%' : ($c['uptime'] === null ? '—' : $c['uptime'].'%') }}</b></div>
                    <div><span>平均応答</span><b>{{ $c['probe']['avg_ms'] ?? null ? $c['probe']['avg_ms'].'ms' : '—' }}</b></div>
                    <div><span>監視実行（24h）</span><b>{{ number_format($c['judged']) }}</b></div>
                    <div><span>正常判定率</span><b>{{ $c['healthy_rate'] === null ? '—' : $c['healthy_rate'].'%' }}</b></div>
                </div>
                <p style="font-size:10px">機能 {{ $c['features_on'] }} / {{ $c['features_total'] }} を監視中 @if ($c['review'])<span class="chip review">要確認 {{ $c['review'] }}</span>@endif · HTTP プローブ {{ $c['probes'] }} 件</p>
                <button type="button" class="btn" wire:click="openSite('{{ $c['code'] }}')">詳細を見る</button>
            </section>
        @endforeach
    </div>
    <x-slot:drawer>
        @php($x = $d['detail'])
        <x-monitor.drawer :open="(bool) $x" :title="$x ? $this->sites()->label($x['site']->application_code) : ''">
            @if ($x)
                <div class="actions-row">
                    <a class="btn" href="{{ Analytics::getUrl(['site' => $x['site']->application_code]) }}">{!! Icon::svg('chart') !!}統計へ</a>
                    <a class="btn" href="{{ Logs::getUrl(['site' => $x['site']->application_code]) }}">{!! Icon::svg('logs') !!}監視ログへ</a>
                    <a class="btn" href="{{ Incidents::getUrl(['site' => $x['site']->application_code]) }}">{!! Icon::svg('alert') !!}エラー管理へ</a>
                    <a class="btn" href="{{ Rules::getUrl() }}">{!! Icon::svg('sliders') !!}監視ルール</a>
                </div>
                <div class="detail-block"><h3>機能</h3>
                    @forelse ($x['features'] as $f)
                        <div class="tree-row" style="grid-template-columns:1fr auto"><span>{{ $f->display_name }} <span class="code-name mono">{{ $f->feature_key }}</span></span><span>@if ($f->enabled)<span class="badge ok">監視中</span>@else<span class="badge neutral">未監視（機能未使用）</span>@endif @if ($f->needsReview())<span class="chip review">推奨: {{ $f->recommended_enabled ? '有効' : '無効' }}</span>@endif</span></div>
                    @empty
                        <span class="text-muted">機能の定義はありません。</span>
                    @endforelse
                </div>
                <div class="detail-block"><h3>監視項目</h3>
                    @forelse ($x['checks'] as $row)
                        @php($ck = $row['model'])
                        <div class="tree-row" style="grid-template-columns:1fr auto auto"><span>{{ $ck->display_name }}<br><span class="code-name mono">{{ $ck->check_key }}</span></span><span class="text-muted" style="font-size:9px">{{ $ck->last_completed_at ? P::ago($ck->last_completed_at) : '未実行' }}</span><span>@if ($row['gate']['active'])<x-monitor.outcome :outcome="(string) $ck->last_outcome" />@else<span class="badge neutral">{{ FeatureRegistry::gateLabel($row['gate']['reason']) }}</span>@endif</span></div>
                    @empty
                        <span class="text-muted">監視項目はありません。</span>
                    @endforelse
                </div>
                @if ($x['probes']->isNotEmpty())
                    <div class="detail-block"><h3>HTTP プローブ</h3>@foreach ($x['probes'] as $p)<div class="mono">{{ $p->params_json['url'] ?? '' }} {{ $p->enabled ? '' : '（停止）' }}</div>@endforeach</div>
                @endif
            @endif
        </x-monitor.drawer>
    </x-slot:drawer>
</x-monitor.shell>
</div>
