@extends('pdf.base')
@section('title', pdf_ar(__('exams.pdf.roster_title', [], 'ar')).' / Score sheet')
@section('head')
<style>
    td.initial { background: #D9E8DF; color: #2E6B4F; font-weight: bold; text-align: center; }
    .scorebox { height: 24pt; }
</style>
@endsection
@section('content')
<table style="width:100%"><tr>
    <td class="ar" style="width:55%">
        <h2 class="emerald">{{ pdf_ar($exam->name) }}</h2>
        <div>{{ $exam->exam_date->format('Y-m-d') }} {{ pdf_ar(hijri_date($exam->exam_date)) }} <span class="muted">:{{ pdf_ar(__('exams.pdf.date', [], 'ar')) }}</span></div>
        <div>{{ pdf_ar($exam->lesson?->teacher?->name ?? '—') }} <span class="muted">:{{ pdf_ar(__('exams.pdf.teacher', [], 'ar')) }}</span></div>
        <div>{{ $exam->pass_mark }} <span class="muted">:{{ pdf_ar(__('exams.pdf.pass_mark', [], 'ar')) }}</span> &nbsp;&nbsp; {{ $exam->total_marks }} <span class="muted">:{{ pdf_ar(__('exams.pdf.total_marks', [], 'ar')) }}</span></div>
    </td>
    <td class="en muted" style="width:45%; vertical-align:top">
        <div>Date: {{ $exam->exam_date->format('Y-m-d') }}</div>
        <div>Total marks: {{ $exam->total_marks }} · Pass mark: {{ $exam->pass_mark }}</div>
        <div>Type: {{ $exam->type->label('en') }} · Students: {{ $students->count() }}</div>
    </td>
</tr></table>

<table class="grid">
    <thead>
        <tr>
            <th style="width:5%">{{ pdf_ar(__('exams.pdf.no', [], 'ar')) }}</th>
            <th style="width:7%"></th>
            <th style="width:15%" class="ar">{{ pdf_ar(__('exams.pdf.student_no', [], 'ar')) }}</th>
            <th class="ar">Name / {{ pdf_ar(__('exams.pdf.name', [], 'ar')) }}</th>
            <th style="width:15%" class="ar">{{ $exam->total_marks }} / {{ pdf_ar(__('exams.pdf.score', [], 'ar')) }}</th>
            <th style="width:20%" class="ar">{{ pdf_ar(__('exams.pdf.signature', [], 'ar')) }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($students as $i => $s)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                @if($photos[$s->id] ?? null)
                    <td class="center"><img src="{{ $photos[$s->id] }}" style="width:24pt;height:24pt" alt=""></td>
                @else
                    <td class="initial">{{ pdf_ar($s->initial()) }}</td>
                @endif
                <td class="center">{{ $s->student_no }}</td>
                <td class="ar">{{ pdf_ar($s->full_name) }}</td>
                <td class="scorebox"></td>
                <td class="scorebox"></td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
