<?php

namespace App\Services\Grades;

use App\Models\Exam;
use App\Services\Exams\ExamService;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

/**
 * رفع درجات الامتحان: a paper exam's scores from Excel (student_no, name, score). preview() checks every row — unknown
 * student (not among the exam's students), empty, not a whole number, below zero or over the exam's total, the same
 * student twice — without writing. The screen then commits the valid rows through PUT exams/{exam}/scores
 * (ExamService::recordPaperScores), so exam_attempts stay the only place exam scores live.
 */
class ExamScoreImport
{
    public const COLUMNS = ['student_no', 'name', 'score'];

    public const MAX_ROWS = 1000;

    private const ALIASES = [
        'رقم الطالب' => 'student_no', 'الرقم' => 'student_no', 'student' => 'student_no', 'no' => 'student_no',
        'الاسم' => 'name', 'اسم الطالب' => 'name', 'full_name' => 'name',
        'الدرجة' => 'score', 'mark' => 'score', 'marks' => 'score', 'grade' => 'score',
    ];

    public function __construct(private ExamService $exams) {}

    /** @return list<array{row: int, student_no: ?string, name: ?string, student_id: ?int, score: ?int, status: string, errors: array<string, string>}> */
    public function preview(Exam $exam, UploadedFile $file): array
    {
        return $this->check($exam, $this->read($file));
    }

    /** @param  list<array{row: int, data: array}>  $rows */
    public function check(Exam $exam, array $rows): array
    {
        $students = $this->exams->eligibleStudents($exam)->keyBy(fn ($s) => mb_strtoupper(trim((string) $s->student_no)));
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $no = self::text($r['data']['student_no'] ?? null);
            $raw = $r['data']['score'] ?? null;
            $rawText = self::text($raw);
            $student = $no !== null ? $students->get(mb_strtoupper($no)) : null;
            $errors = [];
            $score = null;

            if ($no === null) {
                $errors['student_no'] = __('grades.import.student_required');
            } elseif (! $student) {
                $errors['student_no'] = __('grades.import.unknown_student', ['value' => $no]);
            } elseif (isset($seen[$student->id])) {
                $errors['student_no'] = __('grades.import.duplicate', ['row' => $seen[$student->id]]);
            }

            if ($rawText !== null) {
                $normalized = str_replace(['٫', ','], '.', strtr($rawText, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
                if (! is_numeric($normalized)) {
                    $errors['score'] = __('grades.import.not_number', ['value' => $rawText]);
                } elseif ((float) $normalized != floor((float) $normalized)) {
                    $errors['score'] = __('grades.import.not_whole');
                } elseif ((float) $normalized < 0) {
                    $errors['score'] = __('grades.import.negative');
                } elseif ((float) $normalized > $exam->total_marks) {
                    $errors['score'] = __('grades.import.over_total', ['total' => $exam->total_marks]);
                } else {
                    $score = (int) $normalized;
                }
            }

            if ($student && ! isset($seen[$student->id])) {
                $seen[$student->id] = $r['row'];
            }
            $status = $errors ? 'error' : ($rawText === null ? 'empty' : 'ok');
            $out[] = [
                'row' => (int) $r['row'], 'student_no' => $no, 'name' => $student?->full_name ?? self::text($r['data']['name'] ?? null),
                'student_id' => $student?->id, 'score' => $score, 'status' => $status, 'errors' => $errors,
            ];
        }

        return $out;
    }

    private static function text(mixed $v): ?string
    {
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }
        $v = $v === null ? '' : trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** @return list<array{row: int, data: array}> */
    private function read(UploadedFile $file): array
    {
        $sheet = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\Import {}, $file)[0] ?? [];
        if (count($sheet) < 2) {
            return [];
        }
        $headers = array_map(function ($h) {
            $h = trim((string) $h);
            $key = strtolower(str_replace([' ', '-'], '_', $h));

            return in_array($key, self::COLUMNS, true) ? $key : (self::ALIASES[$h] ?? self::ALIASES[$key] ?? null);
        }, array_shift($sheet));
        $rows = [];
        foreach ($sheet as $i => $cells) {
            if (count(array_filter($cells, fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            $data = [];
            foreach ($headers as $col => $key) {
                if ($key) {
                    $data[$key] = $cells[$col] ?? null;
                }
            }
            $rows[] = ['row' => $i + 2, 'data' => $data];
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }
}
