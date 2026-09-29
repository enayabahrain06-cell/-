{{--
    Certificate frame as one SVG (A4 landscape at 96 dpi, 1123 × 794 px), embedded by pdf/certificate.blade.php as an image.
    $ornament: full (star band, corner rosettes, watermark) | minimal (plain band, no watermark) | off (gold line only).
    $basmala: shaped outlines from glyphs.json (dompdf cannot shape Aref Ruqaa).
    Colours: emerald #0f3d2e / #1f5a44, gold #c8a45a, Basmala #9c7424, parchment #fbf7ee.
--}}
@php
    $W = 1123; $H = 794; $cx = $W / 2;
    $gold = '#c8a45a'; $deep = '#0f3d2e'; $emerald = '#1f5a44'; $paper = '#fbf7ee';
    $f = fn ($n) => round($n, 2);

    // Eight-point star (Rub el Hizb, two squares at 45°) as a 16-vertex polygon.
    $star = function (float $x, float $y, float $r, float $turn = 22.5) use ($f): string {
        $inner = $r * cos(deg2rad(45)) / cos(deg2rad(22.5));
        $pts = [];
        for ($i = 0; $i < 16; $i++) {
            $a = deg2rad($turn + $i * 22.5 - 90);
            $d = $i % 2 ? $inner : $r;
            $pts[] = $f($x + $d * cos($a)).','.$f($y + $d * sin($a));
        }

        return implode(' ', $pts);
    };

    // Band tiles: the 28 px band's centre line, split between the corner squares into tiles of about 28 px.
    $band = 28; $mid = 42; $cornerHalf = 22;
    $tiles = [];
    foreach ([[$mid + $cornerHalf, $W - $mid - $cornerHalf, true], [$mid + $cornerHalf, $H - $mid - $cornerHalf, false]] as [$from, $to, $horizontal]) {
        $n = (int) round(($to - $from) / 28);
        $step = ($to - $from) / $n;
        for ($i = 0; $i < $n; $i++) {
            $c = $from + ($i + 0.5) * $step;
            $tiles[] = $horizontal ? [$c, $mid] : [$mid, $c];
            $tiles[] = $horizontal ? [$c, $H - $mid] : [$W - $mid, $c];
            $dot = $from + $i * $step;
            if ($i > 0) {
                $tiles[] = $horizontal ? [$dot, $mid, 'dot'] : [$mid, $dot, 'dot'];
                $tiles[] = $horizontal ? [$dot, $H - $mid, 'dot'] : [$W - $mid, $dot, 'dot'];
            }
        }
    }
    $corners = [[$mid, $mid], [$W - $mid, $mid], [$mid, $H - $mid], [$W - $mid, $H - $mid]];

    // Sura-header cartouche on the top band: an elongated hexagon.
    $cw = 190; $ch = 27; $cy = $mid; $tip = 24;
    $hex = fn (float $inset) => implode(' ', [
        $f($cx - $cw + $inset).','.$cy, $f($cx - $cw + $tip + $inset * 0.4).','.$f($cy - $ch + $inset), $f($cx + $cw - $tip - $inset * 0.4).','.$f($cy - $ch + $inset),
        $f($cx + $cw - $inset).','.$cy, $f($cx + $cw - $tip - $inset * 0.4).','.$f($cy + $ch - $inset), $f($cx - $cw + $tip + $inset * 0.4).','.$f($cy + $ch - $inset),
    ]);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}">
    <rect x="0" y="0" width="{{ $W }}" height="{{ $H }}" fill="{{ $paper }}"/>

    @if($ornament === 'full')
        {{-- Watermark: a 16-point rosette (two eight-point stars at three sizes, radiating lines, circles) behind the text. --}}
        <g fill="none" stroke="{{ $gold }}" stroke-width="1" stroke-opacity="0.16">
            @foreach([230, 160, 92] as $r)
                <polygon points="{{ $star($cx, 404, $r, 0) }}"/>
                <polygon points="{{ $star($cx, 404, $r, 22.5) }}"/>
            @endforeach
            @foreach([238, 124, 46] as $r)<circle cx="{{ $cx }}" cy="404" r="{{ $r }}"/>@endforeach
            @for($i = 0; $i < 16; $i++)
                @php $a = deg2rad($i * 22.5); @endphp
                <line x1="{{ $f($cx + 46 * sin($a)) }}" y1="{{ $f(404 - 46 * cos($a)) }}" x2="{{ $f($cx + 238 * sin($a)) }}" y2="{{ $f(404 - 238 * cos($a)) }}"/>
            @endfor
        </g>
    @endif

    {{-- Thin gold outer line, 22 px in. --}}
    <rect x="22" y="22" width="{{ $W - 44 }}" height="{{ $H - 44 }}" fill="none" stroke="{{ $gold }}" stroke-width="1.5"/>

    @if($ornament !== 'off')
        {{-- The emerald band, 28 px wide, starting 28 px in. --}}
        <path fill="{{ $deep }}" fill-rule="evenodd" d="M28 28 H{{ $W - 28 }} V{{ $H - 28 }} H28 Z M{{ 28 + $band }} {{ 28 + $band }} V{{ $H - 28 - $band }} H{{ $W - 28 - $band }} V{{ 28 + $band }} Z"/>
        @if($ornament === 'full')
            <g fill="none" stroke="{{ $gold }}" stroke-width="1">
                @foreach($tiles as $t)
                    @if(! isset($t[2]))<polygon points="{{ $star($t[0], $t[1], 9) }}"/>@endif
                @endforeach
            </g>
            <g fill="{{ $gold }}">
                @foreach($tiles as $t)
                    @if(isset($t[2]))<circle cx="{{ $f($t[0]) }}" cy="{{ $f($t[1]) }}" r="1.4"/>@else<circle cx="{{ $f($t[0]) }}" cy="{{ $f($t[1]) }}" r="1.6"/>@endif
                @endforeach
            </g>
        @endif
        {{-- Inside the band: a gold hairline, then an emerald hairline. --}}
        <rect x="61" y="61" width="{{ $W - 122 }}" height="{{ $H - 122 }}" fill="none" stroke="{{ $gold }}" stroke-width="0.9"/>
        <rect x="66" y="66" width="{{ $W - 132 }}" height="{{ $H - 132 }}" fill="none" stroke="{{ $emerald }}" stroke-width="0.6"/>

        {{-- Corners: an emerald square with a layered gold eight-point rosette. --}}
        @foreach($corners as [$x, $y])
            <rect x="{{ $x - $cornerHalf }}" y="{{ $y - $cornerHalf }}" width="{{ $cornerHalf * 2 }}" height="{{ $cornerHalf * 2 }}" fill="{{ $deep }}" stroke="{{ $gold }}" stroke-width="1.2"/>
            @if($ornament === 'full')
                <polygon points="{{ $star($x, $y, 18, 0) }}" fill="none" stroke="{{ $gold }}" stroke-width="1.2"/>
                <polygon points="{{ $star($x, $y, 12.5) }}" fill="{{ $gold }}"/>
                <circle cx="{{ $x }}" cy="{{ $y }}" r="5" fill="{{ $deep }}"/>
                <circle cx="{{ $x }}" cy="{{ $y }}" r="2" fill="{{ $gold }}"/>
            @else
                <polygon points="{{ $star($x, $y, 11) }}" fill="{{ $gold }}"/>
            @endif
        @endforeach

        {{-- Bottom centre: a small gold star medallion on the band. --}}
        <circle cx="{{ $cx }}" cy="{{ $H - $mid }}" r="17" fill="{{ $deep }}" stroke="{{ $gold }}" stroke-width="1.2"/>
        <polygon points="{{ $star($cx, $H - $mid, 13, 0) }}" fill="none" stroke="{{ $gold }}" stroke-width="1"/>
        <polygon points="{{ $star($cx, $H - $mid, 8) }}" fill="{{ $gold }}"/>
        <circle cx="{{ $cx }}" cy="{{ $H - $mid }}" r="2.4" fill="{{ $deep }}"/>
    @endif

    {{-- Top centre: the cartouche holding the Basmala (parchment fill, emerald edge, gold inner line). --}}
    <polygon points="{{ $hex(0) }}" fill="{{ $paper }}" stroke="{{ $deep }}" stroke-width="3"/>
    <polygon points="{{ $hex(5) }}" fill="none" stroke="{{ $gold }}" stroke-width="1"/>
    <g fill="#9c7424" transform="translate({{ $cx }} {{ $cy + 8 }})">{!! $basmala !!}</g>
</svg>
