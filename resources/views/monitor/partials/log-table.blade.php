@use('App\Filament\Monitor\MonitorPage', 'P')

<div class="table-scroll">
    <table>
        <thead><tr><th>受信</th><th>重大度</th><th>サービス</th><th>種別 / チェック</th><th>内容</th><th>判定</th><th>ノード</th><th>エラー番号</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr @if ($clickable ?? false) wire:click="openLog({{ (int) $r->id }})" data-log @endif>
                <td class="mono">{{ P::jst($r->received_at ?? $r->occurred_at, 'm/d H:i:s') }}</td>
                <td><x-monitor.sev :severity="$r->severity" /></td>
                <td>{{ $this->siteLabel((int) $r->site_id) }}</td>
                <td class="code-name">{{ $r->check_key ?: \App\Monitoring\Support\Ui::label($r->source_kind) }}{{ ! $r->check_key && $r->error_code ? ' '.$r->error_code : '' }}</td>
                <td class="summary-cell" title="{{ $r->summary }}">{{ \Illuminate\Support\Str::limit((string) $r->summary, 90) }}</td>
                <td>@if ($r->outcome)<x-monitor.outcome :outcome="$r->outcome" />@else<span class="text-muted">—</span>@endif</td>
                <td class="node-chip">{{ $r->node_code }}</td>
                <td class="mono">@if ($r->incident_id)@php($pid = \App\Monitoring\Support\PublicId::incident((int) $r->incident_id))<a class="btn link" href="{{ \App\Filament\Monitor\Pages\IncidentDetail::getUrl(['publicId' => $pid]) }}" wire:click.stop>{{ $pid }}</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="8"><div class="empty">該当するログはありません。</div></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
