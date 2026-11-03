@props(['latest' => [], 'fresh' => true])
@php($procs = (array) ($latest['processes'] ?? []))
@if ($procs)
    <div class="actions-row" style="margin-top:10px;gap:5px" aria-label="プロセスの稼働">
        @foreach ($procs as $key => $p)
            @php($label = config('monitoring.resources.processes.'.$key.'.label', $key))
            @if (! $fresh)
                <span class="badge neutral">{{ $label }} —</span>
            @elseif ((int) ($p['count'] ?? 0) > 0)
                <span class="badge ok" title="{{ (int) $p['count'] }} プロセス">{{ $label }} {{ (int) $p['count'] }}</span>
            @elseif (! empty($p['required']))
                <span class="badge critical">{{ $label }} 停止</span>
            @else
                <span class="badge neutral" title="このサーバーでは必須ではありません">{{ $label }} なし</span>
            @endif
        @endforeach
    </div>
@endif
