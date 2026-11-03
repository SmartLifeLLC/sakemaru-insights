@props(['severity'])
@php
    $v = $severity instanceof \BackedEnum ? $severity->value : (string) $severity;
    [$cls, $label] = match ($v) {
        'CRITICAL' => ['critical', '重大'],
        'ERROR' => ['error', 'エラー'],
        'WARNING' => ['warn', '警告'],
        'INFO' => ['info', '情報'],
        default => ['neutral', $v],
    };
@endphp
<span class="badge {{ $cls }}">{{ $label }}</span>
