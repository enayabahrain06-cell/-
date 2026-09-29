{{-- Ornamental divider under the title, 300 × 16 px: gold lines, small diamonds and a central eight-point star. --}}
@php
    $gold = '#c8a45a';
    $inner = 7 * cos(deg2rad(45)) / cos(deg2rad(22.5));
    $star = implode(' ', array_map(fn ($i) => round(150 + ($i % 2 ? $inner : 7) * cos(deg2rad($i * 22.5 - 90)), 2).','.round(8 + ($i % 2 ? $inner : 7) * sin(deg2rad($i * 22.5 - 90)), 2), range(0, 15)));
    $diamond = fn (float $x) => "{$x},4.5 ".($x + 3.5).',8 '."{$x},11.5 ".($x - 3.5).',8';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="300" height="16" viewBox="0 0 300 16">
    <g stroke="{{ $gold }}" stroke-width="1">
        <line x1="0" y1="8" x2="118" y2="8"/>
        <line x1="182" y1="8" x2="300" y2="8"/>
    </g>
    <g fill="{{ $gold }}">
        <polygon points="{{ $diamond(126) }}"/>
        <polygon points="{{ $diamond(174) }}"/>
        <polygon points="{{ $star }}"/>
    </g>
    <circle cx="150" cy="8" r="2.2" fill="#fbf7ee"/>
</svg>
