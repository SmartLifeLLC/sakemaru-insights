@props(['outcome'])
@php
    $v = $outcome instanceof \BackedEnum ? $outcome->value : (string) $outcome;
    [$cls, $label] = match ($v) {
        'HEALTHY' => ['ok', '正常'],
        'WARNING' => ['warn', '警告'],
        'VIOLATION' => ['error', '違反'],
        'UNKNOWN' => ['blue', '判定不能'],
        'NOT_DUE' => ['neutral', '判定前'],
        '' => ['neutral', '—'],
        default => ['neutral', $v],
    };
@endphp
<span class="badge {{ $cls }}">{{ $label }}</span>
