{{-- Generic report PDF: title, period, summary box, one table per section. Arabic cells go through pdf_ar(). --}}
@extends('pdf.base')
@php $ar = $locale !== 'en'; $txt = fn ($v) => $ar ? pdf_ar((string) $v) : (string) $v; @endphp
@section('title', $txt($report['title']))
@section('head')
<style>
    td.num, th.num { text-align: center; direction: ltr; }
    .section-title { font-size: 13pt; color: #2E6B4F; margin: 12pt 0 2pt; border-bottom: 1px solid #B8872E; padding-bottom: 2pt; }
    table.summary td { padding: 3pt 8pt; }
</style>
@endsection
@section('content')
@if(!empty($report['period']))
    <div class="{{ $ar ? 'ar' : 'en' }} muted">{{ $txt($report['period']) }}</div>
@endif
@if(!empty($report['summary']))
    <div class="box">
        <table class="summary" style="width:100%">
            @foreach(array_chunk($report['summary'], 2) as $pair)
                <tr>
                    @foreach($ar ? array_reverse($pair) : $pair as [$label, $value])
                        @if($ar)
                            <td class="num" style="width:20%"><b>{{ $txt($value) }}</b></td><td class="ar" style="width:30%">{{ $txt($label) }}</td>
                        @else
                            <td class="en" style="width:30%">{{ $label }}</td><td class="num" style="width:20%"><b>{{ $value }}</b></td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
@endif
@foreach($report['sections'] as $section)
    <div class="section-title {{ $ar ? 'ar' : 'en' }}">{{ $txt($section['title']) }}</div>
    @if(empty($section['rows']))
        <div class="muted {{ $ar ? 'ar' : 'en' }}">{{ $txt(__('reports.no_rows')) }}</div>
    @else
        <table class="grid">
            <thead><tr>
                @foreach($ar ? array_reverse($section['headings']) : $section['headings'] as $h)
                    <th class="{{ $ar ? 'ar' : 'en' }}">{{ $txt($h) }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach($section['rows'] as $row)
                    <tr>
                        @foreach($ar ? array_reverse($row) : $row as $cell)
                            <td class="{{ is_numeric(str_replace([',', '.', '%', ' '], '', (string) $cell)) ? 'num' : ($ar ? 'ar' : 'en') }}">{{ is_numeric(str_replace([',', '.', '%', ' '], '', (string) $cell)) ? $cell : $txt($cell) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach
@endsection
