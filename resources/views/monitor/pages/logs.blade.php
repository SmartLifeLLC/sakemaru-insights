@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Ui')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\IncidentDetail')
@use('App\Filament\Monitor\Pages\Runbooks')
@php
    $page = $this->page();
    $open = $this->openLogData();
@endphp
<div>
<x-monitor.shell :page="$this">
    <x-slot:buttons>
        <button type="button" class="btn" wire:click="exportCsv">{!! Icon::svg('down') !!}CSV エクスポート</button>
    </x-slot:buttons>

    <section class="panel table-panel" @if (! $this->cursor) wire:poll.visible.60s @endif>
        <div class="tabs" style="padding:16px 20px 0;margin:0" role="tablist">
            @foreach (['all' => 'すべて', 'info' => '正常・情報', 'warning' => '警告', 'error' => 'エラー・重大'] as $k => $l)
                <button type="button" role="tab" class="{{ $this->tab === $k ? 'active' : '' }}" aria-selected="{{ $this->tab === $k ? 'true' : 'false' }}" wire:click="setTab('{{ $k }}')">{{ $l }}</button>
            @endforeach
            <span style="flex:1"></span>
            @if ($page && $page->hasNewer)
                <button type="button" class="badge blue" wire:click="refreshNewest">新着あり — 最新を表示</button>
            @endif
        </div>
        <form class="filter-row" wire:submit="applySearch">
            <div class="filter-search">{!! Icon::svg('search') !!}<input type="search" wire:model="search" placeholder="エラー番号（MON-…）・チェック名・ノード名で検索" aria-label="ログを検索" @if ($this->focus) autofocus @endif></div>
            <select class="field-select" wire:model.live="site" aria-label="サービス"><option value="">すべてのサービス</option>@foreach ($this->siteOptions() as $o)<option value="{{ $o['code'] }}">{{ $o['label'] }}</option>@endforeach</select>
            @if ($this->tab === 'all')
                <select class="field-select" wire:model.live="severity" aria-label="重大度"><option value="">すべての重大度</option><option value="INFO">情報</option><option value="WARNING">警告</option><option value="ERROR">エラー</option><option value="CRITICAL">重大</option></select>
            @endif
            <select class="field-select" wire:model.live="checkKey" aria-label="チェック"><option value="">すべてのチェック</option>@foreach ($this->checkKeys() as $ck)<option value="{{ $ck }}">{{ $ck }}</option>@endforeach</select>
            <select class="field-select" wire:model.live="sourceKind" aria-label="種別"><option value="">すべての種別</option><option value="CHECK">チェック</option><option value="APPLICATION_LOG">アプリログ</option><option value="MONITOR_SYSTEM">監視システム</option></select>
            <input class="field-select" type="datetime-local" wire:model.live="from" aria-label="受信 開始（JST）">
            <input class="field-select" type="datetime-local" wire:model.live="to" aria-label="受信 終了（JST）">
            <input class="field-select" type="date" wire:model.live="businessDate" aria-label="対象営業日">
            <select class="field-select" wire:model.live="pageSize" aria-label="件数">@foreach (config('monitoring.ui.page_size_options') as $o)<option value="{{ $o }}">{{ $o }} 件</option>@endforeach</select>
            <button type="submit" class="btn primary">検索</button>
            <button type="button" class="btn subtle" wire:click="resetFilters">条件を戻す</button>
        </form>
        @if ($this->incident || $this->node || $this->checkKey)
            <div class="filter-row" style="padding-top:8px;padding-bottom:8px">
                @if ($this->incident)<span class="chip">エラー番号 {{ $this->incident }}</span>@endif
                @if ($this->checkKey)<span class="chip">チェック {{ $this->checkKey }}</span>@endif
                @if ($this->node)<span class="chip">ノード {{ $this->node }}</span>@endif
            </div>
        @endif
        @if ($this->error)
            <div class="flash error" style="margin:12px 20px">{{ $this->error }}</div>
        @endif
        @include('monitor.partials.log-table', ['rows' => $page?->items ?? [], 'clickable' => true])
        @if ($page)
            <div class="pager">
                <span>表示基準 {{ P::jst($page->asOf, 'H:i:s') }} JST · 閲覧中に届いた行は途中に混ぜません{{ $this->cursor ? '（続きを表示中）' : '' }}</span>
                <button type="button" class="btn" wire:click="goTo(null)" @disabled(! $this->cursor)>先頭</button>
                <button type="button" class="btn" wire:click="goTo('{{ $page->prevCursor }}')" @disabled(! $page->prevCursor)>前へ</button>
                <button type="button" class="btn" wire:click="goTo('{{ $page->nextCursor }}')" @disabled(! $page->nextCursor)>次へ</button>
            </div>
        @endif
    </section>

    <x-slot:drawer>
        <x-monitor.drawer :open="(bool) $open" :title="'ログ詳細 #'.($open['log']->id ?? '')">
            @if ($open && $open['log'])
                @php($l = $open['log'])
                <div class="actions-row"><x-monitor.sev :severity="$l->severity" />@if ($l->outcome)<x-monitor.outcome :outcome="$l->outcome" />@endif<span class="badge neutral">{{ Ui::label($l->source_kind) }}</span></div>
                <p class="drawer-intro" style="margin-top:14px;color:var(--text)">{{ $l->summary }}</p>
                <div class="detail-grid">
                    <div class="detail-item"><span>サービス / ノード</span><b>{{ $this->siteLabel((int) $l->site_id) }}</b><br><small class="text-muted">{{ $l->node_code ?: '—' }}</small></div>
                    <div class="detail-item"><span>受信 / 発生（JST）</span><b class="mono">{{ P::jst($l->received_at, 'Y/m/d H:i:s') }}</b><br><small class="text-muted mono">発生 {{ P::jst($l->occurred_at, 'Y/m/d H:i:s') }}</small></div>
                    <div class="detail-item"><span>チェック / 分類</span><b class="mono">{{ $l->check_key ?: '—' }}</b><br><small class="text-muted mono">{{ $l->error_code }}{{ $l->run_id ? ' run#'.$l->run_id : '' }}</small></div>
                    <div class="detail-item"><span>対象営業日 / 件数</span><b class="mono">{{ $l->business_date?->format('Y-m-d') ?? '—' }}</b><br><small class="text-muted">{{ number_format($l->occurrence_delta) }} 件 · {{ $l->retention_class }} は {{ P::jst($l->expires_at, 'Y/m/d') }} まで保持</small></div>
                </div>
                @if ($open['incident'])
                    <div class="info-box">関連エラー <a class="btn link" href="{{ IncidentDetail::getUrl(['publicId' => $open['incident']->public_id]) }}">{{ $open['incident']->public_id }}</a> · {{ $open['incident']->workflow_status->label() }} / {{ $open['incident']->observation_state->label() }}</div>
                @endif
                <div class="detail-block"><h3>指標（metrics）</h3>@if ($l->metrics_json)<pre>{{ json_encode($l->metrics_json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>@else<span class="text-muted">なし</span>@endif</div>
                <div class="detail-block"><h3>証拠（伏字済み）</h3>@if ($l->evidence_json)<pre>{{ json_encode($l->evidence_json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>@else<span class="text-muted">なし</span>@endif</div>
                @if ($open['runbook'])
                    <div class="detail-block"><h3>対処手順 {{ $open['runbook']->runbook_key }}</h3><b>{{ $open['runbook']->title }}</b><pre>{{ $open['runbook']->first_checks }}</pre><a class="btn link" href="{{ Runbooks::getUrl(['key' => $open['runbook']->runbook_key]) }}">手順を開く {!! Icon::svg('arrow') !!}</a></div>
                @endif
                <div class="drawer-actions" x-data="{ copied: false, text: @js($open['copy']) }">
                    <span class="form-note" x-show="copied">コピーしました</span>
                    <textarea class="input" x-ref="fallback" x-show="false" readonly x-text="text"></textarea>
                    <button type="button" class="btn" x-on:click="navigator.clipboard ? navigator.clipboard.writeText(text).then(() => copied = true).catch(() => { $refs.fallback.style.display='block'; $refs.fallback.select(); }) : ($refs.fallback.style.display='block', $refs.fallback.select())">{!! Icon::svg('copy') !!}ログ本文をコピー</button>
                    <button type="button" class="btn" wire:click="closeDrawer">閉じる</button>
                </div>
            @elseif ($open)
                <div class="empty">ログが見つからないか、閲覧権限がありません。</div>
            @endif
        </x-monitor.drawer>
    </x-slot:drawer>
</x-monitor.shell>
</div>
