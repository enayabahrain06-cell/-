@extends('pdf.base')
@section('title', pdf_ar(__('exams.pdf.question_paper', [], 'ar')).' / Question paper')
@section('content')
@php($ar = fn (string $key, array $args = []) => pdf_ar(__('exams.pdf.'.$key, $args, 'ar')))
@php($lines = fn (?string $text, int $max = 70) => implode('<br>', pdf_ar_lines($text, $max)))
@include('pdf.partials.exam-paper-head', ['student' => null, 'attempt' => null])

@foreach($exam->questions as $i => $q)
    <table class="question" style="width:100%"><tr>
        <td class="muted center" style="width:14%; vertical-align:top">{{ $ar('marks_n', ['n' => $q->marks]) }}</td>
        <td class="ar" style="vertical-align:top">
            <div>{!! $lines($q->prompt) !!}</div>

            @switch($q->type->value)
                @case('mcq')
                    <table style="width:100%; margin-top:4pt">
                        @foreach($present->options($q) as $o)
                            <tr><td class="ar">{!! $lines($o['text'], 60) !!}</td><td style="width:14pt; text-align:center"><span class="choice"></span></td></tr>
                        @endforeach
                    </table>
                    @break
                @case('true_false')
                    <table style="margin-top:4pt; margin-left:auto"><tr>
                        <td class="ar">{{ $ar('false') }}</td><td style="width:14pt"><span class="choice"></span></td>
                        <td style="width:20pt"></td>
                        <td class="ar">{{ $ar('true') }}</td><td style="width:14pt"><span class="choice"></span></td>
                    </tr></table>
                    @break
                @case('complete_verse')
                    <div class="writein"></div><div class="writein"></div>
                    @break
                @case('order_verses')
                    <div class="muted">{{ $ar('order_hint') }}</div>
                    <table style="width:100%; margin-top:2pt">
                        @foreach($present->options($q) as $o)
                            <tr><td class="ar">{!! $lines($o['text'], 60) !!}</td><td style="width:30pt"><div style="border:1px solid #1B2B28; height:14pt; width:24pt"></div></td></tr>
                        @endforeach
                    </table>
                    @break
                @case('recitation')
                    <div class="muted">{{ $ar('recitation_hint') }}</div>
                    @break
            @endswitch
        </td>
        <td class="center emerald" style="width:6%; vertical-align:top"><strong>{{ $i + 1 }}</strong></td>
    </tr></table>
@endforeach
@endsection
