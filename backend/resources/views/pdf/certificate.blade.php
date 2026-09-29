{{--
    Certificate (spec section 16), A4 landscape, drawn at 96 dpi (1123 × 794 px). Data from the certificates package
    (CertificateService::renderView, AhlCertificateHost): $locale, $ornament (full|minimal|off), $issuer, $date (Y/m/d),
    $date_secondary (Hijri), $signatures[name,title,image], $cert[name,title,body,grade,certificate_no,qr,stamp,photo].
    Optional: $cert['stamp_image'] (data: URI) with $cert['stamp_rotation'] (degrees) replaces the default seal.

    dompdf does not shape Arabic and ignores direction: Arabic goes through pdf_ar() (shaped, visual order) inside
    left-to-right blocks, and the footer columns are laid out in visual order. The frame, seal and divider are SVG
    (pdf/certificate/*.blade.php); the Basmala and the seal lettering are Aref Ruqaa / Reem Kufi outlines from glyphs.json,
    because those fonts need shaping dompdf cannot do. Live text uses Amiri and IBM Plex Sans Arabic (Kufi-style labels).
--}}
@php
    $ar = $locale !== 'en';
    $t = fn ($k, $r = []) => __("certificates.$k", $r, $ar ? 'ar' : 'en');
    $indic = fn ($s) => $ar ? strtr((string) $s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) : (string) $s;
    // One Arabic line in visual order: words reversed, each word shaped on its own, digit runs kept left to right.
    // Word by word because ArPHP splits any run longer than 50 characters and prints the pieces in the wrong order.
    $vis = function (?string $text) use ($ar, $indic) {
        if (! $ar) {
            return e((string) $text);
        }
        $words = array_map(function ($word) use ($indic) {
            $runs = preg_split('/([0-9٠-٩]+)/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            return implode('', array_reverse(array_map(fn ($r) => preg_match('/^[0-9٠-٩]+$/u', $r) ? e($indic($r)) : pdf_ar($r), $runs)));
        }, preg_split('/\s+/u', trim((string) $text)) ?: []);

        return implode(' ', array_reverse($words));
    };
    // dompdf cannot stack two marks: a vowel sign next to a shadda prints detached («يُكرَّم»), so only the shadda is kept.
    $marks = fn ($s) => preg_replace('/[\x{064B}-\x{0650}\x{0652}]*\x{0651}[\x{064B}-\x{0650}\x{0652}]*/u', "\u{0651}", (string) $s);
    $txt = fn ($s) => $ar ? $vis($marks($s)) : e($s);
    // Word-wrap to lines of at most $max characters (reading order), each in visual order.
    $wrap = function (?string $text, int $max) use ($txt) {
        $lines = [];
        foreach (preg_split('/s+/u', trim((string) $text)) ?: [] as $word) {
            $last = count($lines) - 1;
            if ($last >= 0 && mb_strlen($lines[$last].' '.$word) <= $max) {
                $lines[$last] .= ' '.$word;
            } elseif ($word !== '') {
                $lines[] = $word;
            }
        }

        return array_map($txt, $lines);
    };

    $glyphs = json_decode(file_get_contents(resource_path('views/pdf/certificate/glyphs.json')), true);
    $svg = fn (string $view, array $data) => 'data:image/svg+xml;base64,'.base64_encode(view($view, $data)->render());

    [$y, $m, $d] = array_pad(explode('/', $date), 3, '');
    $gregorian = $ar ? pdf_ar('م').$indic($y).' / '.$indic($m).' / '.$indic($d) : e($date);
    $hijri = $date_secondary ? ($ar ? $vis(preg_replace('/\s+هـ$/u', 'هـ', $date_secondary)) : e($date_secondary)) : null;
    preg_match('/\d{4}/', (string) $date_secondary, $hy);
    $hijriYear = isset($hy[0]) ? strtr($hy[0], ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) : '';

    $stampImage = $cert['stamp_image'] ?? null;
    $stampRotation = (float) ($cert['stamp_rotation'] ?? -8);
    $sigs = $signatures ?: [['name' => null, 'title' => $t('signature_default'), 'image' => null]];
    $sigCell = function (array $s) use ($t, $txt) {
        $main = $s['name'] ?: $s['title'];
        $sub = $s['name'] ? $s['title'] : $t('signature');

        return ['image' => $s['image'], 'main' => $main ? $txt($main) : '', 'sub' => $sub ? $txt($sub) : ''];
    };
    $sigCells = array_map($sigCell, array_slice($sigs, 0, 2));
    // Column widths in visual order (QR or details, signatures, details or QR): two signatures need more of the row.
    [$qrW, $midW, $metaW] = count($sigCells) === 2 ? [15, 56, 29] : [30, 40, 30];
    if ($ar) {
        $sigCells = array_reverse($sigCells);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<style>
{!! $fonts ?? '' !!}
@font-face { font-family: 'plexarabic'; font-style: normal; font-weight: bold; src: url('{{ str_replace('\\', '/', resource_path('fonts/IBMPlexSansArabic-SemiBold.ttf')) }}') format('truetype'); }
@page { margin: 0; }
html, body { margin: 0; padding: 0; }
body { font-family: 'amiri', 'DejaVu Sans', sans-serif; color: #1e2823; background: #fbf7ee; }
.page { position: relative; width: 1123px; height: 793px; overflow: hidden; }
.bg { position: absolute; top: 0; left: 0; width: 1123px; height: 794px; }
.v { direction: ltr; unicode-bidi: bidi-override; }
.center { position: absolute; left: 120px; right: 120px; text-align: center; }
.kufi { font-family: 'plexarabic', 'DejaVu Sans', sans-serif; }
.issuer { top: 94px; font-size: 17px; font-weight: bold; color: #1f5a44; }
.title { top: 84px; font-size: 66px; font-weight: bold; color: #0f3d2e; line-height: 1.15; }
.divider { top: 222px; }
.certify { top: 242px; font-size: 21px; }
.name { top: 242px; }
.name span { display: inline-block; padding: 0 56px 4px; border-bottom: 1px solid #c8a45a; font-size: 50px; font-weight: bold; color: #0f3d2e; line-height: 1.2; }
.body { top: 356px; font-size: 21px; line-height: 1.3; }
.prayer { font-size: 17px; color: #56655c; margin-top: -2px; }
.grade { margin: 12px auto 0; border-collapse: separate; border: 1px solid #c8a45a; border-radius: 20px; background: #e2ece6; }
.grade td { height: 38px; padding: 0 12px; vertical-align: middle; line-height: 1; }
.grade .label { font-size: 14px; color: #1f5a44; padding-top: 4px; }
.grade .value { font-size: 22px; font-weight: bold; color: #0f3d2e; }
.footer { position: absolute; left: 96px; right: 96px; bottom: 82px; }
.footer table { width: 100%; border-collapse: collapse; }
.footer td { vertical-align: bottom; padding: 0; }
.meta td { padding: 2px 0; vertical-align: middle; white-space: nowrap; }
.meta .label { font-size: 13px; color: #1f5a44; }
.meta .value { font-size: 16px; color: #1e2823; }
.ltr { direction: ltr; unicode-bidi: embed; font-family: 'DejaVu Sans', sans-serif; font-size: 13px; }
.sig { width: 190px; text-align: center; }
.sig-pair .sig { width: 160px; }
.sig .space { height: 50px; }
.sig img { max-height: 50px; max-width: 180px; }
.sig .line { border-top: 1px solid #56655c; margin: 0 0 4px; }
.sig .main { font-size: 15px; font-weight: bold; color: #0f3d2e; }
.sig .sub { font-size: 12px; color: #56655c; }
.qr-box { display: inline-block; background: #ffffff; border: 3px double #c8a45a; padding: 5px; }
.qr-box img { width: 86px; height: 86px; }
.hint { font-size: 12px; color: #56655c; margin-top: 3px; }
.photo { position: absolute; top: 96px; width: 68px; height: 68px; border: 2px solid #c8a45a; }
.status-stamp { position: absolute; top: 330px; left: 0; right: 0; text-align: center; font-size: 80px; font-weight: bold; color: #B3261E; opacity: 0.18; transform: rotate(-16deg); }
</style>
</head>
<body>
<div class="page">
    <img class="bg" src="{{ $svg('pdf.certificate.frame', ['ornament' => $ornament, 'basmala' => $glyphs['basmala']['svg']]) }}" alt="">

    @if(!empty($cert['photo']))
        <img class="photo" style="{{ $ar ? 'right' : 'left' }}: 96px" src="{{ $cert['photo'] }}" alt="">
    @endif

    <div class="center issuer kufi v">{!! $txt($issuer) !!}</div>
    <div class="center title v">{!! $txt($cert['title']) !!}</div>
    <div class="center divider"><img src="{{ $svg('pdf.certificate.divider', []) }}" width="300" height="16" alt=""></div>
    <div class="center certify v">{!! $txt($t('certify_by', ['issuer' => $issuer])) !!}</div>
    <div class="center name"><span class="v">{!! $txt($cert['name']) !!}</span></div>
    <div class="center body">
        @if($ar)
            @foreach($wrap($cert['body'], 90) as $line)<div class="v">{!! $line !!}</div>@endforeach
        @else
            <div>{{ $cert['body'] }}</div>
        @endif
        <div class="prayer v">{!! $txt($t('prayer')) !!}</div>
        @if(!empty($cert['grade']))
            <table class="grade"><tr>
                @if($ar)
                    <td class="value v" style="padding-left: 26px">{!! $vis($cert['grade']) !!}</td><td class="label kufi v" style="padding-right: 26px">{!! $txt($t('grade_line')) !!}</td>
                @else
                    <td class="label kufi" style="padding-left: 26px">{{ $t('grade_line') }}</td><td class="value" style="padding-right: 26px">{{ $cert['grade'] }}</td>
                @endif
            </tr></table>
        @endif
    </div>

    @php
        $qrCell = function () use ($cert, $txt, $t) {
            if (empty($cert['qr'])) {
                return '';
            }

            return '<div class="qr-box"><img src="'.e($cert['qr']).'" alt=""></div><div class="hint kufi v">'.$txt($t('verify_hint')).'</div>';
        };
        $seal = $stampImage
            ? '<img src="'.e($stampImage).'" alt="" style="max-width:120px;max-height:120px;opacity:0.9;transform:rotate('.$stampRotation.'deg)">'
            : '<img src="'.$svg('pdf.certificate.seal', ['g' => $glyphs, 'year' => $hijriYear]).'" width="124" height="124" alt="">';
        $rows = [[$t('issued_on'), $gregorian], ...($hijri ? [[$t('hijri_label'), $hijri]] : []), [$t('certificate_no'), '<span class="ltr">'.e($cert['certificate_no']).'</span>']];
    @endphp
    <div class="footer"><table><tr>
        {{-- Visual order, left to right. Arabic: QR · seal and signatures · details. English mirrors it. --}}
        <td style="width: {{ $ar ? $qrW : $metaW }}%; text-align: {{ $ar ? 'left' : 'right' }}">
            @if($ar)
                <div style="text-align: center; width: 110px">{!! $qrCell() !!}</div>
            @else
                <table class="meta" style="width: auto">
                    @foreach($rows as [$label, $value])
                        <tr><td class="label kufi" style="padding-right: 18px">{{ $label }}</td><td class="value">{!! $value !!}</td></tr>
                    @endforeach
                </table>
            @endif
        </td>
        <td style="width: {{ $midW }}%; text-align: center">
            <table class="{{ count($sigCells) === 2 ? 'sig-pair' : '' }}" style="width: auto; margin: 0 auto"><tr>
                @foreach($sigCells as $i => $s)
                    @if($i === (count($sigCells) === 2 ? 1 : 0) && ! $stampImage && $ar)<td style="padding: 0 18px 0 0">{!! $seal !!}</td>@endif
                    <td class="sig">
                        <div class="space">@if($s['image'])<img src="{{ $s['image'] }}" alt="">@endif</div>
                        <div class="line"></div>
                        <div class="main kufi v">{!! $s['main'] !!}</div>
                        <div class="sub kufi v">{!! $s['sub'] !!}</div>
                    </td>
                    @if($stampImage && $i === count($sigCells) - 1)<td style="padding: 0"><div style="margin-left: -20px">{!! $seal !!}</div></td>@endif
                    @if($i === 0 && ! $stampImage && ! $ar)<td style="padding: 0 0 0 18px">{!! $seal !!}</td>@endif
                @endforeach
            </tr></table>
        </td>
        <td style="width: {{ $ar ? $metaW : $qrW }}%; text-align: {{ $ar ? 'right' : 'left' }}">
            @if($ar)
                <table class="meta" style="width: auto; margin-left: auto">
                    @foreach($rows as [$label, $value])
                        <tr><td class="value" style="text-align: right">{!! $value !!}</td><td class="label kufi v" style="text-align: right; padding-left: 18px">{!! $txt($label) !!}</td></tr>
                    @endforeach
                </table>
            @else
                <div style="text-align: center; width: 110px; margin-left: auto">{!! $qrCell() !!}</div>
            @endif
        </td>
    </tr></table></div>

    @if(!empty($cert['stamp']))
        <div class="status-stamp v">{!! $txt($cert['stamp']) !!}</div>
    @endif
</div>
</body>
</html>
