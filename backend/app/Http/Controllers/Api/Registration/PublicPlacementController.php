<?php

namespace App\Http\Controllers\Api\Registration;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamAttemptResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\Exams\ExamService;
use App\Services\Exams\PlacementService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Placement test during public registration (no login). The family starts an attempt for the chosen
 * package's test and gets a one-time token; everything after that goes through the token.
 *
 * @group Public registration
 * @unauthenticated
 */
class PublicPlacementController extends Controller
{
    public function __construct(private PlacementService $placement, private ExamService $exams) {}

    public function start(Request $request): JsonResponse
    {
        abort_unless((bool) setting('registration.open', true), 403, __('exams.placement.registration_closed'));
        $request->merge(['guardian_phone' => PhoneNumber::normalize($request->input('guardian_phone')) ?? $request->input('guardian_phone')]);
        $data = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'guardian_phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
        ]);

        $exam = Exam::activePlacementFor((int) $data['package_id']);
        abort_unless($exam, 404, __('exams.placement.not_found'));

        [$attempt, $token] = $this->placement->start($exam, $data['full_name'], $data['guardian_phone'], now());

        return $this->state($attempt, 201, ['token' => $token]);
    }

    /** Resume after a reload or a closed tab: the questions and saved answers, or the result once finished. */
    public function show(string $token): JsonResponse
    {
        return $this->state($this->placement->resolve($token, now()));
    }

    public function saveAnswers(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.answer' => ['nullable'],
        ]);
        $attempt = $this->exams->saveAnswers($this->placement->resolve($token, now()), $data['answers'], now());

        return response()->json(['saved_at' => display_tz(now())->toIso8601String(), 'remaining_seconds' => $this->exams->remainingSeconds($attempt, now())]);
    }

    public function submit(string $token): JsonResponse
    {
        $attempt = $this->placement->submit($this->placement->resolve($token, now()), now());

        return response()->json(['data' => ['status' => $attempt->status->value, 'result' => $this->placement->result($attempt)]]);
    }

    private function state(ExamAttempt $attempt, int $status = 200, array $extra = []): JsonResponse
    {
        $exam = $attempt->exam;
        $meta = ['exam' => ['name' => $exam->name, 'duration_minutes' => $exam->duration_minutes, 'questions_count' => count($attempt->question_order ?? [])]];

        if ($attempt->status !== AttemptStatus::InProgress) {
            return response()->json(['data' => $extra + $meta + ['status' => $attempt->status->value, 'result' => $this->placement->result($attempt)]], $status);
        }

        $attempt->setAttribute('remaining_seconds', $this->exams->remainingSeconds($attempt, now()));
        $attempt->load('answers');
        $attempt->setRelation('questions', $this->exams->questionsForAttempt($attempt));

        return response()->json(['data' => $extra + $meta + ['status' => $attempt->status->value, 'attempt' => new ExamAttemptResource($attempt)]], $status);
    }
}
