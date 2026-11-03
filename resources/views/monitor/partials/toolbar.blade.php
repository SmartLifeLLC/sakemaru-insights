{{-- 期間・サービス・前期間比較（URL に反映。V-094） --}}
@php($range = $range ?? null)
<div class="toolbar">
    <div class="toolbar-left">
        <select class="field-select" wire:model.live="site" aria-label="対象サービス">
            <option value="">すべてのサービス</option>
            @foreach ($this->siteOptions() as $o)
                <option value="{{ $o['code'] }}">{{ $o['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="toolbar-right">
        @if ($range)
            <span class="range-caption">{!! \App\Monitoring\Support\Icon::svg('clock') !!}{{ $range[0]->setTimezone('Asia/Tokyo')->format('m/d H:i') }} – {{ $range[1]->setTimezone('Asia/Tokyo')->format('m/d H:i') }} JST</span>
        @endif
        <div class="segmented" role="group" aria-label="表示期間">
            @foreach (['24h' => '24時間', '7d' => '7日間', '30d' => '30日間'] as $k => $l)
                <button type="button" class="{{ $this->period === $k ? 'selected' : '' }}" aria-pressed="{{ $this->period === $k ? 'true' : 'false' }}" wire:click="setPeriod('{{ $k }}')">{{ $l }}</button>
            @endforeach
        </div>
        @if (property_exists($this, 'compare'))
            <label class="compare-control"><input type="checkbox" wire:model.live="compare">前期間と比較</label>
        @endif
    </div>
</div>
