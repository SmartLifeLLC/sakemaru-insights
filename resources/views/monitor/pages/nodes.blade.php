@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Svg')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\NodeDetail')
@use('App\Filament\Monitor\Pages\IncidentDetail')
@use('App\Monitoring\Resources\NodeSeries')
@use('App\Monitoring\Resources\ThresholdEvaluator')
@php($rows = $this->rows())
<div>
<x-monitor.shell :page="$this">
    <x-slot:toolbar>
        <div class="toolbar">
            <div class="toolbar-left"><span class="range-caption">{!! Icon::svg('clock') !!}システム負荷の推移（JST）</span></div>
            <div class="toolbar-right">
                <div class="segmented" role="group" aria-label="表示範囲">
                    @foreach (NodeSeries::LABELS as $k => $lab)
                        <button type="button" class="{{ $this->range === $k ? 'selected' : '' }}" wire:click="setRange('{{ $k }}')">{{ $lab }}</button>
                    @endforeach
                </div>
            </div>
        </div>
    </x-slot:toolbar>
    @if (! $rows)
        <section class="panel"><div class="empty">
            資源収集ノードはまだ登録されていません。<br>
            各サーバーの .env に <span class="mono">MONITOR_NODE_CODE</span>・<span class="mono">MONITOR_NODE_ROLE</span>・<span class="mono">MONITOR_NODE_DISPLAY_NAME</span> を設定し、
            <span class="mono">sakemaru-monitor-resources@.service</span> を起動すると、最初の送信で自動登録されます。
        </div></section>
    @endif
    <div class="node-cols {{ count($rows) === 1 ? 'single' : '' }}" wire:poll.visible.60s>
        @foreach ($rows as $r)
            @php($n = $r['node'])
            @php($s = $r['series'])
            @php($cur = $r['latest']['current'] ?? [])
            <section class="panel node-panel" x-data="{ tab: 'monitor' }">
                <div class="node-head">
                    <div>
                        <h3><span class="dot {{ ! $n->enabled ? '' : ($r['fresh'] ? ($r['breaches'] ? 'amber' : 'green') : 'red') }}" @if (! $n->enabled) style="background:var(--faint)" @endif></span>{{ $n->display_name }}
                            @if (! $n->enabled)<span class="badge neutral">無効</span>@endif
                            @if ($r['open'])<span class="badge error">未解決 {{ $r['open'] }}</span>@endif
                            @if ($r['latest']['burst'] ?? false)<span class="badge warn">1 秒記録中</span>@endif
                        </h3>
                        <small>{{ $n->node_code }} · {{ $n->role }} · {{ $r['latest']['cores'] ?? '?' }} コア · {{ $n->os_info_json['mem_total_gb'] ?? '?' }} GB · {{ $r['fresh'] ? '受信 '.P::ago($n->last_heartbeat_at) : '資源データ停止（'.P::ago($n->last_heartbeat_at).'）' }}</small>
                    </div>
                    <div class="node-now">
                        <span class="{{ $r['levels']['cpu'] !== 'ok' ? 'text-red' : '' }}"><b>{{ $r['cpu'] ?? '—' }}</b> CPU %</span>
                        <span class="{{ $r['levels']['mem'] !== 'ok' ? 'text-red' : '' }}"><b>{{ $r['mem'] ?? '—' }}</b> メモリ %</span>
                        <span class="{{ $r['levels']['load'] !== 'ok' ? 'text-red' : '' }}"><b>{{ $r['load'] ?? '—' }}</b> 負荷 %</span>
                        <span class="{{ $r['levels']['disk'] !== 'ok' ? 'text-red' : '' }}"><b>{{ $r['disk'] ?? '—' }}</b> ディスク %</span>
                    </div>
                </div>
                <div class="node-tabs" role="tablist">
                    @foreach (['monitor' => 'モニタリング', 'status' => 'ステータス', 'storage' => 'ストレージ', 'process' => 'プロセス', 'detail' => '詳細'] as $k => $lab)
                        <button type="button" role="tab" :class="tab === '{{ $k }}' ? 'active' : ''" x-on:click="tab = '{{ $k }}'">{{ $lab }}</button>
                    @endforeach
                </div>

                {{-- モニタリング: 時系列（AWS のモニタリングタブと同じ並び） --}}
                <div x-show="tab === 'monitor'" class="node-tab-body">
                    @if (! ($s['labels'] ?? []))
                        <div class="empty">この範囲の集約データはまだありません（5 分ごとに増えます）。</div>
                    @else
                        <div class="mini-charts">
                            @foreach ([['cpu', 'CPU 使用率 (%)', 'line-cur', 100], ['mem', 'メモリ使用率 (%)', 'line-green', 100], ['load', '負荷（load1 ÷ コア, %）', 'line-warn', null], ['iowait', 'I/O 待ち (%)', 'line-err', null], ['disk', 'ディスク使用率 (%)', 'line-purple', 100], ['swap', 'スワップ使用率 (%)', 'line-prev-solid', 100]] as [$key, $title, $cls, $max])
                                <div class="mini-chart">
                                    <div class="mini-chart-head"><span>{{ $title }}</span><b>{{ ($v = collect($s[$key])->last()) === null ? '—' : $v }}</b></div>
                                    {!! Svg::line($s[$key], null, $s['labels'], [], '%', 360, 120, $max, $cls) !!}
                                </div>
                            @endforeach
                        </div>
                        <div class="chart-foot"><span>{{ $s['source'] }} · {{ count($s['labels']) }} 点</span><a class="btn link" href="{{ NodeDetail::getUrl(['code' => $n->node_code]) }}?range=raw">30 秒値・1 秒バーストを見る →</a></div>
                    @endif
                </div>

                {{-- ステータス: 受信・しきい値超過・未解決 --}}
                <div x-show="tab === 'status'" class="node-tab-body" x-cloak>
                    <table class="kv-table">
                        <tr><th>資源データ</th><td>{!! $r['fresh'] ? '<span class="badge ok">受信中</span> '.P::ago($n->last_heartbeat_at) : '<span class="badge error">停止</span> 最終 '.P::jst($n->last_heartbeat_at) !!}</td></tr>
                        <tr><th>しきい値超過</th><td>
                            @php($bad = collect($r['eval'])->where('level', '!=', 'ok'))
                            @if ($bad->isEmpty())<span class="badge ok">なし</span>@else
                                @foreach ($bad as $b)<span class="badge {{ $b['level'] === 'warning' ? 'warn' : 'error' }}">{{ $b['label'] }} {{ $b['metric'] === 'process_missing' ? '停止' : $b['value'] }}</span> @endforeach
                            @endif
                        </td></tr>
                        <tr><th>未解決の案件</th><td>
                            @forelse ($r['open_list'] as $i)
                                <div><a href="{{ IncidentDetail::getUrl(['publicId' => $i->public_id]) }}" wire:navigate><x-monitor.sev :severity="$i->severity" /> {{ $i->public_id }} {{ \Illuminate\Support\Str::limit($i->title, 70) }}</a> <span class="muted">{{ P::ago($i->last_seen_at) }}</span></div>
                            @empty
                                <span class="badge ok">なし</span>
                            @endforelse
                        </td></tr>
                        <tr><th>現在値</th><td class="mono">CPU {{ $cur['cpu'] ?? '—' }}% · user {{ $cur['user'] ?? '—' }}% · system {{ $cur['system'] ?? '—' }}% · iowait {{ $cur['iowait'] ?? '—' }}% · steal {{ $cur['steal'] ?? '—' }}% · load1/5/15 {{ $cur['load1'] ?? '—' }} / {{ $cur['load5'] ?? '—' }} / {{ $cur['load15'] ?? '—' }} · メモリ {{ $cur['mem_used_pct'] ?? '—' }}%（空き {{ $cur['mem_available_pct'] ?? '—' }}%）· スワップ {{ $cur['swap_used_pct'] ?? '—' }}% · NW 受信 {{ $cur['net_rx_mbps'] ?? '—' }} / 送信 {{ $cur['net_tx_mbps'] ?? '—' }} Mbps</td></tr>
                    </table>
                </div>

                {{-- ストレージ --}}
                <div x-show="tab === 'storage'" class="node-tab-body" x-cloak>
                    <div class="table-scroll"><table>
                        <thead><tr><th>マウント</th><th>種別</th><th>容量</th><th>空き</th><th>使用率</th><th>inode</th></tr></thead>
                        <tbody>
                        @forelse ((array) ($r['latest']['disks'] ?? []) as $d)
                            <tr><td class="mono">{{ $d['mount'] }}</td><td>{{ $d['fstype'] }}</td><td class="mono">{{ $d['size_gb'] }} GB</td><td class="mono">{{ $d['free_gb'] }} GB</td>
                                <td><span class="badge {{ ($d['used_pct'] ?? 0) >= 90 ? 'error' : (($d['used_pct'] ?? 0) >= 80 ? 'warn' : 'ok') }}">{{ $d['used_pct'] }}%</span></td><td class="mono">{{ $d['inode_used_pct'] ?? '—' }}%</td></tr>
                        @empty
                            <tr><td colspan="6"><div class="empty">ディスク情報がありません。</div></td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </div>

                {{-- プロセス: 必須プロセスだけ --}}
                <div x-show="tab === 'process'" class="node-tab-body" x-cloak>
                    <div class="table-scroll"><table>
                        <thead><tr><th>プロセス</th><th>稼働数</th><th>状態</th></tr></thead>
                        <tbody>
                        @forelse ($r['required_procs'] as $key => $p)
                            @php($label = config('monitoring.resources.processes.'.$key.'.label', $key))
                            <tr><td>{{ $label }}</td><td class="mono">{{ (int) ($p['count'] ?? 0) }}</td>
                                <td>@if (! $r['fresh'])<span class="badge neutral">不明（受信停止）</span>@elseif ((int) ($p['count'] ?? 0) > 0)<span class="badge ok">稼働</span>@else<span class="badge critical">停止（{{ isset($p['missing_since']) ? P::ago($p['missing_since']) : '—' }}から）</span>@endif</td></tr>
                        @empty
                            <tr><td colspan="3"><div class="empty">必須プロセスが設定されていません（.env の MONITOR_NODE_PROCESSES）。</div></td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </div>

                {{-- 詳細 --}}
                <div x-show="tab === 'detail'" class="node-tab-body" x-cloak>
                    @php($os = (array) $n->os_info_json)
                    <table class="kv-table">
                        <tr><th>ノードコード</th><td class="mono">{{ $n->node_code }}</td></tr>
                        <tr><th>役割</th><td>{{ $n->role }}</td></tr>
                        <tr><th>ホスト名</th><td class="mono">{{ $os['hostname'] ?? '—' }}</td></tr>
                        <tr><th>カーネル / PHP</th><td class="mono">{{ $os['kernel'] ?? '—' }} / PHP {{ $os['php'] ?? '—' }}</td></tr>
                        <tr><th>CPU / メモリ</th><td>{{ $os['cores'] ?? '—' }} コア / {{ $os['mem_total_gb'] ?? '—' }} GB</td></tr>
                        <tr><th>収集器</th><td class="mono">v{{ $os['collector_version'] ?? '—' }} · 登録 {{ P::jst($n->created_at) }}（{{ $n->registered_from }}）</td></tr>
                        <tr><th>しきい値の上書き</th><td class="mono">{{ $n->thresholds_json ? collect($n->thresholds_json)->map(fn ($v, $k) => $k.': '.json_encode($v))->implode(' / ') : '既定' }}</td></tr>
                    </table>
                    <div class="chart-foot"><span></span><a class="btn link" href="{{ NodeDetail::getUrl(['code' => $n->node_code]) }}">ノード詳細（1 年・30 秒値・履歴）→</a></div>
                </div>
            </section>
        @endforeach
    </div>
</x-monitor.shell>
</div>
