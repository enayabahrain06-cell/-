{{-- Exam and student details for the exam papers. $student and $attempt are null on the blank question paper. --}}
@php($ar = fn (string $key, array $args = []) => pdf_ar(__('exams.pdf.'.$key, $args, 'ar')))
<table style="width:100%"><tr>
    <td class="en muted" style="width:40%; vertical-align:top">
        <div>{{ $exam->exam_date->format('Y-m-d') }}</div>
        <div>Total marks: {{ $exam->total_marks }} · Pass mark: {{ $exam->pass_mark }}</div>
        <div>Type: {{ $exam->type->label('en') }}</div>
        @if($attempt)
            <div style="margin-top:6pt">
                <span class="badge {{ $attempt->passed ? '' : 'fail' }}">{{ $attempt->passed ? $ar('passed') : $ar('failed') }}</span>
                <strong>{{ $attempt->total_score ?? '—' }} / {{ $exam->total_marks }}</strong>
            </div>
        @endif
    </td>
    <td class="ar" style="width:60%; vertical-align:top">
        <h2 class="emerald">{{ pdf_ar($exam->name) }}</h2>
        <div>{{ pdf_ar($exam->lesson?->name ?? $exam->package?->name ?? '—') }} <span class="muted">:{{ $ar('circle') }}</span></div>
        <div>{{ $exam->exam_date->format('Y-m-d') }} {{ pdf_ar(hijri_date($exam->exam_date)) }} <span class="muted">:{{ $ar('date') }}</span></div>
        <div>{{ $exam->pass_mark }} <span class="muted">:{{ $ar('pass_mark') }}</span> &nbsp;&nbsp; {{ $exam->total_marks }} <span class="muted">:{{ $ar('total_marks') }}</span></div>
    </td>
</tr></table>

<table class="box" style="width:100%"><tr>
    <td style="width:35%" class="ar">
        @if($student) {{ $student->student_no }} @else <div class="writein"></div> @endif
    </td>
    <td style="width:15%" class="ar muted">:{{ $ar('student_no') }}</td>
    <td style="width:35%" class="ar">
        @if($student) {{ pdf_ar($student->full_name) }} @else <div class="writein"></div> @endif
    </td>
    <td style="width:15%" class="ar muted">:{{ $ar('name') }}</td>
</tr></table>
