<?php

namespace App\Http\Controllers\Api\Exams;

use App\Enums\MediaCollection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\PaperAnswersRequest;
use App\Http\Requests\Exams\PaperScoresRequest;
use App\Http\Requests\Exams\StoreExamRequest;
use App\Http\Requests\Exams\UpdateExamRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\Exams\ExamPaperPresenter;
use App\Services\Exams\ExamService;
use App\Services\Media\MediaService;
use App\Services\Pdf\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @group Exams
 */
class ExamController extends Controller
{
    public function __construct(private ExamService $exams) {}

    /** List exams. Filters: type, status, package_id, lesson_id, from, to. Teachers see only their circles' exams. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Exam::class);
        $user = $request->user();

        $q = Exam::with(['package', 'lesson'])->withCount(['questions', 'attempts'])
            ->tap(fn ($q) => \App\Support\Track::scope($q, $user))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('exam_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('exam_date', '<=', $request->date('to')))
            ->orderByDesc('exam_date')->orderByDesc('id');

        if (! $user->can('exams.manage')) {
            $mine = Lesson::where('teacher_id', $user->id);
            $q->where(fn ($w) => $w
                ->whereIn('lesson_id', (clone $mine)->select('id'))
                ->orWhere(fn ($p) => $p->whereNull('lesson_id')->whereIn('package_id', (clone $mine)->select('package_id'))));
        }

        return ExamResource::collection($q->paginate((int) $request->integer('per_page', 25)));
    }

    public function store(StoreExamRequest $request): ExamResource
    {
        $exam = Exam::create($request->validated() + ['status' => 'draft', 'created_by' => $request->user()->id]);

        return new ExamResource($exam->load(['package', 'lesson'])->loadCount(['questions', 'attempts']));
    }

    /** Exam with its questions and quick stats. */
    public function show(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);
        $exam->load(['package', 'lesson', 'questions'])->loadCount(['questions', 'attempts']);
        $r = $this->exams->results($exam);

        return response()->json([
            'data' => new ExamResource($exam),
            'stats' => ['eligible' => $r['eligible'], 'graded' => $r['graded'], 'passed' => $r['passed'], 'pass_rate' => $r['pass_rate'], 'average' => $r['average']],
        ]);
    }

    public function update(UpdateExamRequest $request, Exam $exam): ExamResource
    {
        $exam->update($request->validated());

        return new ExamResource($exam->fresh()->load(['package', 'lesson', 'questions'])->loadCount(['questions', 'attempts']));
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->authorize('delete', $exam);
        $exam->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    public function publish(Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        return new ExamResource($this->exams->publish($exam)->load(['package', 'lesson']));
    }

    public function close(Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        return new ExamResource($this->exams->close($exam)->load(['package', 'lesson']));
    }

    /** Printable paper roster (PDF) with score and signature boxes. */
    public function rosterPdf(Exam $exam, PdfService $pdf): Response
    {
        $this->authorize('view', $exam);
        $locale = app()->getLocale();
        $exam->load(['package', 'lesson.teacher']);

        $students = $this->exams->eligibleStudents($exam);
        $photos = app(\App\Services\Media\StudentPhotoService::class);

        $bytes = $pdf->render('pdf.exam-roster', [
            'locale' => $locale,
            'exam' => $exam,
            'students' => $students,
            'photos' => $students->mapWithKeys(fn ($s) => [$s->id => $photos->printableDataUri($s)]),
            'authority' => setting($locale === 'en' ? 'authority.name_en' : 'authority.name_ar', config('ahl.authority.name_'.$locale)),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="exam-'.$exam->id.'-roster.pdf"',
        ]);
    }

    /** Paper exams: enter scores per student. */
    public function scores(PaperScoresRequest $request, Exam $exam): AnonymousResourceCollection
    {
        $attempts = $this->exams->recordPaperScores($exam, $request->validated('scores'), $request->user());

        return ExamAttemptResource::collection($attempts->load('student'));
    }

    /** Paper exams with a question paper: enter one student's answers; the system grades them. */
    public function paperAnswers(PaperAnswersRequest $request, Exam $exam, Student $student): ExamAttemptResource
    {
        $attempt = $this->exams->recordPaperAnswers($exam, $student, $request->validated('answers'), $request->user());

        return new ExamAttemptResource($attempt->load(['student', 'media', 'answers.question']));
    }

    /** Blank question paper to hand out (paper exams) or keep on file (online exams). */
    public function questionPaperPdf(Exam $exam, PdfService $pdf): Response
    {
        $this->authorize('view', $exam);
        abort_if($exam->questions()->doesntExist(), 404);
        $exam->load(['package', 'lesson.teacher', 'questions']);

        return $this->pdfResponse($pdf->render('pdf.exam-question-paper', $this->paperData($exam)), "exam-{$exam->id}-questions.pdf");
    }

    /** One student's paper: questions, the student's answers, the correct answers and the marks. */
    public function paperPdf(Exam $exam, ExamAttempt $attempt, PdfService $pdf): Response
    {
        abort_unless($attempt->exam_id === $exam->id, 404);
        // Staff only: the paper shows the correct answers.
        $this->authorize('grade', $exam);

        return $this->papers($exam, $pdf, $attempt, "exam-{$exam->id}-paper-{$attempt->student?->student_no}.pdf");
    }

    /** Every submitted or graded student's paper, one student per page. */
    public function papersPdf(Exam $exam, PdfService $pdf): Response
    {
        $this->authorize('grade', $exam);

        return $this->papers($exam, $pdf, null, "exam-{$exam->id}-papers.pdf");
    }

    private function papers(Exam $exam, PdfService $pdf, ?ExamAttempt $only, string $filename): Response
    {
        $exam->load(['package', 'lesson.teacher', 'questions']);
        $attempts = $this->exams->attemptsForPapers($exam, $only);
        abort_if($attempts->isEmpty() || $exam->questions->isEmpty(), 404);

        return $this->pdfResponse($pdf->render('pdf.exam-paper', $this->paperData($exam) + ['attempts' => $attempts]), $filename);
    }

    /** @return array<string, mixed> */
    private function paperData(Exam $exam): array
    {
        $locale = app()->getLocale();

        return [
            'locale' => $locale,
            'exam' => $exam,
            // The papers are Arabic-first like the score sheet, with English beside the headings.
            'present' => new ExamPaperPresenter('ar'),
            'authority' => setting($locale === 'en' ? 'authority.name_en' : 'authority.name_ar', config('ahl.authority.name_'.$locale)),
        ];
    }

    private function pdfResponse(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /** Upload the graded paper sheet image for one attempt. */
    public function uploadSheet(Request $request, Exam $exam, ExamAttempt $attempt, MediaService $media): ExamAttemptResource
    {
        $this->authorize('grade', $exam);
        abort_unless($attempt->exam_id === $exam->id, 404);

        $request->validate(['sheet' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192']]);
        $media->storeUpload($attempt, MediaCollection::ExamSheet, $request->file('sheet'));

        return new ExamAttemptResource($attempt->fresh()->load(['student', 'media']));
    }
}
