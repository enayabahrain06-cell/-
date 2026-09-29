{{--
    Default round seal (no stamp image uploaded), 124 × 124 px: pale gold disc, gold rings, emerald centre with a gold
    eight-point star outline, "سار" in Ruqaa, "هيئة التعليم الديني" curved over the top and the Hijri year at the bottom.
    $g: glyphs.json (shaped outlines), $year: Arabic-Indic digits or ''.
--}}
@php
    $gold = '#c8a45a'; $deep = '#0f3d2e';
    $pts = function (float $r, float $turn) {
        $inner = $r * cos(deg2rad(45)) / cos(deg2rad(22.5));
        $out = [];
        for ($i = 0; $i < 16; $i++) {
            $a = deg2rad($turn + $i * 22.5 - 90);
            $d = $i % 2 ? $inner : $r;
            $out[] = round($d * cos($a), 2).','.round($d * sin($a), 2);
        }

        return implode(' ', $out);
    };
    // The year's digits, laid out left to right and centred.
    $digits = array_values(array_filter(mb_str_split($year), fn ($d) => isset($g['digits'][$d])));
    $yearWidth = array_sum(array_map(fn ($d) => $g['digits'][$d]['w'], $digits));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="124" height="124" viewBox="-62 -62 124 124">
    <circle r="60" fill="{{ $gold }}" fill-opacity="0.2"/>
    <circle r="60" fill="none" stroke="{{ $gold }}" stroke-width="2"/>
    <circle r="56" fill="none" stroke="{{ $gold }}" stroke-width="0.8"/>
    <circle r="36" fill="{{ $deep }}" stroke="{{ $gold }}" stroke-width="1.5"/>
    <polygon points="{{ $pts(31, 0) }}" fill="none" stroke="{{ $gold }}" stroke-width="1"/>
    <g fill="{{ $gold }}" transform="translate(0 8)">{!! $g['sar']['svg'] !!}</g>
    <g fill="{{ $deep }}">{!! $g['seal_arc']['svg'] !!}</g>
    <g fill="{{ $deep }}">
        @php $x = -$yearWidth / 2; @endphp
        @foreach($digits as $d)
            <g transform="translate({{ round($x, 2) }} 51)">{!! $g['digits'][$d]['svg'] !!}</g>
            @php $x += $g['digits'][$d]['w']; @endphp
        @endforeach
    </g>
    @if($digits)
        <polygon points="{{ $pts(2.6, 0) }}" fill="{{ $deep }}" transform="translate({{ round(-$yearWidth / 2 - 8, 2) }} 48)"/>
        <polygon points="{{ $pts(2.6, 0) }}" fill="{{ $deep }}" transform="translate({{ round($yearWidth / 2 + 8, 2) }} 48)"/>
    @else
        <polygon points="{{ $pts(3.2, 0) }}" fill="{{ $deep }}" transform="translate(0 47)"/>
    @endif
</svg>
