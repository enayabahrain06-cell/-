<?php

namespace App\Http\Controllers\Api\Archive;

use App\Exports\SheetTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\ArchiveBatch;
use App\Models\ArchiveRecord;
use App\Models\Student;
use App\Services\Archive\ArchiveImport;
use App\Services\AuditLogger;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Registration
 * @subgroup Archive
 *
 * رفع الأرشيف (archive.manage): template, preview, commit, delete a batch (its archive rows only).
 * عرض الأرشيف (archive.view): search the records, one student's history.
 */
class ArchiveController extends Controller
{
    public function __construct(private ArchiveImport $import, private AuditLogger $audit) {}

    public function template(Request $request): BinaryFileResponse
    {
        $this->authorizeManage($request);

        return Excel::download(new SheetTemplateExport('archive', ArchiveImport::COLUMNS, [
            ['960512345', '', 'حسين علي المحروس', '2012-05-14', '2024/2025', 'الفصل الأول', 'المستوى الثاني', 'صف الإمام نافع', 'القرآن الكريم', 'ناجح', '92', ''],
        ]), 'archive-template.xlsx');
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $rows = $this->import->preview($request->file('file'));

        return response()->json([
            'file_name' => $request->file('file')->getClientOriginalName(),
            'rows' => $rows,
            'valid' => count(array_filter($rows, fn ($r) => ! $r['errors'])),
            'matched' => count(array_filter($rows, fn ($r) => ! $r['errors'] && $r['match'])),
            'invalid' => count(array_filter($rows, fn ($r) => (bool) $r['errors'])),
        ]);
    }

    public function commit(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1', 'max:'.ArchiveImport::MAX_ROWS],
            'rows.*.row' => ['required', 'integer'],
            'rows.*.data' => ['required', 'array'],
        ]);
        $batch = $this->import->commit($request->user(), $data['file_name'], $data['notes'] ?? null, $data['rows']);

        return response()->json(['message' => __('archive.uploaded', ['count' => $batch->rows_count, 'matched' => $batch->matched_count]), 'data' => self::batch($batch)], 201);
    }

    public function batches(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => ArchiveBatch::with('uploader:id,name')->latest('id')->limit(200)->get()->map(fn ($b) => self::batch($b))]);
    }

    /** Removes the batch and its archive rows only; students are never touched. */
    public function destroyBatch(Request $request, ArchiveBatch $batch): JsonResponse
    {
        $this->authorizeManage($request);
        DB::transaction(function () use ($batch) {
            $this->audit->record('archive.batch_deleted', $batch, $batch->only(['file_name', 'rows_count', 'matched_count']), []);
            $batch->records()->delete();
            $batch->delete();
        });

        return response()->json(['message' => __('archive.batch_deleted')]);
    }

    /** Search: name / CPR / student number, year, level label, batch, matched student. */
    public function records(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'academic_year' => ['nullable', 'string', 'max:30'],
            'level' => ['nullable', 'string', 'max:100'],
            'student_id' => ['nullable', 'integer'],
            'archive_batch_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        $page = $this->visible(ArchiveRecord::query(), $request)
            ->with('student:id,full_name,student_no')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('cpr', 'like', "%{$s}%")->orWhere('student_no', 'like', "%{$s}%")))
            ->when($data['academic_year'] ?? null, fn ($q, $y) => $q->where('academic_year', $y))
            ->when($data['level'] ?? null, fn ($q, $l) => $q->where('level_label', 'like', "%{$l}%"))
            ->when($data['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', $id))
            ->when($data['archive_batch_id'] ?? null, fn ($q, $id) => $q->where('archive_batch_id', $id))
            ->orderByDesc('academic_year')->orderBy('full_name')->orderBy('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => self::record($r)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'years' => ArchiveRecord::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year'),
        ]);
    }

    /** One student's archive rows (profile tab الأرشيف). */
    public function student(Request $request, Student $student): JsonResponse
    {
        $this->authorizeView($request);
        abort_unless(Track::allows($request->user(), $student->gender), 403);

        return response()->json(['data' => ArchiveRecord::where('student_id', $student->id)->orderByDesc('academic_year')->orderBy('id')->get()->map(fn ($r) => self::record($r))]);
    }

    private function visible(Builder $q, Request $request): Builder
    {
        // Matched rows follow the student's gender track; unmatched rows are visible to everyone with archive.view.
        return Track::genderFor($request->user())
            ? $q->where(fn ($w) => $w->whereNull('student_id')->orWhereHas('student', fn ($s) => Track::scope($s, $request->user())))
            : $q;
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('archive.view') || $request->user()->can('archive.manage'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('archive.manage'), 403);
    }

    private static function batch(ArchiveBatch $b): array
    {
        return ['id' => $b->id, 'file_name' => $b->file_name, 'rows_count' => $b->rows_count, 'matched_count' => $b->matched_count,
            'notes' => $b->notes, 'uploaded_by' => $b->uploader?->name, 'created_at' => $b->created_at?->toIso8601String()];
    }

    private static function record(ArchiveRecord $r): array
    {
        return [
            'id' => $r->id, 'archive_batch_id' => $r->archive_batch_id,
            'student' => $r->student ? ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no] : null,
            'cpr' => $r->cpr, 'student_no' => $r->student_no, 'full_name' => $r->full_name, 'academic_year' => $r->academic_year,
            'term_label' => $r->term_label, 'level_label' => $r->level_label, 'class_label' => $r->class_label,
            'subject' => $r->subject, 'result' => $r->result, 'grade' => $r->grade, 'notes' => $r->notes,
        ];
    }
}
