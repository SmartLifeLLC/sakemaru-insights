@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Svg')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\NodeDetail')
@php($rows = $this->rows())
<div>
<x-monitor.shell :page="$this">
    @if (! $rows)
        <section class="panel"><div class="empty">
            資源収集ノードはまだ登録されていません。<br>
            各サーバーの .env に <span class="mono">MONITOR_NODE_CODE</span>・<span class="mono">MONITOR_NODE_ROLE</span>・<span class="mono">MONITOR_NODE_DISPLAY_NAME</span> を設定し、
            <span class="mono">sakemaru-monitor-resources@.service</span> を起動すると、最初の送信で自動登録されます。
        </div></section>
    @endif
    <div class="node-grid" wire:poll.visible.60s>
        @foreach ($rows as $r)
            <a class="panel node-card" href="{{ NodeDetail::getUrl(['code' => $r['node']->node_code]) }}">
                <h3><span class="dot {{ ! $r['node']->enabled ? '' : ($r['fresh'] ? ($r['breaches'] ? 'amber' : 'green') : 'red') }}" @if (! $r['node']->enabled) style="background:var(--faint)" @endif></span>{{ $r['node']->display_name }}
                    @if (! $r['node']->enabled)<span class="badge neutral">無効</span>@endif
                    @if ($r['open'])<span class="badge error">未解決 {{ $r['open'] }}</span>@endif
                    @if ($r['latest']['burst'] ?? false)<span class="badge warn">1 秒記録中</span>@endif
                </h3>
                <small>{{ $r['node']->node_code }} · {{ $r['node']->role }} · {{ $r['latest']['cores'] ?? '?' }} コア · {{ $r['node']->os_info_json['mem_total_gb'] ?? '?' }}GB · {{ $r['fresh'] ? '受信 '.P::ago($r['node']->last_heartbeat_at) : '資源データ停止（'.P::ago($r['node']->last_heartbeat_at).'）' }}</small>
                <div class="gauges">
                    <div class="gauge">{!! Svg::gauge($r['cpu'], $r['levels']['cpu']) !!}<span>CPU %</span></div>
                    <div class="gauge">{!! Svg::gauge($r['mem'], $r['levels']['mem']) !!}<span>メモリ %</span></div>
                    <div class="gauge">{!! Svg::gauge($r['disk'], $r['levels']['disk']) !!}<span>ディスク %</span></div>
                    <div class="gauge">{!! Svg::gauge($r['load'], $r['levels']['load']) !!}<span>負荷 %</span></div>
                </div>
                <x-monitor.procs :latest="$r['latest']" :fresh="$r['fresh']" />
                <div style="margin-top:8px;position:relative;height:28px">{!! Svg::spark((array) ($r['latest']['recent']['cpu'] ?? []), 220, 28) !!}</div>
            </a>
        @endforeach
    </div>
</x-monitor.shell>
</div>
