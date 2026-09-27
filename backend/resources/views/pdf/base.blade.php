{{--
    Shared PDF chrome. Usage in a view:
        @extends('pdf.base')
        @section('title', pdf_ar('...'))
        @section('content') ... @endsection
    Arabic strings MUST go through pdf_ar(). Blocks that hold shaped Arabic use direction:ltr + text-align:right
    (ArPHP reverses the glyph run for dompdf).
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
{!! $fonts ?? '' !!}
@page { margin: 18mm 15mm; }
body { font-family: 'amiri', 'plexarabic', 'DejaVu Sans', sans-serif; font-size: 12pt; color: #1B2B28; }
.ar { direction: ltr; text-align: right; unicode-bidi: bidi-override; }
.en { direction: ltr; text-align: left; font-family: 'plexarabic', 'DejaVu Sans', sans-serif; }
.center { text-align: center; }
.muted { color: #4E5F59; font-size: 10pt; }
.emerald { color: #2E6B4F; }
.gold { color: #B8872E; }
h1 { font-size: 20pt; margin: 0 0 4pt; color: #2E6B4F; }
h2 { font-size: 15pt; margin: 0 0 4pt; }
table.grid { width: 100%; border-collapse: collapse; margin-top: 8pt; }
table.grid th, table.grid td { border: 1px solid #CBD6CE; padding: 5pt 6pt; vertical-align: middle; }
table.grid th { background: #E3EAE3; font-weight: bold; }
.header { border-bottom: 2px solid #B8872E; padding-bottom: 6pt; margin-bottom: 10pt; }
.footer { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 9pt; color: #4E5F59; text-align: center; }
.box { border: 1px solid #CBD6CE; padding: 8pt 10pt; margin-top: 8pt; }
.badge { display: inline-block; padding: 2pt 8pt; border: 1px solid #2E6B4F; color: #2E6B4F; border-radius: 10pt; font-size: 10pt; }
</style>
@yield('head')
</head>
<body>
<div class="header">
    <table style="width:100%"><tr>
        <td class="ar" style="width:60%">
            <h1>@yield('title')</h1>
            <div class="muted">{{ pdf_ar(setting('authority.name_ar', config('ahl.authority.name_ar'))) }}</div>
        </td>
        <td class="en muted" style="width:40%; text-align:right">
            {{ setting('authority.name_en', config('ahl.authority.name_en')) }}<br>
            {{ config('ahl.authority.system_en') }}
        </td>
    </tr></table>
</div>
@yield('content')
<div class="footer">{{ pdf_ar(config('ahl.authority.system_ar')) }} &nbsp;·&nbsp; {{ now()->setTimezone(config('ahl.display_timezone'))->format('Y-m-d H:i') }}</div>
</body>
</html>
