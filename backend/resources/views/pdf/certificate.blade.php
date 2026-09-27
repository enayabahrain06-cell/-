@extends('pdf.base')
@section('title', pdf_ar(__('exams.pdf.certificate', [], 'ar')).' / '.__('exams.pdf.certificate', [], 'en'))
@section('head')
<style>
    .frame { border: 5px double #B8872E; padding: 16pt 30pt 14pt; margin-top: 4pt; text-align: center; }
    .ctitle { font-size: 24pt; color: #B8872E; margin: 2pt 0 8pt; }
    .line { font-size: 14pt; margin: 4pt 0; }
    .name { font-size: 24pt; color: #2E6B4F; font-weight: bold; margin: 8pt 0; }
    .small { font-size: 10pt; color: #4E5F59; margin-top: 10pt; }
    .sig { margin-top: 14pt; font-size: 12pt; }
    .c { direction: ltr; unicode-bidi: bidi-override; text-align: center; }
</style>
@endsection
@section('content')
@php $ar = $locale !== 'en'; $t = fn ($k) => __("exams.pdf.$k", [], $ar ? 'ar' : 'en'); $exam = $extra['exam'] ?? $certificate->title; @endphp
<div class="frame">
    @if($ar)
        {{-- Arabic: every visual line is one shaped run (pdf_ar); multi-part lines are emitted in reverse reading order. --}}
        <div class="ctitle c">{{ pdf_ar($t('certificate')) }}</div>
        <div class="line c">{{ pdf_ar($t('certify').' '.$authority.' '.$t('that')) }}</div>
        <div class="name c">{{ pdf_ar($student->full_name) }}</div>
        <div class="line c"><b>{{ pdf_ar($exam) }}</b> {{ pdf_ar($t('passed_exam')) }}</div>
        @if(isset($extra['score']))
            <div class="line c"><b>{{ $extra['score'] }} / {{ $extra['total'] }}</b> {{ pdf_ar($t('with_score')) }}</div>
        @endif
        <div class="small c">
            @if($hijri){{ pdf_ar($hijri) }} {{ pdf_ar($t('hijri')) }} — @endif{{ $gregorian }} {{ pdf_ar($t('issued_on')) }}
            <br>{{ $certificate->certificate_no }} :{{ pdf_ar($t('certificate_no')) }}
        </div>
        <div class="sig c">______________________<br>{{ pdf_ar($t('signature_line')) }}</div>
    @else
        <div class="ctitle en center">{{ $t('certificate') }}</div>
        <div class="line en center">{{ $authority }} {{ $t('certify') }} {{ $t('that') }}</div>
        <div class="name c">{{ pdf_ar($student->full_name) }}</div>
        <div class="line en center">{{ $t('passed_exam') }} <b>{{ pdf_ar($exam) }}</b></div>
        @if(isset($extra['score']))
            <div class="line en center">{{ $t('with_score') }} <b>{{ $extra['score'] }} / {{ $extra['total'] }}</b></div>
        @endif
        <div class="small en center">
            {{ $t('issued_on') }} {{ $gregorian }}@if($hijri) — {{ $t('hijri') }} {{ $hijri }}@endif
            <br>{{ $t('certificate_no') }}: {{ $certificate->certificate_no }}
        </div>
        <div class="sig en center">______________________<br>{{ $t('signature_line') }}</div>
    @endif
</div>
@endsection
