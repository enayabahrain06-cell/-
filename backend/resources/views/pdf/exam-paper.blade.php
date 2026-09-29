@extends('pdf.base')
@section('title', pdf_ar(__('exams.pdf.student_paper', [], 'ar')).' / Exam paper')
@section('content')
@php($ar = fn (string $key, array $args = []) => pdf_ar(__('exams.pdf.'.$key, $args, 'ar')))
@php($lines = fn (?string $text, int $max) => implode('<br>', pdf_ar_lines($text, $max)))
{{-- Verse-order answers: one numbered verse per line (the number sits on the right, where the line starts). --}}
@php($numbered = fn (string $text, int $max) => collect(explode("\n", $text))->map(function ($verse, $i) use ($max) {
    $lines = pdf_ar_lines($verse, $max);
    // dompdf places an inline number written before the shaped Arabic run at that line's right end (where Arabic starts).
    $lines[0] = '<span class="muted">'.($i + 1).'</span> '.($lines[0] ?? '');

    return '<div>'.implode('<br>', $lines).'</div>';
})->implode(''))
@foreach($attempts as $n => $attempt)
    @php($answers = $attempt->answers->keyBy('exam_question_id'))
    <div class="{{ $loop->last ? '' : 'page-break' }}">
        @include('pdf.partials.exam-paper-head', ['student' => $attempt->student, 'attempt' => $attempt])

        <table class="grid">
            <thead>
                <tr>
                    <th style="width:10%">{{ $ar('score') }}</th>
                    <th style="width:10%">{{ $ar('result') }}</th>
                    <th style="width:20%" class="ar">{{ $ar('correct_answer') }}</th>
                    <th style="width:20%" class="ar">{{ $ar('student_answer') }}</th>
                    <th class="ar">{{ $ar('question') }}</th>
                    <th style="width:5%">{{ $ar('no') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($exam->questions as $i => $q)
                    @php($a = $answers->get($q->id))
                    @php($given = $present->answer($q, $a?->answer))
                    @php($key = $present->correct($q))
                    <tr style="page-break-inside: avoid">
                        <td class="center">{{ $a?->score ?? 0 }} / {{ $q->marks }}</td>
                        <td class="center">
                            @if($q->type->value === 'recitation' || $a?->is_correct === null) <span class="muted">—</span>
                            @elseif($a->is_correct) <span class="ok">{{ $ar('correct') }}</span>
                            @else <span class="no">{{ $ar('wrong') }}</span>
                            @endif
                        </td>
                        @php($ordered = $q->type->value === 'order_verses')
                        <td class="ar">
                            @if($key === null) <span class="muted">{{ $ar('teacher_scored') }}</span>
                            @else {!! $ordered ? $numbered($key, 24) : $lines($key, 24) !!}
                            @endif
                        </td>
                        <td class="ar">
                            @if($q->type->value === 'recitation') <span class="muted">{{ $ar('recitation') }}</span>
                            @elseif($given === null) <span class="muted">{{ $ar('blank') }}</span>
                            @else {!! $ordered ? $numbered($given, 24) : $lines($given, 24) !!}
                            @endif
                        </td>
                        {{-- Kept under the column width: dompdf would re-wrap a longer shaped line from the wrong end. --}}
                        <td class="ar">{!! $lines($q->prompt, 28) !!}</td>
                        <td class="center">{{ $i + 1 }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="box" style="width:100%"><tr>
            <td class="en muted" style="width:40%">
                Auto: {{ $attempt->auto_score ?? 0 }} · Teacher: {{ $attempt->manual_score ?? 0 }} · Total: {{ $attempt->total_score ?? 0 }} / {{ $exam->total_marks }}
            </td>
            <td class="ar" style="width:60%">
                <strong>{{ $attempt->total_score ?? 0 }} / {{ $exam->total_marks }}</strong> <span class="muted">:{{ $ar('total') }}</span>
            </td>
        </tr></table>

        <table style="width:100%; margin-top:24pt"><tr>
            <td class="ar" style="width:50%"><div class="writein"></div><span class="muted">{{ $ar('date') }}</span></td>
            <td style="width:10%"></td>
            <td class="ar" style="width:40%"><div class="writein"></div><span class="muted">{{ $ar('teacher_signature') }}</span></td>
        </tr></table>
    </div>
@endforeach
@endsection
