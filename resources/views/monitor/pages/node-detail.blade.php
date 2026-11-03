@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Svg')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\Nodes')
@use('App\Filament\Monitor\Pages\IncidentDetail')
@use('App\Filament\Monitor\Pages\Rules')
@php
    $d = $this->data();
    $n = $d['node'];
    $s = $d['series'];
    $l = $d['latest'];
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:buttons>
        <a class="btn" href="{{ Nodes::getUrl() }}">ノード一覧へ</a>
        <a class="btn" href="{{ Rules::getUrl(['tab' => 'nodes']) }}">{!! Icon::svg('sliders') !!}しきい値を変更</a>
    </x-slot:buttons>
    <div class="facts">
        <div class="fact"><span>ノード</span><b>{{ $n->display_name }}</b><small class="mono">{{ $n->node_code }} · {{ $n->role }}{{ $n->enabled ? '' : ' · 無効' }}</small></div>
        <div class="fact"><span>サーバー</span><b>{{ $d['cores'] }} コア / {{ $n->os_info_json['mem_total_gb'] ?? '?' }}GB</b><small>{{ $n->os_info_json['kernel'] ?? '' }} · PHP {{ $n->os_info_json['php'] ?? '?' }}</small></div>
        <div class="fact"><span>最終受信</span><b>{{ P::ago($n->last_heartbeat_at) }}</b><small>{{ P::jst($n->last_heartbeat_at, 'Y/m/d H:i:s') }}</small></div>
        <div class="fact"><span>現在の CPU / メモリ</span><b>{{ $l['current']['cpu'] ?? '—' }}% / {{ $l['current']['mem_used_pct'] ?? '—' }}%</b><small>load {{ $l['current']['load1'] ?? '—' }} · iowait {{ $l['current']['iowait'] ?? '—' }}%</small></div>
        <div class="fact"><span>接続数（TCP）</span><b>{{ $l['sockets']['tcp_estab'] ?? '—' }}</b><small>timewait {{ $l['sockets']['tcp_timewait'] ?? '—' }}</small></div>
    </div>
    <section class="panel table-panel wide-panel">
        <div class="panel-head"><div><h2 class="panel-title">プロセスの稼働</h2><p class="panel-sub">1 分ごとに /proc を確認。必須プロセスが 5 分以上 0 件で重大。必須の指定は各サーバーの .env（MONITOR_NODE_PROCESSES）</p></div></div>
        <div class="table-scroll"><table><thead><tr><th>プロセス</th><th>件数</th><th>このサーバーで</th><th>状態</th></tr></thead><tbody>
        @forelse ((array) ($l['processes'] ?? []) as $pk => $pv)
            <tr><td>{{ config('monitoring.resources.processes.'.$pk.'.label', $pk) }}</td><td class="mono">{{ (int) ($pv['count'] ?? 0) }}</td><td>{{ ! empty($pv['required']) ? '必須' : '任意' }}</td>
                <td>@if ((int) ($pv['count'] ?? 0) > 0)<span class="badge ok">稼働中</span>@elseif (! empty($pv['required']))<span class="badge critical">停止（{{ P::jst($pv['missing_since'] ?? null, 'm/d H:i:s') }} から）</span>@else<span class="badge neutral">なし</span>@endif</td></tr>
        @empty
            <tr><td colspan="4"><div class="empty">プロセス情報はまだありません（資源収集の更新後に表示されます）。</div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
    <div class="toolbar">
        <div class="toolbar-left">
            <div class="segmented" role="group" aria-label="表示期間">
                @foreach (['24h' => '24 時間', '7d' => '7 日', '1y' => '1 年', 'raw' => '詳細（30 秒・1 秒）'] as $k => $lab)
                    <button type="button" class="{{ $this->range === $k ? 'selected' : '' }}" wire:click="setRange('{{ $k }}')">{{ $lab }}</button>
                @endforeach
            </div>
            @if ($this->range === 'raw')
                <input class="field-select" type="datetime-local" wire:model.live="from" aria-label="開始（JST）">
                <input class="field-select" type="datetime-local" wire:model.live="to" aria-label="終了（JST）">
            @endif
        </div>
        <div class="toolbar-right"><span class="range-caption">{!! Icon::svg('info') !!}{{ $s['source'] }}</span></div>
    </div>
    @if ($this->range === 'raw' && ! $s['labels'])
        <div class="data-notice">{!! Icon::svg('info') !!}この範囲の 30 秒値はありません。30 秒値・1 秒値は各サーバーのローカルファイル（60 日保存）にあり、このサーバー以外のノードは 5 分集約で表示します。</div>
    @endif
    <div class="two-col">
        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title">CPU・メモリ・負荷</h2><p class="panel-sub">%（負荷は load1 ÷ コア数）</p></div></div>
            <div class="chart-legend"><div class="legend"><span class="legend-item"><i class="dot blue"></i>CPU</span><span class="legend-item"><i class="dot green"></i>メモリ</span><span class="legend-item"><i class="dot amber"></i>負荷</span><span class="legend-item"><i class="dot red"></i>I/O 待ち</span></div></div>
            <div class="chart-shell">{!! Svg::multi(['cpu' => ['values' => $s['cpu'], 'class' => 'line-cur', 'label' => 'CPU'], 'mem' => ['values' => $s['mem'], 'class' => 'line-green', 'label' => 'メモリ'], 'load' => ['values' => $s['load'], 'class' => 'line-warn', 'label' => '負荷'], 'io' => ['values' => $s['iowait'], 'class' => 'line-err', 'label' => 'iowait']], $s['labels'], 100) ?: '<div class="empty">データがありません。</div>' !!}</div>
        </section>
        <section class="panel table-panel">
            <div class="panel-head"><div><h2 class="panel-title">マウント別ディスク</h2><p class="panel-sub">最新値</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>マウント</th><th>種別</th><th>使用率</th><th>空き</th><th>inode</th></tr></thead><tbody>
            @forelse ((array) ($l['disks'] ?? []) as $dk)
                @php($lv = $d['eval']['disk_used_pct:'.$dk['mount']]['level'] ?? 'ok')
                <tr><td class="mono">{{ $dk['mount'] }}</td><td>{{ $dk['fstype'] }}</td><td><span class="badge {{ $lv === 'ok' ? 'ok' : ($lv === 'warning' ? 'warn' : 'error') }}">{{ $dk['used_pct'] }}%</span></td><td class="mono">{{ $dk['free_gb'] }}GB / {{ $dk['size_gb'] }}GB</td><td class="mono">{{ $dk['inode_used_pct'] === null ? '—' : $dk['inode_used_pct'].'%' }}</td></tr>
            @empty
                <tr><td colspan="5"><div class="empty">ディスク情報はまだありません。</div></td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    </div>
    @if ($s['burst'])
        <section class="panel wide-panel">
            <div class="panel-head"><div><h2 class="panel-title">1 秒値（警告域の前後 2 分）</h2><p class="panel-sub">{{ count($s['burst']['labels']) }} 点</p></div></div>
            <div class="chart-shell">{!! Svg::multi(['cpu' => ['values' => $s['burst']['cpu'], 'class' => 'line-cur', 'label' => 'CPU'], 'io' => ['values' => $s['burst']['iowait'], 'class' => 'line-err', 'label' => 'iowait'], 'mem' => ['values' => $s['burst']['mem'], 'class' => 'line-green', 'label' => 'メモリ']], $s['burst']['labels'], 100) !!}</div>
        </section>
    @endif
    <div class="two-col">
        <section class="panel table-panel">
            <div class="panel-head"><div><h2 class="panel-title">しきい値と現在の判定</h2><p class="panel-sub">継続時間付き。ノード別の上書きは監視ルールで変更</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>指標</th><th>現在</th><th>警告</th><th>エラー</th><th>継続</th><th>判定</th></tr></thead><tbody>
            @foreach ($d['eval'] as $key => $r)
                @php($t = $d['thresholds'][$r['metric']] ?? [])
                <tr><td>{{ $r['label'] }}</td><td class="mono">{{ $r['value'] ?? '—' }}</td><td class="mono">{{ ($t['direction'] ?? '') === 'below' ? '<' : '≥' }} {{ $t['warning'] ?? '—' }}</td><td class="mono">{{ ($t['direction'] ?? '') === 'below' ? '<' : '≥' }} {{ $t['error'] ?? '—' }}</td><td>{{ ($t['duration'] ?? 0) ? intdiv($t['duration'], 60).' 分' : '即時' }}</td><td><span class="badge {{ $r['level'] === 'ok' ? 'ok' : ($r['level'] === 'warning' ? 'warn' : 'error') }}">{{ ['ok' => '正常', 'warning' => '警告', 'error' => 'エラー', 'critical' => '重大'][$r['level']] }}</span></td></tr>
            @endforeach
            </tbody></table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title">しきい値超過の履歴</h2><p class="panel-sub">最新 20 件</p></div></div>
            <div class="incident-list">
                @forelse ($d['incidents'] as $i)
                    <a class="incident-row" href="{{ IncidentDetail::getUrl(['publicId' => $i->public_id]) }}"><span class="severity-icon {{ $i->severity->value === 'WARNING' ? 'warn' : '' }}">{!! Icon::svg('alert') !!}</span><span class="incident-body"><b>{{ $i->title }}</b><small>{{ $i->public_id }} · {{ $i->workflow_status->label() }} · {{ $i->observation_state->label() }}</small></span><span class="incident-time">{{ P::jst($i->first_seen_at) }}</span></a>
                @empty
                    <div class="empty">超過の履歴はありません。</div>
                @endforelse
            </div>
        </section>
    </div>
</x-monitor.shell>
</div>
