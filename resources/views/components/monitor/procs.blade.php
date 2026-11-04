@props(['latest' => [], 'fresh' => true])
{{-- 必須プロセスだけを表示（必須でないもの、例: mod_php 構成の php-fpm は出さない） --}}
@php($procs = array_filter((array) ($latest['processes'] ?? []), fn ($p) => ! empty($p['required'])))
@if ($procs)
    <div class="actions-row" style="margin-top:10px;gap:5px" aria-label="プロセスの稼働">
        @foreach ($procs as $key => $p)
            @php($label = config('monitoring.resources.processes.'.$key.'.label', $key))
            @if (! $fresh)
                <span class="badge neutral">{{ $label }} —</span>
            @elseif ((int) ($p['count'] ?? 0) > 0)
                <span class="badge ok" title="{{ (int) $p['count'] }} プロセス">{{ $label }} {{ (int) $p['count'] }}</span>
            @else
                <span class="badge critical">{{ $label }} 停止</span>
            @endif
        @endforeach
    </div>
@endif
