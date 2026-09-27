<?php

namespace App\Services\Enrollment;

use App\Enums\Gender;
use App\Enums\MemorizationLevel;
use App\Http\Requests\Enrollment\QuickEnrollRequest;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk quick enrollment from an Excel sheet with the same columns as the form (circle_id picks the package).
 * preview() validates every row without writing; commit() re-validates and enrolls the valid rows one by one,
 * each in its own transaction, so one bad row never blocks the others.
 */
class EnrollmentImport
{
    public const COLUMNS = ['full_name', 'birth_date', 'gender', 'guardian_name', 'guardian_phone', 'student_phone', 'memorization_level', 'circle_id', 'notes'];

    /** Arabic header aliases for sheets filled in by hand. */
    private const ALIASES = [
        'الاسم' => 'full_name', 'اسم الطالب' => 'full_name', 'تاريخ الميلاد' => 'birth_date', 'الجنس' => 'gender',
        'اسم ولي الأمر' => 'guardian_name', 'هاتف ولي الأمر' => 'guardian_phone', 'هاتف الطالب' => 'student_phone',
        'مستوى الحفظ' => 'memorization_level', 'رقم الحلقة' => 'circle_id', 'الحلقة' => 'circle_id', 'ملاحظات' => 'notes',
    ];

    private const MAX_ROWS = 500;

    public function __construct(private QuickEnrollmentService $service) {}

    /** @return list<array{row: int, data: array, errors: array<string, string>, warnings: list<string>}> */
    public function preview(User $actor, UploadedFile $file): array
    {
        return array_map(fn (array $r) => $this->check($actor, $r['row'], $r['data']), $this->read($file));
    }

    /**
     * @param  list<array{row: int, data: array}>  $rows  rows as returned by preview()
     * @return array{enrolled: list<array>, skipped: list<array>}
     */
    public function commit(User $actor, array $rows, bool $includeWarnings = false): array
    {
        $enrolled = $skipped = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $r) {
            $checked = $this->check($actor, (int) $r['row'], (array) $r['data']);
            if ($checked['errors'] || ($checked['warnings'] && ! $includeWarnings)) {
                $skipped[] = $checked;

                continue;
            }
            try {
                $result = $this->service->enroll($actor, $checked['data'] + ['confirm_duplicate' => true]);
                $enrolled[] = ['row' => $checked['row'], 'student_no' => $result['student']?->student_no, 'full_name' => $result['student']?->full_name];
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = ['row' => $checked['row'], 'data' => $checked['data'], 'errors' => array_map(fn ($m) => $m[0], $e->errors()), 'warnings' => []];
            }
        }

        return ['enrolled' => $enrolled, 'skipped' => $skipped];
    }

    /** Validate one row; duplicate hits are warnings (the row can still be enrolled on request). */
    public function check(User $actor, int $row, array $raw): array
    {
        $data = $this->coerce($raw);
        $data = array_merge($data, QuickEnrollRequest::normalize($data));

        $lesson = filled($data['circle_id'] ?? null) ? Lesson::find((int) $data['circle_id']) : null;
        $data['lesson_id'] = $lesson?->id;
        $data['package_id'] = $lesson?->package_id;

        $validator = Validator::make($data, QuickEnrollRequest::fieldRules() + ['circle_id' => ['required']]);
        $errors = array_map(fn ($m) => $m[0], $validator->errors()->toArray());
        if (! $errors && ! $lesson) {
            $errors['circle_id'] = __('enrollment.errors.lesson_required');
        }

        $warnings = [];
        if (! $errors) {
            // Duplicates are warnings here, not errors; photos are added later (bulk photo import), so none is required.
            $errors = $this->service->errors($actor, array_merge($data, ['confirm_duplicate' => true]), hasPhoto: true);
            $guardian = User::where('phone', $data['guardian_phone'])->first();
            foreach ($this->service->duplicates($actor, $guardian, $data['full_name'], $data['birth_date']) as $d) {
                $warnings[] = __('enrollment.warnings.'.$d['reason'], ['name' => $d['full_name'] ?? '—', 'no' => $d['student_no'] ?? '—']);
            }
        }

        return ['row' => $row, 'data' => array_intersect_key($data, array_flip([...self::COLUMNS, 'package_id', 'lesson_id', 'locale'])), 'errors' => $errors, 'warnings' => $warnings];
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
            $rows[] = ['row' => $i + 2, 'data' => $data]; // spreadsheet row number (header is row 1)
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

        return in_array($key, self::COLUMNS, true) ? $key : (self::ALIASES[$h] ?? null);
    }

    /** Excel serial dates, Arabic gender words and memorization labels to the API's values. */
    private function coerce(array $raw): array
    {
        $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $raw);

        $birth = $data['birth_date'] ?? null;
        if (is_numeric($birth) && (float) $birth > 1000) {
            $data['birth_date'] = ExcelDate::excelToDateTimeObject((float) $birth)->format('Y-m-d');
        } elseif ($birth !== null) {
            $data['birth_date'] = (string) $birth;
        }

        $gender = mb_strtolower((string) ($data['gender'] ?? ''));
        $data['gender'] = match (true) {
            in_array($gender, ['male', 'm', 'ذكر', 'ولد', 'بنين'], true) => Gender::Male->value,
            in_array($gender, ['female', 'f', 'أنثى', 'انثى', 'بنت', 'بنات'], true) => Gender::Female->value,
            default => $data['gender'] ?? null,
        };

        $level = (string) ($data['memorization_level'] ?? '');
        foreach (MemorizationLevel::cases() as $case) {
            if ($level === $case->value || $level === $case->label('ar') || $level === $case->label('en')) {
                $data['memorization_level'] = $case->value;
            }
        }
        $data['memorization_level'] = filled($data['memorization_level'] ?? null) ? $data['memorization_level'] : MemorizationLevel::cases()[0]->value;

        foreach (['guardian_phone', 'student_phone', 'circle_id'] as $k) {
            if (isset($data[$k]) && is_float($data[$k])) {
                $data[$k] = (string) (int) $data[$k];
            }
        }

        return $data;
    }
}
