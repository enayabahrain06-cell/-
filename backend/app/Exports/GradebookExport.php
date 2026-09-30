<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * تنزيل الدرجات: a class's gradebook. One sheet per subject (components with full marks and weight, the weighted total
 * and the class averages); for the whole class a summary sheet first with every subject's total and the average.
 */
class GradebookExport implements Export, WithMultipleSheets
{
    /** @param  list<array{subject: string, book: array}>  $books */
    public function __construct(private string $className, private array $books, private bool $summary) {}

    public function sheets(): array
    {
        $sheets = [];
        if ($this->summary) {
            $sheets[] = $this->summarySheet();
        }
        foreach ($this->books as $b) {
            $sheets[] = $this->subjectSheet($b['subject'], $b['book']);
        }

        return $sheets ?: [new ReportSheet($this->title($this->className), [__('grades.export.student_no'), __('grades.export.name')], [], $this->rtl())];
    }

    private function subjectSheet(string $subject, array $book): ReportSheet
    {
        $headings = [__('grades.export.student_no'), __('grades.export.name')];
        foreach ($book['components'] as $c) {
            $headings[] = __('grades.export.component', ['name' => $c['name'], 'max' => self::n($c['max_marks']), 'weight' => self::n($c['weight'])]);
        }
        $headings[] = __('grades.export.total');

        $rows = [];
        foreach ($book['students'] as $s) {
            $cells = (array) $s['cells'];
            $row = [$s['student']['student_no'], $s['student']['full_name']];
            foreach ($book['components'] as $c) {
                $row[] = $cells[$c['id']]['score'] ?? '';
            }
            $row[] = $s['total'] ?? '';
            $rows[] = $row;
        }
        $avg = ['', __('grades.export.average')];
        $componentAverages = (array) $book['averages']['components'];
        foreach ($book['components'] as $c) {
            $avg[] = $componentAverages[$c['id']] ?? '';
        }
        $avg[] = $book['averages']['total'] ?? '';
        $rows[] = $avg;

        return new ReportSheet($this->title($subject), $headings, $rows, $this->rtl());
    }

    private function summarySheet(): ReportSheet
    {
        $headings = [__('grades.export.student_no'), __('grades.export.name')];
        $students = [];
        foreach ($this->books as $i => $b) {
            $headings[] = $b['subject'];
            foreach ($b['book']['students'] as $s) {
                $students[$s['student']['id']] ??= ['no' => $s['student']['student_no'], 'name' => $s['student']['full_name'], 'totals' => []];
                $students[$s['student']['id']]['totals'][$i] = $s['total'];
            }
        }
        $headings[] = __('grades.export.average');
        $rows = [];
        foreach ($students as $s) {
            $row = [$s['no'], $s['name']];
            foreach (array_keys($this->books) as $i) {
                $row[] = $s['totals'][$i] ?? '';
            }
            $given = array_filter($s['totals'], fn ($v) => $v !== null);
            $row[] = $given ? round(array_sum($given) / count($given), 2) : '';
            $rows[] = $row;
        }

        return new ReportSheet($this->title(__('grades.export.summary')), $headings, $rows, $this->rtl());
    }

    /** @var array<string, true> */
    private array $used = [];

    /** Excel sheet titles: at most 31 characters, none of : \ / ? * [ ], unique within the file. */
    private function title(string $t): string
    {
        $base = mb_substr(trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $t)) ?: 'grades', 0, 28);
        $title = $base;
        for ($i = 2; isset($this->used[mb_strtolower($title)]); $i++) {
            $title = $base.' '.$i;
        }
        $this->used[mb_strtolower($title)] = true;

        return $title;
    }

    private function rtl(): bool
    {
        return app()->getLocale() === 'ar';
    }

    private static function n(float|int|null $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
