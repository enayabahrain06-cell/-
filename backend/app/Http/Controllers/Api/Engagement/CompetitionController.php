<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\SaveCompetitionRequest;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Competition;
use App\Models\CompetitionJudge;
use App\Models\CompetitionParticipant;
use App\Models\CompetitionRound;
use App\Models\CompetitionScore;
use App\Models\Student;
use App\Models\User;
use App\Services\Engagement\CompetitionService;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Competitions (section 14). Every query is limited to the viewer's gender track; judges must be staff of the
 * competition's track; results stay hidden from students and guardians until they are published.
 *
 * @group Competitions
 */
class CompetitionController extends Controller
{
    public function __construct(private CompetitionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Competition::class);
        $user = $request->user();
        $manage = $user->can('competitions.manage');

        $page = Track::scope(Competition::query(), $user)
            // Judges without manage rights only see competitions they judge.
            ->when(! $manage, fn ($q) => $q->whereHas('judges', fn ($j) => $j->where('user_id', $user->id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount(['participants as participants_count' => fn ($q) => $q->where('status', '!=', 'withdrawn'), 'rounds', 'judges'])
            ->orderByDesc('starts_at')->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Competition $c) => $this->row($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function defaults(): JsonResponse
    {
        $this->authorize('create', Competition::class);

        return response()->json(['data' => ['criteria' => CompetitionService::DEFAULT_CRITERIA]]);
    }

    public function store(SaveCompetitionRequest $request): JsonResponse
    {
        $c = DB::transaction(function () use ($request) {
            $c = Competition::create($this->attributes($request) + ['status' => 'draft', 'created_by' => $request->user()->id]);
            $this->syncChildren($c, $request);

            return $c;
        });

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($c->fresh(), $request->user())], 201);
    }

    public function show(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('view', $competition);

        return response()->json(['data' => $this->detail($competition, $request->user())]);
    }

    public function update(SaveCompetitionRequest $request, Competition $competition): JsonResponse
    {
        if ($competition->results_published_at) {
            throw ValidationException::withMessages(['competition' => __('engagement.errors.published')]);
        }
        DB::transaction(function () use ($competition, $request) {
            $competition->update($this->attributes($request));
            $this->syncChildren($competition, $request);
        });

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($competition->fresh(), $request->user())]);
    }

    public function destroy(Competition $competition): JsonResponse
    {
        $this->authorize('delete', $competition);
        $competition->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    /** draft → open (registration) → running → judging; or cancelled. Finishing happens by publishing. */
    public function status(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'open', 'running', 'judging', 'cancelled'])]]);
        if ($competition->results_published_at) {
            throw ValidationException::withMessages(['competition' => __('engagement.errors.published')]);
        }
        $competition->update(['status' => $data['status']]);

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($competition, $request->user())]);
    }

    // --- participants -----------------------------------------------------------------------------

    public function participants(Competition $competition): JsonResponse
    {
        $this->authorize('view', $competition);

        return response()->json(['data' => $competition->participants()->with('student.activeLessons')->orderBy('registered_at')->get()
            ->map(fn (CompetitionParticipant $p) => $this->participantRow($p))]);
    }

    /** Students of the track that could be registered, with the reason when they cannot. */
    public function candidates(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);
        $registered = $competition->participants()->where('status', '!=', 'withdrawn')->pluck('student_id')->flip();
        $students = Student::with('activeLessons')->where('gender', $competition->gender)->where('status', 'active')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', '%'.$request->string('search').'%')->orWhere('student_no', 'like', '%'.$request->string('search').'%')))
            ->orderBy('full_name')->limit(100)->get();

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'student' => (new StudentSummaryResource($s))->resolve(),
            'registered' => $registered->has($s->id),
        ] + $this->service->eligibility($competition, $s))]);
    }

    /** Staff registration (single or bulk). Managers may register outside the window; eligibility always applies. */
    public function register(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:500'], 'student_ids.*' => ['integer']]);
        $ok = 0;
        $failed = [];
        foreach (Student::whereIn('id', $data['student_ids'])->get() as $s) {
            try {
                $this->service->register($competition, $s, $request->user(), ignoreWindow: true);
                $ok++;
            } catch (ValidationException $e) {
                $failed[] = ['student_id' => $s->id, 'name' => $s->full_name, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['message' => __('api.saved'), 'data' => ['registered' => $ok, 'failed' => $failed]]);
    }

    public function withdraw(Competition $competition, CompetitionParticipant $participant): JsonResponse
    {
        $this->authorize('update', $competition);
        abort_unless($participant->competition_id === $competition->id, 404);
        $this->service->withdraw($participant);

        return response()->json(['message' => __('api.saved')]);
    }

    // --- judges -----------------------------------------------------------------------------------

    public function judgeCandidates(Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);
        $users = User::role(config('ahl.staff_roles'))->with('teacher')->orderBy('name')->get()
            ->filter(fn (User $u) => Track::staffGender($u)?->value === $competition->gender)->values();

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'roles' => $u->getRoleNames()])]);
    }

    public function addJudge(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'round_id' => ['nullable', 'integer', Rule::exists('competition_rounds', 'id')->where('competition_id', $competition->id)],
        ]);
        $this->service->addJudge($competition, User::findOrFail($data['user_id']), $data['round_id'] ?? null);

        return response()->json(['message' => __('api.saved'), 'data' => $this->judges($competition)]);
    }

    public function removeJudge(Competition $competition, CompetitionJudge $judge): JsonResponse
    {
        $this->authorize('update', $competition);
        abort_unless($judge->competition_id === $competition->id, 404);
        $judge->delete();

        return response()->json(['message' => __('api.deleted'), 'data' => $this->judges($competition)]);
    }

    // --- judging ----------------------------------------------------------------------------------

    /** The judging sheet for one round: participants, criteria and the current judge's saved scores. */
    public function sheet(Request $request, Competition $competition, CompetitionRound $round): JsonResponse
    {
        $this->authorize('judge', $competition);
        abort_unless($round->competition_id === $competition->id, 404);
        $user = $request->user();
        abort_unless($this->service->isJudge($competition, $user, $round), 403, __('engagement.errors.not_judge'));
        $mine = CompetitionScore::where('round_id', $round->id)->where('judge_id', $user->id)->get()->keyBy('participant_id');

        return response()->json(['data' => [
            'competition' => $this->row($competition),
            'round' => $this->roundRow($round),
            'criteria' => $round->effectiveCriteria(),
            'locked' => (bool) $competition->results_published_at,
            'participants' => $competition->participants()->with('student')->where('status', '!=', 'withdrawn')->orderBy('seed_no')->orderBy('registered_at')->get()
                ->map(fn (CompetitionParticipant $p) => [
                    'id' => $p->id,
                    'student' => ['id' => $p->student->id, 'full_name' => $p->student->full_name, 'initial' => $p->student->initial(), 'photo_url' => $p->student->photoUrl('thumb')],
                    'scores' => $mine->get($p->id)?->criteria_scores,
                    'total' => $mine->has($p->id) ? round($mine[$p->id]->total_x100 / 100, 2) : null,
                    'note' => $mine->get($p->id)?->note,
                ]),
        ]]);
    }

    public function score(Request $request, Competition $competition, CompetitionRound $round): JsonResponse
    {
        $this->authorize('judge', $competition);
        abort_unless($round->competition_id === $competition->id, 404);
        $data = $request->validate([
            'participant_id' => ['required', 'integer'],
            'scores' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $p = CompetitionParticipant::findOrFail($data['participant_id']);
        $s = $this->service->score($round, $p, $request->user(), $data['scores'], $data['note'] ?? null);

        return response()->json(['message' => __('api.saved'), 'data' => ['total' => round($s->total_x100 / 100, 2)]]);
    }

    /** Standings preview for managers (before and after publishing). */
    public function standings(Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);

        return response()->json(['data' => $this->standingsRows($competition)]);
    }

    public function publish(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('update', $competition);
        if ($competition->results_published_at) {
            throw ValidationException::withMessages(['competition' => __('engagement.errors.published')]);
        }
        $result = $this->service->publish($competition, $request->user(), $request->boolean('notify', true));

        return response()->json(['message' => __('api.saved'), 'data' => $result + ['standings' => $this->standingsRows($competition->fresh())]]);
    }

    // --- student / guardian -----------------------------------------------------------------------

    /** Open competitions the student can join, and the ones they are in (results only once published). */
    public function mine(Request $request): JsonResponse
    {
        $s = $this->ownStudent($request);
        $mine = CompetitionParticipant::with('competition')->where('student_id', $s->id)->where('status', '!=', 'withdrawn')->get();
        $open = Competition::where('gender', $s->gender?->value)->where('status', 'open')
            ->where('registration_opens_at', '<=', now())->where('registration_closes_at', '>=', now())
            ->whereNotIn('id', $mine->pluck('competition_id'))->get()
            ->filter(fn ($c) => $this->service->eligibility($c, $s)['eligible'])->values();

        return response()->json(['data' => [
            'student' => ['id' => $s->id, 'full_name' => $s->full_name],
            'open' => $open->map(fn ($c) => $this->row($c)),
            'mine' => $mine->map(fn (CompetitionParticipant $p) => $this->row($p->competition) + [
                'participant_status' => $p->competition->results_published_at ? $p->status : 'registered', // winner/finalist hidden until published
                'final_rank' => $p->competition->results_published_at ? $p->final_rank : null,
                'final_score' => $p->competition->results_published_at && $p->final_score_x100 !== null ? round($p->final_score_x100 / 100, 2) : null,
                'published' => (bool) $p->competition->results_published_at,
                'rounds' => $p->competition->rounds()->with('location:id,name')->get()->map(fn ($r) => $this->roundRow($r)),
            ]),
        ]]);
    }

    public function registerSelf(Request $request, Competition $competition): JsonResponse
    {
        $s = $this->ownStudent($request);
        $p = $this->service->register($competition, $s, $request->user());

        return response()->json(['message' => __('api.saved'), 'data' => ['id' => $p->id, 'status' => $p->status]], 201);
    }

    // ---------------------------------------------------------------------------------------------

    private function ownStudent(Request $request): Student
    {
        $user = $request->user();
        $student = $user->student ?? ($request->filled('student_id') ? $user->children()->whereKey($request->integer('student_id'))->first() : null);
        abort_unless($student, 403);

        return $student;
    }

    private function attributes(SaveCompetitionRequest $r): array
    {
        $v = $r->validated();
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        foreach (['registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at'] as $k) {
            // Values without an offset are local (Bahrain) times; stored in UTC.
            $v[$k] = Carbon::parse($v[$k], $tz)->utc();
        }
        $v['scope_lesson_id'] = $v['scope'] === 'circle' ? $v['scope_lesson_id'] : null;
        $v['scope_package_id'] = $v['scope'] === 'package' ? $v['scope_package_id'] : null;

        return collect($v)->except(['rounds', 'prizes'])->all();
    }

    private function syncChildren(Competition $c, SaveCompetitionRequest $r): void
    {
        $keep = [];
        foreach (array_values($r->validated('rounds')) as $i => $row) {
            $attrs = ['name' => $row['name'], 'round_date' => $row['round_date'], 'start_time' => $row['start_time'] ?? null, 'location_id' => $row['location_id'] ?? null, 'sort_order' => $i + 1];
            $round = ! empty($row['id']) ? $c->rounds()->whereKey($row['id'])->first() : null;
            $round ? $round->update($attrs) : ($round = $c->rounds()->create($attrs));
            $keep[] = $round->id;
        }
        // Rounds with scores are never removed silently.
        $c->rounds()->whereNotIn('id', $keep)->whereDoesntHave('scores')->delete();

        $c->prizes()->delete();
        foreach ($r->validated('prizes') ?? [] as $row) {
            $c->prizes()->create(['rank' => $row['rank'], 'title' => $row['title'], 'badge_id' => $row['badge_id'] ?? null, 'points' => $row['points'] ?? 0]);
        }
    }

    private function row(Competition $c): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $c->id, 'name' => $c->name($locale), 'name_ar' => $c->name_ar, 'name_en' => $c->name_en,
            'gender' => $c->gender, 'type' => $c->type, 'scope' => $c->scope, 'status' => $c->status,
            'min_age' => $c->min_age, 'max_age' => $c->max_age, 'max_participants' => $c->max_participants,
            'registration_opens_at' => $c->registration_opens_at?->toIso8601String(),
            'registration_closes_at' => $c->registration_closes_at?->toIso8601String(),
            'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
            'registration_open' => $c->status === 'open' && now()->between($c->registration_opens_at, $c->registration_closes_at),
            'published' => (bool) $c->results_published_at,
            'participants_count' => $c->participants_count ?? null,
            'rounds_count' => $c->rounds_count ?? null,
            'judges_count' => $c->judges_count ?? null,
        ];
    }

    private function detail(Competition $c, User $user): array
    {
        $c->load(['rounds.location:id,name', 'prizes.badge', 'scopedLesson:id,name', 'scopedPackage']);
        $locale = app()->getLocale();

        return array_merge($this->row($c), [
            'description' => $c->description,
            'scope_lesson' => $c->scopedLesson ? ['id' => $c->scopedLesson->id, 'name' => $c->scopedLesson->name] : null,
            'scope_package' => $c->scopedPackage ? ['id' => $c->scopedPackage->id, 'name' => $c->scopedPackage->name] : null,
            'scope_lesson_id' => $c->scope_lesson_id, 'scope_package_id' => $c->scope_package_id,
            'criteria' => $c->criteria ?: CompetitionService::DEFAULT_CRITERIA,
            'tie_break' => $c->tie_break,
            'rounds' => $c->rounds->map(fn ($r) => $this->roundRow($r)),
            'prizes' => $c->prizes->sortBy('rank')->values()->map(fn ($p) => ['id' => $p->id, 'rank' => $p->rank, 'title' => $p->title, 'badge_id' => $p->badge_id, 'badge' => $p->badge?->localizedName($locale), 'points' => $p->points]),
            'judges' => $this->judges($c),
            'participants_count' => $c->participants()->where('status', '!=', 'withdrawn')->count(),
            'can' => [
                'manage' => $user->can('update', $c),
                'judge' => $user->can('judge', $c) && $this->service->isJudge($c, $user),
            ],
        ]);
    }

    private function roundRow(CompetitionRound $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'round_date' => $r->round_date?->toDateString(),
            'start_time' => $r->start_time ? substr($r->start_time, 0, 5) : null,
            'location_id' => $r->location_id, 'location' => $r->relationLoaded('location') ? $r->location?->name : null,
            'sort_order' => $r->sort_order, 'status' => $r->status,
        ];
    }

    private function judges(Competition $c): array
    {
        return $c->judges()->with(['user:id,name'])->get()
            ->map(fn (CompetitionJudge $j) => ['id' => $j->id, 'user_id' => $j->user_id, 'name' => $j->user?->name, 'round_id' => $j->round_id])->all();
    }

    private function participantRow(CompetitionParticipant $p): array
    {
        return [
            'id' => $p->id, 'status' => $p->status, 'registered_at' => $p->registered_at?->toIso8601String(),
            'final_rank' => $p->final_rank, 'final_score' => $p->final_score_x100 === null ? null : round($p->final_score_x100 / 100, 2),
            'student' => $p->student ? (new StudentSummaryResource($p->student))->resolve() : null,
        ];
    }

    private function standingsRows(Competition $c): array
    {
        return $this->service->standings($c)->map(fn ($r) => [
            'rank' => $r['final_x100'] === null ? null : $r['rank'],
            'participant_id' => $r['participant']->id,
            'student' => ['id' => $r['participant']->student?->id, 'full_name' => $r['participant']->student?->full_name, 'initial' => $r['participant']->student?->initial()],
            'rounds' => collect($r['rounds'])->map(fn ($v) => round($v / 100, 2)),
            'final' => $r['final_x100'] === null ? null : round($r['final_x100'] / 100, 2),
            'judged' => CompetitionScore::where('participant_id', $r['participant']->id)->count(),
        ])->values()->all();
    }
}
