<?php

namespace App\Http\Controllers\Api\Certificates;

use App\Enums\CertificateGrade;
use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Student;
use App\Services\Certificates\CertificateService;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Certificates
 *
 * Draft → approved → revoked. Drafts come from staff, exams, completed juz, the honor board and competitions.
 */
class CertificateController extends Controller
{
    public function __construct(private CertificateService $certificates) {}

    /** Enum options for the filters and the issue form. */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);

        return response()->json(['data' => [
            'types' => CertificateType::options(),
            'statuses' => CertificateStatus::options(),
            'grades' => CertificateGrade::options(),
            'sources' => CertificateSource::options(),
            'require_approval' => (bool) setting('certificates.require_approval', true),
        ]]);
    }

    /**
     * Certificates in the user's track (teachers: their own students), newest first.
     * Filters: status, type, source, student_id, lesson_id, search (student name / number, certificate no), from, to.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        $user = $request->user();

        $q = Certificate::with(['student', 'lesson:id,name', 'issuer:id,name', 'approver:id,name'])
            ->tap(fn ($q) => Track::scopeVia($q, $user, 'student'))
            ->when(! $user->can('students.manage') && ! $user->can('certificates.approve'),
                fn ($q) => $q->whereHas('student.lessonStudents', fn ($w) => $w->where('status', 'active')->whereIn('lesson_id', $user->lessons()->select('id'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issued_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issued_on', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('certificate_no', 'like', $s)->orWhere('title', 'like', $s)
                    ->orWhereHas('student', fn ($st) => $st->where('full_name', 'like', $s)->orWhere('student_no', 'like', $s)));
            })
            ->orderByDesc('issued_on')->orderByDesc('id');

        $counts = (clone $q)->reorder()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return CertificateResource::collection($q->paginate((int) $request->integer('per_page', 25)))
            ->additional(['meta' => ['status_counts' => collect(CertificateStatus::values())->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])]])
            ->response();
    }

    /** Create drafts for one or more students (same type, achievement and grade). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            'type' => ['required', Rule::enum(CertificateType::class)],
            'achievement' => ['required', 'string', 'min:2', 'max:255'],
            'title' => ['nullable', 'string', 'max:200'],
            'grade' => ['nullable', Rule::enum(CertificateGrade::class)],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
        ]);

        $students = Student::whereIn('id', $data['student_ids'])->get();
        foreach ($students as $student) {
            $this->authorize('issueFor', [Certificate::class, $student]);
        }

        $created = DB::transaction(fn () => $students->map(fn (Student $s) => $this->certificates->createDraft($s, CertificateType::from($data['type']), $data, $request->user())));

        return response()->json([
            'message' => trans_choice('certificates.messages.created', $created->count(), ['count' => $created->count()]),
            'data' => CertificateResource::collection(Certificate::with('student')->whereIn('id', $created->pluck('id'))->get()),
        ], 201);
    }

    public function show(Certificate $certificate): CertificateResource
    {
        $this->authorize('view', $certificate);

        return new CertificateResource($certificate->load(['student', 'lesson:id,name', 'issuer:id,name', 'approver:id,name']));
    }

    /** Edit a draft (achievement, title, grade, circle, date). */
    public function update(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('update', $certificate);
        $data = $request->validate([
            'achievement' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'grade' => ['sometimes', 'nullable', Rule::enum(CertificateGrade::class)],
            'lesson_id' => ['sometimes', 'nullable', 'integer', 'exists:lessons,id'],
            'issued_on' => ['sometimes', 'date'],
        ]);

        $this->certificates->update($certificate, $data);

        return response()->json(['message' => __('certificates.messages.updated'), 'data' => new CertificateResource($certificate->fresh('student'))]);
    }

    public function destroy(Certificate $certificate): JsonResponse
    {
        $this->authorize('delete', $certificate);
        $this->certificates->delete($certificate);

        return response()->json(['message' => __('certificates.messages.deleted')]);
    }

    public function approve(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('approve', $certificate);
        $this->certificates->approve($certificate, $request->user());

        return response()->json(['message' => trans_choice('certificates.messages.approved', 1, ['count' => 1]), 'data' => new CertificateResource($certificate->fresh('student'))]);
    }

    /** Approve several drafts at once; ones the user may not approve are skipped. */
    public function approveMany(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $user = $request->user();

        $approved = Certificate::with('student')->whereIn('id', $data['ids'])->where('status', CertificateStatus::Draft->value)->get()
            ->filter(fn (Certificate $c) => $user->can('approve', $c))
            ->each(fn (Certificate $c) => $this->certificates->approve($c, $user));

        return response()->json(['message' => trans_choice('certificates.messages.approved', max(1, $approved->count()), ['count' => $approved->count()]), 'approved' => $approved->count()]);
    }

    public function revoke(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('revoke', $certificate);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->certificates->revoke($certificate, $request->user(), $data['reason']);

        return response()->json(['message' => __('certificates.messages.revoked'), 'data' => new CertificateResource($certificate->fresh('student'))]);
    }

    /** Resend the WhatsApp congratulations with the verification link. */
    public function send(Certificate $certificate): JsonResponse
    {
        $this->authorize('send', $certificate);
        $sent = $this->certificates->send($certificate);

        return response()->json(['message' => __('certificates.messages.sent'), 'sent' => $sent]);
    }

    /** Stream the PDF for a signed-in user (staff, the student, the guardian). */
    public function pdf(Certificate $certificate): Response
    {
        $this->authorize('view', $certificate);

        return $this->stream($certificate);
    }

    /**
     * Signed temporary download (link from the API, valid certificates.link_minutes).
     * `view=1` opens inline (previews); `print=1` opens inline and counts a print on approved certificates.
     *
     * @unauthenticated
     */
    public function download(Request $request, Certificate $certificate): Response
    {
        if ($request->boolean('print') && $certificate->isApproved()) {
            $certificate->increment('print_count');
        }

        return $this->stream($certificate, $request->boolean('print') || $request->boolean('view') ? 'inline' : 'attachment');
    }

    private function stream(Certificate $certificate, string $disposition = 'inline'): Response
    {
        return response($this->certificates->pdfContents($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$certificate->certificate_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
