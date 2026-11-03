@props(['page', 'buttons' => null, 'toolbar' => null, 'drawer' => null])
@use('App\Monitoring\Support\Icon')
@php
    $s = $page->shell();
    $nav = [
        'WORKSPACE' => [
            ['dashboard', 'grid', 'ダッシュボード', \App\Filament\Monitor\Pages\Dashboard::getUrl()],
            ['services', 'server', 'サービス一覧', \App\Filament\Monitor\Pages\Services::getUrl()],
            ['analytics', 'chart', '統計・比較', \App\Filament\Monitor\Pages\Analytics::getUrl()],
        ],
        'MONITORING' => [
            ['incidents', 'alert', 'エラー管理', \App\Filament\Monitor\Pages\Incidents::getUrl()],
            ['logs', 'logs', '監視ログ', \App\Filament\Monitor\Pages\Logs::getUrl()],
            ['rules', 'sliders', '監視ルール', \App\Filament\Monitor\Pages\Rules::getUrl()],
            ['notifications', 'bell', '通知設定', \App\Filament\Monitor\Pages\Notifications::getUrl()],
            ['runbooks', 'book', '対処手順', \App\Filament\Monitor\Pages\Runbooks::getUrl()],
            ['nodes', 'cpu', 'ノード', \App\Filament\Monitor\Pages\Nodes::getUrl()],
        ],
    ];
    $infraOk = $s['infra_total'] === 0 || $s['infra_alive'] === $s['infra_total'];
@endphp
<div class="mon-body" x-data="{ menu: false }"
     x-on:keydown.window="if ($event.key === '/' && ! ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) { $event.preventDefault(); window.location.href = @js(\App\Filament\Monitor\Pages\Logs::getUrl()) + '?focus=1'; }">
    <a class="skip" href="#main">メインコンテンツへ</a>
    <aside id="sidebar" class="sidebar" :class="{ 'open': menu }" aria-label="サイドバー">
        <a href="{{ \App\Filament\Monitor\Pages\Dashboard::getUrl() }}" class="brand" aria-label="算盤 監視 ホーム">
            <span class="brand-mark">算</span>
            <span><b>算盤 <span class="brand-en">監視</span></b><small>SAKEMARU OBSERVABILITY</small></span>
        </a>
        <div class="workspace" title="配置 {{ $s['deployment'] }}">
            <span class="workspace-icon">{{ mb_substr($s['client'], 0, 1) }}</span>
            <div><b>{{ $s['client'] }}</b><small>{{ $s['env'] }} / 酒丸システム</small></div>
            <span class="workspace-status" style="background: {{ $infraOk ? 'var(--green)' : 'var(--amber)' }}"></span>
        </div>
        <nav aria-label="メインナビゲーション">
            @foreach ($nav as $group => $links)
                <div class="nav-group">
                    <p class="nav-label">{{ $group }}</p>
                    @foreach ($links as [$key, $ico, $label, $url])
                        <a class="nav-link {{ $s['nav_key'] === $key ? 'active' : '' }}" href="{{ $url }}" @if ($s['nav_key'] === $key) aria-current="page" @endif wire:navigate.hover>
                            {!! Icon::svg($ico) !!}<span>{{ $label }}</span>
                            @if ($key === 'incidents' && $s['open'] > 0)
                                <span class="nav-badge" aria-label="未解決 {{ $s['open'] }} 件">{{ $s['open'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
        <div class="sidebar-bottom">
            <div class="collector">
                <span class="dot {{ $infraOk ? 'green' : 'amber' }}"></span><b>{{ $infraOk ? '監視基盤 正常' : '監視基盤 要確認' }}</b>
                <span class="mono">{{ $s['infra_alive'] }} / {{ $s['infra_total'] }}</span>
            </div>
            <p>収集器・資源収集ノードの稼働（5 分以内の受信）</p>
            <a href="{{ \App\Filament\Monitor\Pages\Nodes::getUrl() }}" class="sidebar-bottom-link">ノード・監視基盤を確認 <span>↗</span></a>
            <div class="version"><span>SAKEMARU</span><span>算盤 監視 v2</span></div>
        </div>
    </aside>
    <div class="app-shell" x-on:click="menu = false">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="icon-btn mobile-menu" x-on:click.stop="menu = ! menu" aria-label="メニューを開く">{!! Icon::svg('menu') !!}</button>
                <span class="top-section">運用監視</span><span class="top-divider"></span><span class="top-sub">{{ $s['client'] }} の監視コンソール</span>
            </div>
            <div class="topbar-actions">
                <a class="search-trigger" href="{{ \App\Filament\Monitor\Pages\Logs::getUrl() }}?focus=1">{!! Icon::svg('search') !!}<span>ログを検索</span><kbd>/</kbd></a>
                {{-- 通知の状態ボタン: 運用中（緑・押すと一時停止）／停止中（赤・押すと再開） --}}
                @if ($page->alertPause())
                    {{ $page->resumeAlertsAction }}
                @else
                    {{ $page->pauseAlertsAction }}
                @endif
                <span class="env-badge {{ $s['env_class'] }}" title="1 DB = 1 環境（表示のみ）"><i></i>{{ $s['client'] }} {{ $s['env'] }}</span>
                <button type="button" class="icon-btn" aria-label="ライト/ダークを切り替え"
                        x-data
                        x-on:click="(() => { const h = document.documentElement; const t = h.dataset.theme === 'light' ? 'dark' : 'light'; h.dataset.theme = t; h.classList.toggle('dark', t === 'dark'); try { localStorage.setItem('sakemaru-monitor-theme-v2', t); localStorage.setItem('theme', t); } catch (e) {} })()">
                    {!! Icon::svg('sun') !!}
                </button>
                <a class="icon-btn {{ $s['unack'] > 0 ? 'notification-btn' : '' }}" href="{{ \App\Filament\Monitor\Pages\Incidents::getUrl(['tab' => 'unacknowledged']) }}" aria-label="未確認のエラー {{ $s['unack'] }} 件">{!! Icon::svg('bell') !!}</a>
                <span class="avatar" title="{{ $s['user_name'] }}">{{ $s['initials'] }}</span>
            </div>
        </header>
        <main id="main" tabindex="-1">
            <div class="heading">
                <div><h1>{{ $s['title'] }}</h1><p>{{ $s['description'] }}</p></div>
                <div class="heading-buttons">{{ $buttons }}</div>
            </div>
            @foreach ($page->banners() as $b)
                <div class="banner {{ $b['level'] === 'error' ? 'error' : 'warning' }}" role="status">{!! Icon::svg('alert') !!}<span>{{ $b['text'] }}</span></div>
            @endforeach
            @if ($page->flash)
                <div class="flash {{ $page->flashLevel === 'ok' ? 'ok' : ($page->flashLevel === 'warn' ? 'warn' : 'error') }}" role="status">
                    {{ $page->flash }}
                    <button type="button" class="btn link" style="float:right" wire:click="dismissFlash">閉じる</button>
                </div>
            @endif
            {{ $toolbar }}
            {{ $slot }}
        </main>
        <footer class="footer">
            <span><span class="dot {{ $infraOk ? 'green' : 'amber' }}"></span>{{ $s['client'] }} {{ $s['env'] }} / 配置 {{ $s['deployment'] }}</span>
            <span>Asia/Tokyo (JST) <span class="footer-sep">·</span> {{ $s['now_jst'] }} 時点</span>
        </footer>
    </div>
    {{ $drawer }}
    <x-filament-actions::modals />
</div>
