{{--
    Default certificate, A4 landscape. Data (CertificateService::renderView, then Host::viewData):
    $locale, $rtl, $ornament (full|minimal|off), $issuer, $date, $date_secondary, $signatures[{name,title,image}],
    $cert[name,title,body,grade,certificate_no,qr,stamp,photo].
    dompdf does not shape Arabic: for Arabic output, point certificates.pdf.view at a view that shapes text
    (and bind a PdfRenderer with Arabic fonts). Publish this view with --tag=certificates-views to restyle it.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<style>
{!! $fonts ?? '' !!}
@page { margin: 9mm; }
body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; margin: 0; }
.page { position: relative; height: 190mm; }
.frame { position: absolute; top: 0; left: 0; right: 0; bottom: 0; }
.frame-full { border: 6pt double #b8872e; }
.frame-full .inner { position: absolute; top: 5pt; left: 5pt; right: 5pt; bottom: 5pt; border: 1pt solid #1f4e79; }
.frame-minimal { border: 4pt double #b8872e; }
.frame-off { border: 0.75pt solid #d1d5db; }
.content { position: absolute; top: 16mm; left: 20mm; right: 20mm; text-align: center; }
.issuer { font-size: 13pt; color: #4b5563; }
.title { font-size: 30pt; color: #b8872e; margin: 4pt 0 6pt; }
.line { font-size: 15pt; margin: 3pt 0; }
.name { font-size: 28pt; color: #1f4e79; font-weight: bold; margin: 6pt 0 4pt; }
.body { font-size: 15pt; line-height: 1.5; }
.grade { font-size: 14pt; margin-top: 6pt; color: #1f4e79; }
.photo { width: 58pt; height: 58pt; border: 2pt solid #b8872e; }
.footer { position: absolute; left: 16mm; right: 16mm; bottom: 12mm; }
.footer td { vertical-align: bottom; font-size: 11pt; text-align: center; }
.sig-img { height: 36pt; }
.sig-name { font-weight: bold; }
.meta { font-size: 10pt; color: #4b5563; }
.qr { width: 64pt; height: 64pt; }
.stamp { position: absolute; top: 70mm; left: 0; right: 0; text-align: center; font-size: 54pt; color: #b3261e; opacity: 0.22; transform: rotate(-18deg); }
</style>
</head>
<body>
@php $t = fn ($k) => __("certificates::certificates.$k", [], $locale); @endphp
<div class="page">
    <div class="frame frame-{{ $ornament }}">@if($ornament === 'full')<div class="inner"></div>@endif</div>

    <div class="content">
        <div class="issuer">{{ $issuer }}</div>
        <div class="title">{{ $cert['title'] }}</div>
        <div class="line">{{ $issuer }} {{ $t('certify') }}</div>
        @if(!empty($cert['photo']))<div><img class="photo" src="{{ $cert['photo'] }}" alt=""></div>@endif
        <div class="name">{{ $cert['name'] }}</div>
        <div class="body">{{ $cert['body'] }}</div>
        @if(!empty($cert['grade']))<div class="grade">{{ $t('grade_line') }}: {{ $cert['grade'] }}</div>@endif
    </div>

    <div class="footer"><table style="width:100%"><tr>
        <td style="width:22%">
            @if(!empty($cert['qr']))
                <img class="qr" src="{{ $cert['qr'] }}" alt=""><br>
                <span class="meta">{{ $t('verify_hint') }}</span>
            @endif
        </td>
        @foreach($signatures as $s)
            <td>
                @if($s['image'])<img class="sig-img" src="{{ $s['image'] }}" alt=""><br>@else<div style="height:36pt"></div>@endif
                ______________________<br>
                @if($s['name'])<span class="sig-name">{{ $s['name'] }}</span><br>@endif
                @if($s['title'])<span class="meta">{{ $s['title'] }}</span>@endif
            </td>
        @endforeach
        <td style="width:26%" class="meta">
            <div>{{ $t('issued_on') }}: {{ $date }}</div>
            @if($date_secondary)<div>{{ $date_secondary }}</div>@endif
            <div>{{ $t('certificate_no') }}: {{ $cert['certificate_no'] }}</div>
        </td>
    </tr></table></div>

    @if(!empty($cert['stamp']))<div class="stamp">{{ $cert['stamp'] }}</div>@endif
</div>
</body>
</html>
