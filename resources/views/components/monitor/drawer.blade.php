@props(['open' => false, 'title' => '', 'close' => 'closeDrawer'])
@if ($open)
    <div class="drawer-backdrop" wire:click="{{ $close }}" aria-hidden="true"></div>
    <aside class="drawer" role="dialog" aria-modal="true" aria-label="{{ $title }}" x-data x-on:keydown.escape.window="$wire.{{ $close }}()" x-init="$el.focus()" tabindex="-1">
        <div class="drawer-top">
            <h2>{{ $title }}</h2>
            <button type="button" class="icon-btn" wire:click="{{ $close }}" aria-label="閉じる">{!! \App\Monitoring\Support\Icon::svg('close') !!}</button>
        </div>
        {{ $slot }}
    </aside>
@endif
