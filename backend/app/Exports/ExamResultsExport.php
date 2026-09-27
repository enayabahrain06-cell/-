<?php

namespace App\Exports;

use App\Models\Exam;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ExamResultsExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private Exam $exam, private array $results) {}

    public function collection(): Collection
    {
        return collect($this->results['rows'])->map(fn ($r) => [
            $r['student_no'],
            $r['full_name'],
            $r['score'] ?? '',
            $this->exam->total_marks,
            $r['passed'] === null ? '' : __($r['passed'] ? 'exams.passed' : 'exams.failed'),
            $r['status'] ? __("enums.attempt_status.{$r['status']}") : __('exams.no_attempt'),
        ]);
    }

    public function headings(): array
    {
        return [__('exams.col_student_no'), __('exams.col_name'), __('exams.col_score'), __('exams.col_total'), __('exams.col_result'), __('exams.col_status')];
    }

    public function title(): string
    {
        return mb_substr($this->exam->name, 0, 30);
    }
}
