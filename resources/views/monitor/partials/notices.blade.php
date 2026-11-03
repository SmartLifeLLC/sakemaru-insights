@use('App\Monitoring\Support\Icon')
<div class="notices">
    @if ($unack > 0)
        <a class="banner error" href="{{ $incidentsUrl }}" wire:navigate>{!! Icon::svg('alert') !!}<span>未確認のエラーが {{ $unack }} 件あります。エラー管理で確認してください。</span><span class="notice-go">→</span></a>
    @endif
    @forelse ($banners as $b)
        <div class="banner {{ $b['level'] === 'error' ? 'error' : 'warning' }}" @if (($b['kind'] ?? '') === 'pause') data-kind="pause" @endif role="status">{!! Icon::svg('alert') !!}<span>{{ $b['text'] }}</span></div>
    @empty
        @if ($unack === 0)
            <div class="empty" style="padding:28px 12px">お知らせはありません。監視基盤は正常です。</div>
        @endif
    @endforelse
    @if (count($banners) > 0)
        <p class="form-note" style="margin-top:12px">設定に関する項目は <a href="{{ $notificationsUrl }}" wire:navigate style="color:var(--blue)">通知設定</a> から対応できます。</p>
    @endif
</div>
