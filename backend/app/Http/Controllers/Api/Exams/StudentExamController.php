<?php

namespace App\Http\Controllers\Api\Exams;

use App\Enums\ExamStatus;
use App\Enums\MediaCollection;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\SaveAnswersRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\Exams\ExamService;
use App\Services\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Exams
 * @subgroup Student portal (me/exams)
 */
class StudentExamController extends Controller
{
    public function __construct(private ExamService $exams) {}

    /** Exams for the logged-in student (or a guardian's child via ?student_id=), grouped: upcoming / open / finished. */
    public function index(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request, allowGuardian: true);
        $lessonIds = $student->lessonStudents()->where('status', 'active')->pluck('lesson_id');
        $packageIds = Lesson::whereIn('id', $lessonIds)->pluck('package_id');

        $exams = Exam::whereIn('status', [ExamStatus::Published->value, ExamStatus::Closed->value, ExamStatus::Graded->value])
            ->where(fn ($q) => $q->whereIn('lesson_id', $lessonIds)->orWhere(fn ($p) => $p->whereNull('lesson_id')->whereIn('package_id', $packageIds)))
            ->with(['package', 'lesson'])->orderBy('opens_at')->get();

        $attempts = $student->examAttempts()->whereIn('exam_id', $exams->pluck('id'))->get()->keyBy('exam_id');
        $now = now();

        $map = fn (Exam $e) => (new ExamResource($e))->additional([])->resolve() + [
            'attempt' => ($a = $attempts->get($e->id)) ? (new ExamAttemptResource($a))->resolve() : null,
        ];

        return response()->json([
            'upcoming' => $exams->filter(fn ($e) => $now->lt($e->opens_at))->map($map)->values(),
            'open' => $exams->filter(fn ($e) => $e->isOpenAt($now) && ! ($attempts->get($e->id)?->submitted_at))->map($map)->values(),
            'finished' => $exams->filter(fn ($e) => $now->gt($e->closes_at) || $attempts->get($e->id)?->submitted_at)->map($map)->values(),
        ]);
    }

    /** Start (or resume) an online attempt. Only inside the open window and only for eligible students. */
    public function start(Request $request, Exam $exam): JsonResponse
    {
        $student = $this->resolveStudent($request, allowGuardian: true); // a guardian may sit the exam for their own child (young children have no login)
        $attempt = $this->exams->startAttempt($exam, $student, now());

        return $this->attemptPayload($attempt);
    }

    /** Resume: remaining seconds (server-side) and saved answers. */
    public function attempt(Request $request, Exam $exam): JsonResponse
    {
        $student = $this->resolveStudent($request, allowGuardian: true);
        $attempt = $exam->attempts()->where('student_id', $student->id)->firstOrFail();
        $attempt = $this->exams->refreshIfExpired($attempt, now());

        return $this->attemptPayload($attempt);
    }

    /** Autosave answers. Rejected once the server-side timer has expired or the attempt was submitted. */
    public function saveAnswers(SaveAnswersRequest $request, Exam $exam): JsonResponse
    {
        $student = $this->resolveStudent($request, allowGuardian: true); // a guardian may sit the exam for their own child (young children have no login)
        $attempt = $exam->attempts()->where('student_id', $student->id)->firstOrFail();
        $attempt = $this->exams->saveAnswers($attempt, $request->validated('answers'), now());

        return response()->json(['saved_at' => display_tz(now())->toIso8601String(), 'remaining_seconds' => $this->exams->remainingSeconds($attempt, now())]);
    }

    /** Upload the recorded recitation for a question. */
    public function uploadAudio(Request $request, Exam $exam, ExamQuestion $question, MediaService $media): JsonResponse
    {
        $student = $this->resolveStudent($request, allowGuardian: true); // a guardian may sit the exam for their own child (young children have no login)
        $attempt = $exam->attempts()->where('student_id', $student->id)->firstOrFail();
        $attempt = $this->exams->refreshIfExpired($attempt, now());

        abort_unless($question->exam_id === $exam->id && $question->type === QuestionType::Recitation, 404);
        if ($attempt->status->value !== 'in_progress') {
            throw ValidationException::withMessages(['attempt' => __('exams.attempt_closed')]);
        }

        // Browser recordings (MediaRecorder) arrive as webm/ogg blobs whose sniffed MIME varies by browser,
        // so validate by extension + size; the file is never executed or served inline.
        $request->validate(['audio' => ['required', 'file', 'extensions:webm,ogg,oga,mp3,m4a,mp4,wav,aac', 'max:20480']]);

        $answer = ExamAnswer::firstOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $question->id], ['answer' => ['recorded' => true], 'saved_at' => now()]);
        $m = $media->storeUpload($answer, MediaCollection::Recitation, $request->file('audio'));
        $answer->update(['answer' => ['recorded' => true, 'media_id' => $m->id], 'saved_at' => now()]);

        return response()->json(['answer_id' => $answer->id, 'audio_media_id' => $m->id, 'remaining_seconds' => $this->exams->remainingSeconds($attempt, now())]);
    }

    public function submit(Request $request, Exam $exam): JsonResponse
    {
        $student = $this->resolveStudent($request, allowGuardian: true); // a guardian may sit the exam for their own child (young children have no login)
        $attempt = $exam->attempts()->where('student_id', $student->id)->firstOrFail();
        $attempt = $this->exams->submit($attempt, now());

        return response()->json(['data' => new ExamAttemptResource($attempt)]);
    }

    private function attemptPayload($attempt): JsonResponse
    {
        $attempt->setAttribute('remaining_seconds', $this->exams->remainingSeconds($attempt, now()));
        $attempt->load(['answers']);
        $attempt->setRelation('questions', $this->exams->questionsForAttempt($attempt));

        return response()->json(['data' => new ExamAttemptResource($attempt)]);
    }

    private function resolveStudent(Request $request, bool $allowGuardian = false): Student
    {
        $user = $request->user();

        if ($user->student) {
            return $user->student;
        }

        if ($allowGuardian && $request->filled('student_id')) {
            $child = $user->children()->whereKey($request->integer('student_id'))->first();
            if ($child) {
                return $child;
            }
        }

        abort(403, __('exams.student_only'));
    }
}
