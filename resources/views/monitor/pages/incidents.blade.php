@use('App\Monitoring\Support\Icon')
@use('App\Monitoring\Support\Ui')
@use('App\Filament\Monitor\MonitorPage', 'P')
@use('App\Filament\Monitor\Pages\IncidentDetail')
@use('App\Filament\Monitor\Pages\Runbooks')
@php
    $page = $this->page();
    $counts = $this->tabCounts();
    $now = \Carbon\CarbonImmutable::now('UTC');
    $o = $this->opened();
@endphp
<div>
<x-monitor.shell :page="$this">
    <section class="panel table-panel" @if (! $this->cursor) wire:poll.visible.60s @endif>
        <div class="tabs" style="padding:16px 20px 0;margin:0" role="tablist">
            @foreach (['unacknowledged' => ['未確認', $counts['unacknowledged']], 'critical' => ['重大', $counts['critical']], 'active' => ['未解決', $counts['active']], 'recovered' => ['復旧確認待ち', $counts['recovered']], 'resolved' => ['解決・終了', null], 'all' => ['すべて', null]] as $k => [$l, $c])
                <button type="button" role="tab" class="{{ $this->tab === $k ? 'active' : '' }}" aria-selected="{{ $this->tab === $k ? 'true' : 'false' }}" wire:click="setTab('{{ $k }}')">{{ $l }}@if ($c !== null)<small>{{ $c }}</small>@endif</button>
            @endforeach
            <span style="flex:1"></span>
            @if ($page && $page->hasNewer)<button type="button" class="badge blue" wire:click="refreshNewest">新着あり — 最新を表示</button>@endif
        </div>
        <form class="filter-row" wire:submit="applyFilters">
            <div class="filter-search">{!! Icon::svg('search') !!}<input type="search" wire:model="search" placeholder="エラー番号（MON-…）・内容の前方一致" aria-label="エラーを検索"></div>
            <select class="field-select" wire:model.live="site" aria-label="サービス"><option value="">すべてのサービス</option>@foreach ($this->siteOptions() as $so)<option value="{{ $so['code'] }}">{{ $so['label'] }}</option>@endforeach</select>
            <select class="field-select" wire:model.live="severity" aria-label="重大度"><option value="">すべての重大度</option><option value="CRITICAL">重大</option><option value="ERROR">エラー</option><option value="WARNING">警告</option></select>
            <select class="field-select" wire:model.live="pageSize" aria-label="件数">@foreach (config('monitoring.ui.page_size_options') as $ps)<option value="{{ $ps }}">{{ $ps }} 件</option>@endforeach</select>
            <button type="submit" class="btn primary">検索</button>
            <button type="button" class="btn subtle" wire:click="resetFilters">条件を戻す</button>
            <span class="bulk-slot">{{ $this->bulkAction }}</span>
        </form>
        @if ($this->error)<div class="flash error" style="margin:12px 20px">{{ $this->error }}</div>@endif
        <div class="table-scroll">
            <table>
                <thead><tr><th class="select-col"><input type="checkbox" aria-label="表示中の全件を選択" wire:click="toggleSelectAll" @checked(($page?->items ?? []) !== [] && count(array_intersect(array_map(fn ($x) => $x->public_id, $page?->items ?? []), $this->selected)) === count($page?->items ?? []))></th><th>重大度</th><th>エラー番号</th><th>サービス</th><th>内容</th><th>観測</th><th>対応</th><th>最終検知</th><th>回数</th><th>担当</th><th>未確認時間</th><th>通知</th></tr></thead>
                <tbody>
                @forelse (($page?->items ?? []) as $i)
                    <tr data-incident wire:click="openIncident('{{ $i->public_id }}')">
                        <td class="select-col" wire:click.stop><input type="checkbox" value="{{ $i->public_id }}" wire:model.live="selected" aria-label="{{ $i->public_id }} を選択"></td>
                        <td><x-monitor.sev :severity="$i->severity" /></td>
                        <td class="mono">{{ $i->public_id }}</td>
                        <td>{{ $this->siteLabel((int) $i->site_id) }}</td>
                        <td class="summary-cell" title="{{ $i->title }}">{{ $i->title }}@if ($i->generation > 1) <span class="badge neutral">第{{ $i->generation }}世代</span>@endif</td>
                        <td><span class="badge {{ $i->observation_state->value === 'RECOVERED' ? 'ok' : ($i->observation_state->value === 'ACTIVE' ? 'error' : 'blue') }}">{{ $i->observation_state->label() }}</span></td>
                        <td>{{ $i->workflow_status->label() }}</td>
                        <td class="mono">{{ P::jst($i->last_seen_at) }}</td>
                        <td class="mono">{{ number_format($i->occurrence_count) }}</td>
                        <td>{{ $i->assigned_name ?: '—' }}</td>
                        <td>{{ $i->acknowledged_at ? '確認済み' : ($i->workflow_status->isOpenish() ? Ui::duration((int) \Carbon\CarbonImmutable::instance($i->first_seen_at)->diffInSeconds($now)) : '—') }}</td>
                        <td>@if ($i->last_delivery_status)<span class="badge {{ $i->last_delivery_status === 'DELIVERED' ? 'ok' : ($i->last_delivery_status === 'BLOCKED' ? 'error' : 'neutral') }}">{{ Ui::label($i->last_delivery_status) }}</span>@endif @if ($i->snoozed_until && $i->snoozed_until->greaterThan($now))<span class="badge warn">抑止中</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="12"><div class="empty">{{ $this->tab === 'unacknowledged' ? '未確認のエラーはありません。' : '該当するエラーはありません。条件を変えるか「すべて」タブで確認してください。' }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($page)
            <div class="pager">
                <span>表示基準 {{ P::jst($page->asOf, 'H:i:s') }} JST · 重大度・新しい順</span>
                <button type="button" class="btn" wire:click="goTo(null)" @disabled(! $this->cursor)>先頭</button>
                <button type="button" class="btn" wire:click="goTo('{{ $page->prevCursor }}')" @disabled(! $page->prevCursor)>前へ</button>
                <button type="button" class="btn" wire:click="goTo('{{ $page->nextCursor }}')" @disabled(! $page->nextCursor)>次へ</button>
            </div>
        @endif
    </section>

    <x-slot:drawer>
        <x-monitor.drawer :open="(bool) $o" :title="$o?->public_id ?? ''">
            @if ($o)
                <div class="actions-row"><x-monitor.sev :severity="$o->severity" /><span class="badge neutral">{{ $this->siteLabel((int) $o->site_id) }}</span><span class="badge neutral">{{ $o->workflow_status->label() }}</span><span class="badge {{ $o->observation_state->value === 'RECOVERED' ? 'ok' : 'error' }}">{{ $o->observation_state->label() }}</span></div>
                <p class="drawer-intro" style="margin-top:14px;color:var(--text);font-size:13px">{{ $o->title }}</p>
                <div class="detail-grid">
                    <div class="detail-item"><span>初回 / 最終検知</span><b class="mono">{{ P::jst($o->first_seen_at) }} / {{ P::jst($o->last_seen_at) }}</b></div>
                    <div class="detail-item"><span>回数 / 世代</span><b>{{ number_format($o->occurrence_count) }} 回 / 第{{ $o->generation }}世代</b></div>
                    <div class="detail-item"><span>確認</span><b>{{ $o->acknowledged_at ? $o->acknowledged_name.'（'.P::jst($o->acknowledged_at).'）' : '未確認' }}</b></div>
                    <div class="detail-item"><span>担当</span><b>{{ $o->assigned_name ?: '未割当' }}</b></div>
                    <div class="detail-item"><span>対象</span><b>{{ $o->business_date?->format('Y-m-d') ?? ($o->node_code ?: '—') }}</b></div>
                    <div class="detail-item"><span>チェック</span><b class="mono">{{ $o->check_key ?: $o->error_code }}</b></div>
                </div>
                @if ($o->runbook_key)
                    <div class="info-box">対処手順 {{ $o->runbook_key }} <a class="btn link" href="{{ Runbooks::getUrl(['key' => $o->runbook_key]) }}">手順を開く {!! Icon::svg('arrow') !!}</a></div>
                @endif
                <div class="drawer-actions">
                    @if ($o->workflow_status->value === 'OPEN')
                        <button type="button" class="btn" wire:click="quick('acknowledge', {{ (int) $o->version }})">{!! Icon::svg('check') !!}確認</button>
                    @endif
                    @if ($o->workflow_status->isOpenish() && $o->workflow_status->value !== 'IN_PROGRESS')
                        <button type="button" class="btn" wire:click="quick('start', {{ (int) $o->version }})">対応開始</button>
                    @endif
                    <a class="btn primary" href="{{ IncidentDetail::getUrl(['publicId' => $o->public_id]) }}">詳細を開く {!! Icon::svg('arrow') !!}</a>
                </div>
            @endif
        </x-monitor.drawer>
    </x-slot:drawer>
</x-monitor.shell>
</div>
