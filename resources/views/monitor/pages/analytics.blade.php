@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Svg')
@php
    $d = $this->data();
    $c = $d['kpi']['current'];
    $p = $d['kpi']['previous'];
    $judged = $c['outcome_healthy'] + $c['outcome_warning'] + $c['outcome_violation'] + $c['outcome_unknown'];
    $max = max(1, ...array_map(fn ($b) => max($b['current'], $b['previous']), $d['bars'] ?: [['current' => 0, 'previous' => 0]]));
    $hm = $d['heatmap'];
    $days = ['日', '月', '火', '水', '木', '金', '土'];
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:toolbar>@include('monitor.partials.toolbar', ['range' => $d['range']])</x-slot:toolbar>
    <div class="two-col">
        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title">判定結果の内訳</h2><p class="panel-sub">監視項目の判定（判定前は除く）</p></div></div>
            <div class="donut-wrap">
                {!! Svg::donut([
                    ['label' => '正常', 'value' => $c['outcome_healthy'], 'color' => 'var(--green)'],
                    ['label' => '警告', 'value' => $c['outcome_warning'], 'color' => 'var(--amber)'],
                    ['label' => '違反', 'value' => $c['outcome_violation'], 'color' => 'var(--red)'],
                    ['label' => '判定不能', 'value' => $c['outcome_unknown'], 'color' => 'var(--purple)'],
                ], $c['healthy_rate'] === null ? '—' : number_format($c['healthy_rate'], 1).'%', '正常判定率') !!}
                <div class="donut-legend">
                    <div><i class="dot green"></i>正常<b>{{ number_format($c['outcome_healthy']) }}</b></div>
                    <div><i class="dot amber"></i>警告<b>{{ number_format($c['outcome_warning']) }}</b></div>
                    <div><i class="dot red"></i>違反<b>{{ number_format($c['outcome_violation']) }}</b></div>
                    <div><i class="dot" style="background:var(--purple)"></i>判定不能<b>{{ number_format($c['outcome_unknown']) }}</b></div>
                    <div class="text-muted" style="font-size:10px">判定前 {{ number_format($c['outcome_not_due']) }}（分母に含めない）</div>
                </div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title">サービス別の異常件数</h2><p class="panel-sub">警告＋エラー＋重大（同一障害の再検知を含む）</p></div></div>
            <div class="chart-legend"><div class="legend"><span class="legend-item"><i class="dot blue"></i>今回</span>@if ($this->compare)<span class="legend-item"><i class="dot" style="background:var(--faint)"></i>前期間</span>@endif</div><span class="chart-hint">件</span></div>
            <div class="bar-chart">
                @foreach ($d['bars'] as $b)
                    <div class="bar-row"><span>{{ $b['label'] }}</span><div class="bar-pair"><div class="hbar" style="width:{{ $b['current'] / $max * 100 }}%" title="今回 {{ $b['current'] }} 件"></div>@if ($this->compare)<div class="hbar prev" style="width:{{ $b['previous'] / $max * 100 }}%" title="前期間 {{ $b['previous'] }} 件"></div>@endif</div><span class="bar-num">{{ number_format($b['current']) }}</span></div>
                @endforeach
            </div>
        </section>
    </div>
    <section class="panel table-panel wide-panel">
        <div class="panel-head"><div><h2 class="panel-title">期間比較の詳細</h2><p class="panel-sub">今回と、同じ長さの直前の期間</p></div></div>
        <div class="table-scroll">
            <table class="cmp-table">
                <thead><tr><th>指標</th><th class="num">今回</th><th class="num">前期間</th><th class="num">差分</th><th class="num">変化率</th></tr></thead>
                <tbody>
                @foreach ($d['metrics'] as [$label, $key, $unit, $badWhenUp])
                    @php
                        $cv = $c[$key]; $pv = $p[$key];
                        $diff = is_numeric($cv) && is_numeric($pv) ? $cv - $pv : null;
                        $rate = $diff !== null && $pv ? round($diff / $pv * 100, 1) : null;
                        $cls = $diff === null || $diff == 0 || $badWhenUp === null ? 'flat' : (($diff > 0) === $badWhenUp ? 'up' : 'down');
                    @endphp
                    <tr><td>{{ $label }}</td><td class="num mono">{{ $cv === null ? '—' : number_format($cv, is_float($cv) ? 1 : 0).$unit }}</td><td class="num mono">{{ $pv === null ? '—' : number_format($pv, is_float($pv) ? 1 : 0).$unit }}</td><td class="num mono {{ $cls }}">{{ $diff === null ? '—' : ($diff > 0 ? '+' : '').number_format($diff, is_float($diff) ? 1 : 0) }}</td><td class="num mono {{ $cls }}">{{ $rate === null ? '—' : ($rate > 0 ? '+' : '').$rate.'%' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><div><h2 class="panel-title">異常が発生しやすい時間帯</h2><p class="panel-sub">曜日 × 2 時間（JST）· {{ ['7d' => '直近 7 日', '30d' => '直近 30 日'][$d['heatmap_period']] }}</p></div></div>
        <div class="heatmap">
            <div class="heat-head"><span></span>@for ($h = 0; $h < 12; $h++)<span>{{ $h * 2 }}:00</span>@endfor</div>
            @foreach ([1, 2, 3, 4, 5, 6, 0] as $dow)
                <div class="heat-row"><label>{{ $days[$dow] }}曜</label>
                    @for ($h = 0; $h < 12; $h++)
                        @php($v = $hm['cells'][$dow][$h])
                        <span class="heat-cell" style="--heat:{{ $hm['max'] ? round(0.08 + 0.92 * $v / $hm['max'], 2) : 0.08 }}" title="{{ $days[$dow] }}曜 {{ $h * 2 }}:00–{{ $h * 2 + 2 }}:00 {{ number_format($v) }} 件" aria-label="{{ $days[$dow] }}曜 {{ $h * 2 }}時から {{ $v }} 件"></span>
                    @endfor
                </div>
            @endforeach
            <div class="heat-legend">少ない @foreach ([.12, .3, .5, .7, 1] as $op)<i style="opacity:{{ $op }}"></i>@endforeach 多い</div>
        </div>
    </section>
</x-monitor.shell>
</div>
