<?php

namespace App\Services\Archive;

use App\Models\ArchiveBatch;
use App\Models\ArchiveRecord;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * رفع الأرشيف: previous years' records from an Excel sheet. preview() validates and matches every row without writing;
 * commit() re-checks the rows and stores the valid ones as one batch. Students are matched by CPR, then student
 * number, then exact full name (with the birth date when the sheet gives one; without it, only a unique name).
 */
class ArchiveImport
{
    public const COLUMNS = ['cpr', 'student_no', 'full_name', 'birth_date', 'academic_year', 'term_label', 'level_label', 'class_label', 'subject', 'result', 'grade', 'notes'];

    private const ALIASES = [
        'الرقم الشخصي' => 'cpr', 'رقم الطالب' => 'student_no', 'الاسم' => 'full_name', 'اسم الطالب' => 'full_name',
        'تاريخ الميلاد' => 'birth_date', 'السنة الدراسية' => 'academic_year', 'العام الدراسي' => 'academic_year', 'السنة' => 'academic_year',
        'الفصل' => 'term_label', 'الفصل الدراسي' => 'term_label', 'المستوى' => 'level_label', 'الصف' => 'class_label',
        'المادة' => 'subject', 'النتيجة' => 'result', 'الدرجة' => 'grade', 'التقدير' => 'grade', 'ملاحظات' => 'notes',
        'term' => 'term_label', 'level' => 'level_label', 'class' => 'class_label', 'year' => 'academic_year', 'name' => 'full_name',
    ];

    public const MAX_ROWS = 2000;

    public function __construct(private AuditLogger $audit) {}

    /** @return list<array{row: int, data: array, errors: array<string, string>, match: ?array}> */
    public function preview(UploadedFile $file): array
    {
        return array_map(fn (array $r) => $this->check($r['row'], $r['data']), $this->read($file));
    }

    /** @param  list<array{row: int, data: array}>  $rows */
    public function commit(User $actor, string $fileName, ?string $notes, array $rows): ArchiveBatch
    {
        $checked = array_map(fn ($r) => $this->check((int) $r['row'], (array) $r['data']), array_slice($rows, 0, self::MAX_ROWS));
        $valid = array_values(array_filter($checked, fn ($r) => ! $r['errors']));

        return DB::transaction(function () use ($actor, $fileName, $notes, $valid) {
            $batch = ArchiveBatch::create(['file_name' => $fileName, 'notes' => $notes, 'uploaded_by' => $actor->id,
                'rows_count' => count($valid), 'matched_count' => count(array_filter($valid, fn ($r) => $r['match'] !== null))]);
            foreach (array_chunk($valid, 200) as $chunk) {
                ArchiveRecord::insert(array_map(fn ($r) => array_intersect_key($r['data'], array_flip(array_diff(self::COLUMNS, ['birth_date']))) + [
                    'archive_batch_id' => $batch->id, 'student_id' => $r['match']['id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            $this->audit->record('archive.uploaded', $batch, [], $batch->only(['file_name', 'rows_count', 'matched_count']), $actor->id);

            return $batch;
        });
    }

    public function check(int $row, array $raw): array
    {
        $data = $this->coerce($raw);
        $v = Validator::make($data, [
            'cpr' => ['nullable', 'digits:9'],
            'student_no' => ['nullable', 'string', 'max:30'],
            'full_name' => ['required', 'string', 'max:200'],
            'birth_date' => ['nullable', 'date'],
            'academic_year' => ['required', 'string', 'max:30'],
            'term_label' => ['nullable', 'string', 'max:100'],
            'level_label' => ['nullable', 'string', 'max:100'],
            'class_label' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', 'string', 'max:100'],
            'result' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], __('archive.columns'));
        $errors = array_map(fn ($m) => $m[0], $v->errors()->toArray());

        return ['row' => $row, 'data' => $data, 'errors' => $errors, 'match' => $errors ? null : $this->match($data)];
    }

    /** @return ?array{id: int, full_name: string, student_no: string, by: string} */
    public function match(array $data): ?array
    {
        $hit = fn (?Student $s, string $by) => $s ? ['id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'by' => $by] : null;
        if (! empty($data['cpr']) && ($s = Student::where('cpr', $data['cpr'])->first())) {
            return $hit($s, 'cpr');
        }
        if (! empty($data['student_no']) && ($s = Student::where('student_no', $data['student_no'])->first())) {
            return $hit($s, 'student_no');
        }
        $byName = Student::where('full_name', $data['full_name'])
            ->when(! empty($data['birth_date']), fn ($q) => $q->whereDate('birth_date', $data['birth_date']))
            ->limit(2)->get();

        return $byName->count() === 1 ? $hit($byName->first(), 'name') : null;
    }

    /** @return list<array{row: int, data: array}> */
    private function read(UploadedFile $file): array
    {
        $sheet = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\Import {}, $file)[0] ?? [];
        if (count($sheet) < 2) {
            return [];
        }
        $headers = array_map(fn ($h) => $this->header((string) $h), array_shift($sheet));
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

    private function header(string $h): ?string
    {
        $h = trim($h);
        $key = strtolower(str_replace([' ', '-'], '_', $h));

        return in_array($key, self::COLUMNS, true) ? $key : (self::ALIASES[$h] ?? self::ALIASES[$key] ?? null);
    }

    /** Trim text, numbers to text (CPR, year), Excel serial dates to Y-m-d. */
    private function coerce(array $raw): array
    {
        $data = [];
        foreach (self::COLUMNS as $k) {
            $v = $raw[$k] ?? null;
            if (is_float($v) && floor($v) === $v) {
                $v = (int) $v;
            }
            $v = $v === null ? null : trim((string) $v);
            $data[$k] = $v === '' ? null : $v;
        }
        if ($data['birth_date'] !== null && is_numeric($data['birth_date']) && (float) $data['birth_date'] > 1000) {
            $data['birth_date'] = ExcelDate::excelToDateTimeObject((float) $data['birth_date'])->format('Y-m-d');
        }
        if ($data['cpr'] !== null) {
            $data['cpr'] = preg_replace('/\D/', '', strtr($data['cpr'], ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])) ?: $data['cpr'];
        }

        return $data;
    }
}
