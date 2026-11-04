@use('App\Monitoring\Support\Icon')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Models\Monitoring\Advisory')
@php
    $d = $this->data();
    $sevClass = fn (string $s) => match ($s) { 'CRITICAL' => 'critical', 'HIGH' => 'error', 'MEDIUM' => 'warn', 'LOW' => 'info', default => 'neutral' };
    $stClass = fn (string $s) => match ($s) { 'NEW' => 'warn', 'ACKNOWLEDGED' => 'blue', 'RESOLVED' => 'ok', default => 'neutral' };
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:toolbar>
        <div class="adv-headline level-{{ $d['headline']['level'] }}">
            {!! Icon::svg($d['headline']['level'] === 'ok' ? 'shield' : 'alert') !!}<span>{{ $d['headline']['text'] }}</span>
            <span class="adv-sources">
                @foreach (Advisory::SOURCES as $k => $label)
                    @php($f = $d['fetches'][$k] ?? null)
                    <span class="chip {{ $f ? ($f->status === 'OK' ? 'ok' : ($f->status === 'FAILED' ? 'fail' : '')) : '' }}" title="{{ $f ? ($f->status === 'OK' ? '最終取得 '.P::jst($f->started_at, 'm/d H:i').' · '.$f->fetched_count.' 件 / 新規 '.$f->new_count : ($f->error ?: $f->status)) : '未取得' }}">{{ $label }}</span>
                @endforeach
            </span>
        </div>
    </x-slot:toolbar>

    <section class="panel table-panel">
        <div class="tabs" style="padding:16px 20px 0;margin:0" role="tablist">
            @foreach (\App\Filament\Monitor\Pages\Advisories::TABS as $k => $l)
                <button type="button" role="tab" class="{{ $this->tab === $k ? 'active' : '' }}" aria-selected="{{ $this->tab === $k ? 'true' : 'false' }}" wire:click="setTab('{{ $k }}')">{{ $l }}<small>{{ $d['counts'][$k] }}</small></button>
            @endforeach
        </div>
        <div class="filter-row">
            <div class="filter-search">{!! Icon::svg('search') !!}<input type="search" wire:model.live.debounce.400ms="q" placeholder="CVE / GHSA / USN / 題名で検索" aria-label="検索"></div>
            <select class="field-select" wire:model.live="component" aria-label="製品"><option value="">すべての製品</option>@foreach ($d['products'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select class="field-select" wire:model.live="severity" aria-label="重大度"><option value="">すべての重大度</option>@foreach (Advisory::SEVERITIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select class="field-select" wire:model.live="source" aria-label="情報源"><option value="">すべての情報源</option>@foreach (Advisory::SOURCES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <button type="button" class="btn subtle" wire:click="resetFilters">条件を戻す</button>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>重大度</th><th>ID</th><th>内容</th><th>製品</th><th>情報源</th><th>公開</th><th>状態</th><th></th></tr></thead>
                <tbody>
                @forelse ($d['rows'] as $a)
                    <tr wire:click="openRow({{ $a->id }})" data-log>
                        <td><span class="badge {{ $sevClass($a->severity) }}">{{ $a->severityLabel() }}{{ $a->cvss !== null ? ' '.number_format($a->cvss, 1) : '' }}</span>
                            @if ($a->kev)<span class="badge critical" title="CISA KEV: 悪用が確認されています">悪用確認</span>@endif
                            @if ($a->installed_affected)<span class="badge error" title="導入中の版が影響範囲に入っています">導入版が影響</span>@endif</td>
                        <td class="mono">{{ $a->external_id }}@if ($a->cve_id && $a->cve_id !== $a->external_id)<small class="text-muted"> {{ $a->cve_id }}</small>@endif</td>
                        <td class="summary-cell" title="{{ $a->title }}">{{ \Illuminate\Support\Str::limit($a->title, 90) }}</td>
                        <td>@foreach ((array) $a->components_json as $c)<span class="chip">{{ $d['products'][$c] ?? $c }}</span>@endforeach</td>
                        <td class="code-name">{{ $a->sourceLabel() }}</td>
                        <td class="mono">{{ $a->published_at ? P::jst($a->published_at, 'm/d') : '—' }}</td>
                        <td><span class="badge {{ $stClass($a->status) }}">{{ $a->statusLabel() }}</span></td>
                        <td>@if ($this->canRespond() && $a->status === 'NEW')<button type="button" class="btn" style="padding:3px 8px" wire:click.stop="acknowledge({{ $a->id }})">確認済みにする</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty">{{ $d['counts']['all'] === 0 ? 'まだ脆弱性情報がありません。取得は owner ノードで 6 時間ごとに行われます（php artisan monitor:fetch-advisories）。' : '該当する脆弱性情報はありません。' }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($d['has_more'])
            <div class="pager"><span>{{ $this->limit }} 件まで表示</span><button type="button" class="btn" wire:click="more">さらに表示</button></div>
        @endif
    </section>

    <x-slot:drawer>
        @php($c = $d['current'])
        <x-monitor.drawer :open="(bool) $c" :title="$c ? $c->external_id : ''">
            @if ($c)
                <div class="actions-row" style="margin-bottom:14px">
                    <span class="badge {{ $sevClass($c->severity) }}">{{ $c->severityLabel() }}{{ $c->cvss !== null ? ' CVSS '.number_format($c->cvss, 1) : '' }}</span>
                    @if ($c->kev)<span class="badge critical">悪用確認（CISA KEV）</span>@endif
                    @if ($c->installed_affected)<span class="badge error">導入版が影響</span>@endif
                    @if ($c->epss !== null)<span class="badge neutral" title="FIRST EPSS: 30 日以内に悪用される確率の推定">EPSS {{ number_format($c->epss * 100, 1) }}%</span>@endif
                    <span class="badge {{ $stClass($c->status) }}">{{ $c->statusLabel() }}</span>
                </div>
                <h3 style="font-size:14px;margin:0 0 8px">{{ $c->title }}</h3>
                <div class="detail-grid">
                    <div class="detail-item"><span>情報源</span><b>{{ $c->sourceLabel() }}</b></div>
                    <div class="detail-item"><span>CVE</span><b class="mono">{{ $c->cve_id ?: '—' }}</b></div>
                    <div class="detail-item"><span>公開</span><b>{{ $c->published_at ? P::jst($c->published_at, 'Y/m/d H:i') : '—' }}</b></div>
                    <div class="detail-item"><span>情報源の更新</span><b>{{ $c->source_updated_at ? P::jst($c->source_updated_at, 'Y/m/d H:i') : '—' }}</b></div>
                    <div class="detail-item"><span>初回取得</span><b>{{ P::jst($c->first_seen_at, 'Y/m/d H:i') }}</b></div>
                    <div class="detail-item"><span>製品</span><b>{{ implode('・', array_map(fn ($k) => $d['products'][$k] ?? $k, (array) $c->components_json)) ?: '—' }}</b></div>
                </div>
                @if ($c->summary)
                    <div class="detail-block"><h3>概要（情報源の原文）</h3><pre>{{ \Illuminate\Support\Str::limit($c->summary, 3000) }}</pre></div>
                @endif
                @if ($c->affected_json)
                    <div class="detail-block"><h3>影響範囲</h3>
                        <table class="kv-table">
                            @foreach ((array) $c->affected_json as $row)
                                @if (is_array($row))
                                    <tr><td class="mono" style="font-size:11px">@foreach ($row as $k => $v)@if ($v !== null && $v !== '' && $v !== false)<span class="chip" style="margin:1px 4px 1px 0">{{ $k }}: {{ is_bool($v) ? ($v ? 'yes' : 'no') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) }}</span>@endif @endforeach</td></tr>
                                @endif
                            @endforeach
                        </table>
                    </div>
                @endif
                @if ($c->status !== 'NEW')
                    <div class="detail-block"><h3>状態の記録</h3><pre>{{ $c->statusLabel() }} · {{ $c->status_name ?: '—' }} · {{ $c->status_at ? P::jst($c->status_at, 'Y/m/d H:i') : '' }}{{ $c->status_note ? "\n".$c->status_note : '' }}</pre></div>
                @endif
                <div class="drawer-actions">
                    @if ($c->url)<a class="btn" href="{{ $c->url }}" target="_blank" rel="noopener noreferrer">{!! Icon::svg('external') !!}情報源を開く</a>@endif
                    @if ($this->canRespond()){{ ($this->statusAction)(['id' => $c->id]) }}@endif
                </div>
                <p class="form-note">対処の考え方は <a href="{{ \App\Filament\Monitor\Pages\Runbooks::getUrl(['key' => 'RB-23']) }}" style="color:var(--blue)">RB-23 脆弱性報告の確認と対応</a> を参照。情報源の更新・取得は 6 時間ごと。</p>
            @endif
        </x-monitor.drawer>
    </x-slot:drawer>
</x-monitor.shell>
</div>
