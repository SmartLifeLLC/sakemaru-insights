@use('App\Monitoring\Support\Icon')
@php
    // 推奨設定（info）は対応が任意なので、警告・エラーとは分けて下に小さく出す（バッジにも数えない）
    $infos = array_values(array_filter($banners, fn ($b) => $b['level'] === 'info'));
    $banners = array_values(array_filter($banners, fn ($b) => $b['level'] !== 'info'));
@endphp
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
    @if ($infos)
        <details class="notice-infos" style="margin-top:14px">
            <summary style="cursor:pointer;font-size:12px;color:var(--muted)">推奨設定（任意） {{ count($infos) }} 件</summary>
            <ul style="margin:8px 0 0;padding-left:18px;font-size:12px;color:var(--muted)">
                @foreach ($infos as $b)<li style="margin:4px 0">{{ $b['text'] }}</li>@endforeach
            </ul>
        </details>
    @endif
</div>
