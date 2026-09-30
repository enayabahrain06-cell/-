<?php

namespace App\Services\TermSetup;

use App\Models\Level;
use App\Models\Subject;
use App\Models\SubjectLesson;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * دروس المواد from Excel: subject (code or name), level (code or name, empty = every level), title, description,
 * order. preview() checks every row (unknown subject/level, missing title, duplicates in the sheet or already
 * saved) without writing; commit() re-checks and creates the valid rows.
 */
class SubjectLessonImport
{
    public const COLUMNS = ['subject', 'level', 'title', 'description', 'order'];

    private const ALIASES = [
        'المادة' => 'subject', 'المستوى' => 'level', 'العنوان' => 'title', 'الدرس' => 'title', 'عنوان الدرس' => 'title',
        'الوصف' => 'description', 'الترتيب' => 'order', 'sort' => 'order', 'subject_code' => 'subject', 'level_code' => 'level',
    ];

    public const MAX_ROWS = 1000;

    private ?Collection $subjects = null;

    private ?Collection $levels = null;

    public function __construct(private AuditLogger $audit) {}

    /** @return list<array{row: int, data: array, errors: array<string, string>}> */
    public function preview(UploadedFile $file): array
    {
        return $this->checkAll($this->read($file));
    }

    /** @param  list<array{row: int, data: array}>  $rows */
    public function commit(User $actor, array $rows): array
    {
        $checked = $this->checkAll(array_slice($rows, 0, self::MAX_ROWS));
        $valid = array_values(array_filter($checked, fn ($r) => ! $r['errors']));
        DB::transaction(function () use ($valid) {
            foreach ($valid as $r) {
                SubjectLesson::create([
                    'subject_id' => $r['data']['subject_id'], 'level_id' => $r['data']['level_id'], 'title' => $r['data']['title'],
                    'description' => $r['data']['description'], 'sort' => $r['data']['order'] ?? 0, 'is_active' => true,
                ]);
            }
        });
        if ($valid) {
            $this->audit->record('subject_lessons.imported', SubjectLesson::class, [], ['count' => count($valid)], $actor->id);
        }

        return ['created' => count($valid), 'skipped' => array_values(array_filter($checked, fn ($r) => (bool) $r['errors']))];
    }

    /** @param  list<array{row: int, data: array}>  $rows */
    private function checkAll(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $checked = $this->check((int) $r['row'], (array) $r['data']);
            $d = $checked['data'];
            if (! $checked['errors']) {
                $key = $d['subject_id'].'|'.($d['level_id'] ?? '-').'|'.mb_strtolower($d['title']);
                if (isset($seen[$key])) {
                    $checked['errors']['title'] = __('term_setup.import.duplicate_in_file', ['row' => $seen[$key]]);
                } elseif (SubjectLesson::where('subject_id', $d['subject_id'])->where('level_id', $d['level_id'])->where('title', $d['title'])->exists()) {
                    $checked['errors']['title'] = __('term_setup.import.exists');
                } else {
                    $seen[$key] = $checked['row'];
                }
            }
            $out[] = $checked;
        }

        return $out;
    }

    private function check(int $row, array $raw): array
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
        $errors = [];
        $subject = $data['subject'] ? $this->find($this->subjects ??= Subject::all(), $data['subject']) : null;
        if (! $data['subject']) {
            $errors['subject'] = __('term_setup.import.subject_required');
        } elseif (! $subject) {
            $errors['subject'] = __('term_setup.import.unknown_subject', ['value' => $data['subject']]);
        }
        $level = $data['level'] ? $this->find($this->levels ??= Level::all(), $data['level']) : null;
        if ($data['level'] && ! $level) {
            $errors['level'] = __('term_setup.import.unknown_level', ['value' => $data['level']]);
        }
        if (! $data['title']) {
            $errors['title'] = __('term_setup.import.title_required');
        } elseif (mb_strlen($data['title']) > 200) {
            $errors['title'] = __('term_setup.import.title_long');
        }
        if ($data['description'] !== null && mb_strlen($data['description']) > 2000) {
            $errors['description'] = __('term_setup.import.description_long');
        }
        if ($data['order'] !== null && (! ctype_digit((string) $data['order']) || (int) $data['order'] > 9999)) {
            $errors['order'] = __('term_setup.import.bad_order');
        }

        return ['row' => $row, 'errors' => $errors, 'data' => $data + [
            'subject_id' => $subject?->id, 'subject_name' => $subject?->name(),
            'level_id' => $level?->id, 'level_name' => $level?->name(),
            'order' => $data['order'] !== null && ctype_digit((string) $data['order']) ? (int) $data['order'] : null,
        ]];
    }

    /** By code (any case) or by Arabic / English name. */
    private function find(Collection $items, string $value): ?object
    {
        $v = mb_strtolower($value);

        return $items->first(fn ($i) => ($i->code !== null && mb_strtolower($i->code) === $v) || $i->name_ar === $value || mb_strtolower((string) $i->name_en) === $v);
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
