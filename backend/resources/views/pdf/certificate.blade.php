{{--
    Certificate (spec section 16), A4 landscape. Data from CertificateService::viewData():
    $locale, $ornament (full|minimal|off), $authority, $gregorian, $hijri, $signatures[], $cert[name,title,body,grade,certificate_no,qr,stamp,photo].
    Arabic lines are shaped one visual line at a time (pdf_ar / pdf_ar_lines); mixed lines are emitted in reverse order.
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
{!! $fonts ?? '' !!}
@page { margin: 9mm; }
body { font-family: 'amiri', 'plexarabic', 'DejaVu Sans', sans-serif; color: #1B2B28; margin: 0; }
.page { position: relative; height: 190mm; }
.frame { position: absolute; top: 0; left: 0; right: 0; bottom: 0; }
.frame-full { border: 6pt double #B8872E; }
.frame-full .inner { position: absolute; top: 5pt; left: 5pt; right: 5pt; bottom: 5pt; border: 1pt solid #2E6B4F; }
.frame-minimal { border: 4pt double #B8872E; }
.frame-off { border: 0.75pt solid #CBD6CE; }
.corner { position: absolute; width: 16pt; height: 16pt; border: 1.5pt solid #B8872E; background: #FBF6EA; transform: rotate(45deg); }
.corner i { position: absolute; top: 4pt; left: 4pt; width: 8pt; height: 8pt; background: #2E6B4F; }
.content { position: absolute; top: 16mm; left: 20mm; right: 20mm; text-align: center; }
.c { direction: ltr; unicode-bidi: bidi-override; text-align: center; }
.en { font-family: 'plexarabic', 'DejaVu Sans', sans-serif; }
.authority { font-size: 13pt; color: #4E5F59; }
.title { font-size: 30pt; color: #B8872E; margin: 4pt 0 6pt; }
.line { font-size: 15pt; margin: 3pt 0; }
.name { font-size: 28pt; color: #2E6B4F; font-weight: bold; margin: 6pt 0 4pt; }
.body { font-size: 15pt; line-height: 1.5; margin: 0 auto; }
.grade { font-size: 14pt; margin-top: 6pt; color: #2E6B4F; }
.photo { width: 58pt; height: 58pt; border: 2pt solid #B8872E; }
.footer { position: absolute; left: 16mm; right: 16mm; bottom: 12mm; }
.footer td { vertical-align: bottom; font-size: 11pt; }
.sig-img { height: 36pt; }
.sig-name { font-weight: bold; }
.meta { font-size: 10pt; color: #4E5F59; }
.qr { width: 64pt; height: 64pt; }
.stamp { position: absolute; top: 70mm; left: 0; right: 0; text-align: center; font-size: 54pt; color: #B3261E; opacity: 0.22; transform: rotate(-18deg); }
</style>
</head>
<body>
@php $ar = $locale !== 'en'; $t = fn ($k) => __("certificates.$k", [], $ar ? 'ar' : 'en'); $cls = $ar ? 'c' : 'c en'; @endphp
<div class="page">
    <div class="frame frame-{{ $ornament }}">@if($ornament === 'full')<div class="inner"></div>@endif</div>
    @if($ornament === 'full')
        <div class="corner" style="top:-8pt;left:-8pt"><i></i></div>
        <div class="corner" style="top:-8pt;right:-8pt"><i></i></div>
        <div class="corner" style="bottom:-8pt;left:-8pt"><i></i></div>
        <div class="corner" style="bottom:-8pt;right:-8pt"><i></i></div>
    @endif

    <div class="content">
        <div class="authority {{ $cls }}">{{ $ar ? pdf_ar($authority) : $authority }}</div>
        <div class="title {{ $cls }}">{{ $ar ? pdf_ar($cert['title']) : $cert['title'] }}</div>
        @if($ar)
            <div class="line c">{{ pdf_ar($authority.' '.$t('certify')) }}</div>
        @else
            <div class="line c en">{{ $authority }} {{ $t('certify') }}</div>
        @endif
        @if(!empty($cert['photo']))<div class="c"><img class="photo" src="{{ $cert['photo'] }}" alt=""></div>@endif
        <div class="name c">{{ pdf_ar($cert['name']) }}</div>
        <div class="body">
            @if($ar)
                @foreach(pdf_ar_lines($cert['body'], 75) as $line)<div class="c">{{ $line }}</div>@endforeach
            @else
                <div class="c en" style="unicode-bidi: normal">{{ $cert['body'] }}</div>
            @endif
        </div>
        @if(!empty($cert['grade']))
            @if($ar)
                <div class="grade c">{{ pdf_ar($cert['grade']) }} :{{ pdf_ar($t('grade_line')) }}</div>
            @else
                <div class="grade c en">{{ $t('grade_line') }}: {{ $cert['grade'] }}</div>
            @endif
        @endif
    </div>

    <div class="footer"><table style="width:100%"><tr>
        {{-- Arabic reads right to left: the QR sits on the left, signatures to its right. --}}
        <td style="width:22%; text-align:center">
            @if(!empty($cert['qr']))
                <img class="qr" src="{{ $cert['qr'] }}" alt=""><br>
                <span class="meta {{ $cls }}">{{ $ar ? pdf_ar($t('verify_hint')) : $t('verify_hint') }}</span>
            @endif
        </td>
        @foreach($ar ? array_reverse($signatures) : $signatures as $s)
            <td style="text-align:center">
                @if($s['image'])<img class="sig-img" src="{{ $s['image'] }}" alt=""><br>@else<div style="height:36pt"></div>@endif
                ______________________<br>
                @if($s['name'])<span class="sig-name {{ $cls }}">{{ pdf_ar($s['name']) }}</span><br>@endif
                @if($s['title'])<span class="meta {{ $cls }}">{{ pdf_ar($s['title']) }}</span>@endif
            </td>
        @endforeach
        <td style="width:26%; text-align:center" class="meta">
            @if($ar)
                <div class="c">{{ $gregorian }} :{{ pdf_ar($t('issued_on')) }}</div>
                @if($hijri)<div class="c">{{ pdf_ar($hijri) }}</div>@endif
                <div class="c">{{ $cert['certificate_no'] }} :{{ pdf_ar($t('certificate_no')) }}</div>
            @else
                <div class="en">{{ $t('issued_on') }}: {{ $gregorian }}</div>
                @if($hijri)<div class="en">{{ $hijri }}</div>@endif
                <div class="en">{{ $t('certificate_no') }}: {{ $cert['certificate_no'] }}</div>
            @endif
        </td>
    </tr></table></div>

    @if(!empty($cert['stamp']))
        <div class="stamp {{ $cls }}">{{ $ar ? pdf_ar($cert['stamp']) : $cert['stamp'] }}</div>
    @endif
</div>
</body>
</html>
