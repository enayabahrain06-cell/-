<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\SaveChallengeRequest;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Student;
use App\Services\Engagement\ChallengeService;
use App\Support\Quran;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Challenges (section 14): progress is measured only from existing records, never typed in.
 *
 * @group Challenges
 */
class ChallengeController extends Controller
{
    public function __construct(private ChallengeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Challenge::class);
        $page = Track::scope(Challenge::query(), $request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount(['participants', 'participants as completed_count' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('starts_at')->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Challenge $c) => $this->row($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(SaveChallengeRequest $request): JsonResponse
    {
        $c = Challenge::create($this->attributes($request) + ['status' => $request->input('status', 'draft'), 'created_by' => $request->user()->id]);

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($c->fresh())], 201);
    }

    public function show(Challenge $challenge): JsonResponse
    {
        $this->authorize('view', $challenge);

        return response()->json(['data' => $this->detail($challenge)]);
    }

    public function update(SaveChallengeRequest $request, Challenge $challenge): JsonResponse
    {
        $challenge->update($this->attributes($request) + ($request->filled('status') ? ['status' => $request->input('status')] : []));

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($challenge->fresh())]);
    }

    public function destroy(Challenge $challenge): JsonResponse
    {
        $this->authorize('delete', $challenge);
        $challenge->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    /** Staff enrolment (single or bulk); eligibility still applies. */
    public function enroll(Request $request, Challenge $challenge): JsonResponse
    {
        $this->authorize('update', $challenge);
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:500'], 'student_ids.*' => ['integer']]);
        $ok = 0;
        $failed = [];
        foreach (Student::whereIn('id', $data['student_ids'])->get() as $s) {
            try {
                $this->service->join($challenge, $s, $request->user());
                $ok++;
            } catch (ValidationException $e) {
                $failed[] = ['student_id' => $s->id, 'name' => $s->full_name, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['message' => __('api.saved'), 'data' => ['joined' => $ok, 'failed' => $failed]]);
    }

    /** Recompute everyone now (normally done on every save and nightly). */
    public function refresh(Challenge $challenge): JsonResponse
    {
        $this->authorize('update', $challenge);
        $challenge->participants()->with(['challenge', 'student'])->where('status', 'joined')->get()->each(fn ($p) => $this->service->refresh($p));

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($challenge->fresh())]);
    }

    // --- student / guardian -----------------------------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        $s = $this->ownStudent($request);
        $joined = ChallengeParticipant::with('challenge.rewardBadge')->where('student_id', $s->id)->get();
        $open = Challenge::with('rewardBadge')->where('gender', $s->gender?->value)->where('status', 'active')
            ->whereDate('ends_at', '>=', today())->whereNotIn('id', $joined->pluck('challenge_id'))->get()
            ->filter(fn ($c) => $this->service->eligibility($c, $s)['eligible'])->values();

        return response()->json(['data' => [
            'student' => ['id' => $s->id, 'full_name' => $s->full_name],
            'open' => $open->map(fn ($c) => $this->row($c)),
            'mine' => $joined->map(fn (ChallengeParticipant $p) => $this->row($p->challenge) + $this->progressRow($p)),
        ]]);
    }

    public function join(Request $request, Challenge $challenge): JsonResponse
    {
        $s = $this->ownStudent($request);
        $p = $this->service->join($challenge, $s, $request->user());

        return response()->json(['message' => __('api.saved'), 'data' => $this->row($challenge) + $this->progressRow($p)], 201);
    }

    // ---------------------------------------------------------------------------------------------

    private function ownStudent(Request $request): Student
    {
        $user = $request->user();
        $student = $user->student ?? ($request->filled('student_id') ? $user->children()->whereKey($request->integer('student_id'))->first() : null);
        abort_unless($student, 403);

        return $student;
    }

    private function attributes(SaveChallengeRequest $r): array
    {
        $v = collect($r->validated())->except('status')->all();
        $v['scope_lesson_id'] = $v['scope'] === 'circle' ? ($v['scope_lesson_id'] ?? null) : null;
        $v['scope_package_id'] = $v['scope'] === 'package' ? ($v['scope_package_id'] ?? null) : null;
        if (in_array($v['goal_type'], ['memorize_range', 'revision_range'], true)) {
            $from = (int) ($v['from_ayah'] ?? 1);
            $to = (int) ($v['to_ayah'] ?? Quran::ayahCount((int) $v['surah_number']));
            $v['from_ayah'] = $from;
            $v['to_ayah'] = $to;
            $v['goal_value'] = $to - $from + 1;
        } else {
            $v['surah_number'] = $v['from_ayah'] = $v['to_ayah'] = null;
        }
        $v['reward_points'] = (int) ($v['reward_points'] ?? 0);

        return $v;
    }

    private function row(Challenge $c): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $c->id, 'name' => $c->name($locale), 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'description' => $c->description,
            'gender' => $c->gender, 'scope' => $c->scope, 'status' => $c->status,
            'goal_type' => $c->goal_type, 'goal_value' => $c->goal_value,
            'surah_number' => $c->surah_number, 'surah_name' => $c->surah_number ? Quran::name((int) $c->surah_number, $locale) : null,
            'from_ayah' => $c->from_ayah, 'to_ayah' => $c->to_ayah, 'min_score' => $c->min_score, 'score_criterion' => $c->score_criterion,
            'starts_at' => $c->starts_at?->toDateString(), 'ends_at' => $c->ends_at?->toDateString(),
            'days_left' => $c->ends_at ? max(0, (int) today()->diffInDays($c->ends_at, false)) : null,
            'reward_points' => $c->reward_points, 'reward_badge_id' => $c->reward_badge_id,
            'reward_badge' => $c->rewardBadge?->localizedName($locale),
            'participants_count' => $c->participants_count ?? null, 'completed_count' => $c->completed_count ?? null,
        ];
    }

    private function progressRow(ChallengeParticipant $p): array
    {
        return [
            'participant_status' => $p->status, 'progress_value' => $p->progress_value, 'progress_pct' => $p->progress_pct,
            'completed_at' => $p->completed_at?->toIso8601String(), 'rewarded' => (bool) $p->rewarded_at,
        ];
    }

    private function detail(Challenge $c): array
    {
        $c->loadMissing('rewardBadge');
        $rows = $c->participants()->with('student')->orderByDesc('progress_pct')->orderBy('completed_at')->orderBy('joined_at')->get();

        return array_merge($this->row($c), [
            'scope_lesson_id' => $c->scope_lesson_id, 'scope_package_id' => $c->scope_package_id,
            'min_age' => $c->min_age, 'max_age' => $c->max_age,
            'participants_count' => $rows->count(), 'completed_count' => $rows->where('status', 'completed')->count(),
            'leaderboard' => $rows->values()->map(fn (ChallengeParticipant $p, $i) => [
                'position' => $i + 1,
                'student' => $p->student ? ['id' => $p->student->id, 'full_name' => $p->student->full_name, 'initial' => $p->student->initial(), 'photo_url' => $p->student->photoUrl('thumb')] : null,
            ] + $this->progressRow($p)),
        ]);
    }
}
