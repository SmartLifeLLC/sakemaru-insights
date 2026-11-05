<div class="mrb-card">
    <div class="mrb-brand">
        <img class="mrb-brand-mark" src="{{ asset('images/moribito/shield.svg') }}" alt="" width="60" height="60">
        <img class="mrb-brand-word" src="{{ asset('images/moribito/wordmark.svg') }}" alt="酒丸守人 もりびと" width="266" height="92">
    </div>

    <h1 class="mrb-heading">{{ $this->getHeading() }}</h1>
    @if (filled($sub = $this->getSubheading()))
        <p class="mrb-subheading">{{ $sub }}</p>
    @endif

    <div class="mrb-form">
        {{ $this->content }}
    </div>

    <div class="mrb-loading" wire:loading.class.delay="is-on" wire:target="authenticate" role="status" aria-live="polite">
        <img src="{{ asset('images/moribito/loading.gif') }}" alt="" width="200" height="177">
        <span>確認しています…</span>
    </div>

    <x-filament-actions::modals />
</div>
