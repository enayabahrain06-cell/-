@extends('pdf.base')
@section('title', pdf_ar('تقرير الطالب').' / Student report')
@section('content')
@php $h = $profile['header']; $p = $profile['progress']; @endphp
<table style="width:100%"><tr>
    <td class="ar" style="width:55%">
        <div><span class="muted">{{ pdf_ar('الطالب') }}:</span> <b>{{ pdf_ar($student->full_name) }}</b></div>
        <div><span class="muted">{{ pdf_ar('رقم الطالب') }}:</span> {{ $student->student_no }}</div>
        @if($h['lesson'])<div><span class="muted">{{ pdf_ar('الصف') }}:</span> {{ pdf_ar($h['lesson']['name']) }}</div>@endif
        @if($h['teacher'])<div><span class="muted">{{ pdf_ar('المعلم') }}:</span> {{ pdf_ar($h['teacher']) }}</div>@endif
        <div><span class="muted">{{ pdf_ar('ولي الأمر') }}:</span> {{ pdf_ar($student->guardian_name) }} — {{ $student->guardian_phone }}</div>
    </td>
    <td class="en" style="width:45%; vertical-align:top">
        <div><span class="muted">Student no:</span> {{ $student->student_no }}</div>
        @if($h['age'] !== null)<div><span class="muted">Age:</span> {{ $h['age'] }}</div>@endif
        <div><span class="muted">Attendance:</span> {{ $h['attendance_percent'] !== null ? $h['attendance_percent'].'%' : '—' }}</div>
        <div><span class="muted">Certificates:</span> {{ $h['certificates_count'] }}</div>
    </td>
</tr></table>

<div class="box">
    <table style="width:100%"><tr>
        <td class="center" style="width:33%"><div class="muted">{{ pdf_ar('الآيات المحفوظة') }} / Ayahs memorized</div><div style="font-size:16pt" class="emerald">{{ $p['memorized_ayahs'] }}</div></td>
        <td class="center" style="width:33%"><div class="muted">{{ pdf_ar('الأجزاء المكتملة') }} / Completed juz</div><div style="font-size:16pt" class="emerald">{{ $p['completed_juz'] }}</div></td>
        <td class="center" style="width:34%"><div class="muted">{{ pdf_ar('من القرآن') }} / Of the Quran</div><div style="font-size:16pt" class="emerald">{{ $p['quran_percent'] }}%</div></td>
    </tr></table>
    @if($p['position']['current'])
        <div class="ar" style="margin-top:6pt">{{ pdf_ar($p['position']['current']['label']) }} :{{ pdf_ar('الموضع الحالي') }}</div>
    @endif
</div>

<h2 class="ar" style="margin-top:14pt">{{ pdf_ar('الشهادات') }} / Certificates</h2>
@if($certificates->isEmpty())
    <div class="muted ar">{{ pdf_ar('لا توجد شهادات معتمدة بعد.') }}</div>
@else
<table class="grid">
    <thead><tr>
        <th>{{ pdf_ar('رقم الشهادة') }} / No</th>
        <th class="ar">{{ pdf_ar('النوع') }} / Type</th>
        <th class="ar">{{ pdf_ar('الشهادة') }} / Title</th>
        <th class="ar">{{ pdf_ar('التقدير') }} / Grade</th>
        <th>{{ pdf_ar('التاريخ') }} / Date</th>
    </tr></thead>
    <tbody>
    @foreach($certificates as $c)
        <tr>
            <td class="center">{{ $c->certificate_no }}</td>
            <td class="ar">{{ pdf_ar($c->typeLabel('ar')) }}</td>
            <td class="ar">{{ pdf_ar(trim($c->title.' '.($c->achievement ? '— '.$c->achievement : ''))) }}</td>
            <td class="ar">{{ $c->grade ? pdf_ar($c->gradeLabel('ar')) : '—' }}</td>
            <td class="center">{{ $c->issued_on?->format('Y/m/d') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
@endsection
