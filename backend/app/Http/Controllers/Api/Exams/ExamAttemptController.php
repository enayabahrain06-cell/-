<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\GradeAttemptRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\Exams\ExamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Exams
 * @subgroup Grading (staff)
 */
class ExamAttemptController extends Controller
{
    public function __construct(private ExamService $exams) {}

    public function index(Request $request, Exam $exam): AnonymousResourceCollection
    {
        $this->authorize('view', $exam);

        $attempts = $exam->attempts()->with(['student', 'media'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('total_score')->orderBy('id')->get();

        return ExamAttemptResource::collection($attempts);
    }

    public function show(Exam $exam, ExamAttempt $attempt): ExamAttemptResource
    {
        abort_unless($attempt->exam_id === $exam->id, 404);
        $this->authorize('view', $attempt);

        return new ExamAttemptResource($attempt->load(['student', 'media', 'answers.question', 'answers.media']));
    }

    public function grade(GradeAttemptRequest $request, Exam $exam, ExamAttempt $attempt): ExamAttemptResource
    {
        abort_unless($attempt->exam_id === $exam->id, 404);

        $attempt = $this->exams->gradeManually($attempt, $request->validated('answers'), $request->user());

        return new ExamAttemptResource($attempt->load(['student', 'media', 'answers.question', 'answers.media']));
    }
}
