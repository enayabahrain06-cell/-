<?php

namespace App\Http\Controllers\Api\Lottery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lottery\SaveLotteryRequest;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Lottery;
use App\Models\LotteryResult;
use App\Services\Lottery\LotteryService;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Lottery
 */
class LotteryController extends Controller
{
    public function __construct(private LotteryService $service) {}

    /** Lotteries of the viewer's track (mixed early-years lotteries are visible to both tracks). Filters: package_id, status. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lottery::class);
        $limit = Track::genderFor($request->user())?->value;

        $page = Lottery::with('package:id,name,name_ar,name_en')->withCount(['pool', 'results', 'teachers'])
            ->when($limit, fn ($q) => $q->whereIn('gender', [$limit, 'mixed']))
            ->tap(fn ($q) => \App\Support\TermScope::via($q, \App\Support\TermScope::fromRequest($request)))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id')->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Lottery $l) => $this->row($l)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(SaveLotteryRequest $request): JsonResponse
    {
        $lottery = $this->service->create($request->validated(), $request->user());

        return response()->json(['message' => __('lottery.created'), 'data' => $this->detail($lottery)], 201);
    }

    public function show(Lottery $lottery): JsonResponse
    {
        $this->authorize('view', $lottery);

        return response()->json(['data' => $this->detail($lottery)]);
    }

    public function update(SaveLotteryRequest $request, Lottery $lottery): JsonResponse
    {
        $lottery = $this->service->update($lottery, $request->validated());

        return response()->json(['message' => __('api.saved'), 'data' => $this->detail($lottery)]);
    }

    public function syncPool(Lottery $lottery): JsonResponse
    {
        $this->authorize('update', $lottery);
        $this->service->syncPool($lottery);

        return response()->json(['data' => $this->detail($lottery->fresh())]);
    }

    /** Run or re-run. Optional seed (same seed ⇒ same distribution). */
    public function run(Request $request, Lottery $lottery): JsonResponse
    {
        $this->authorize('update', $lottery);
        $data = $request->validate(['seed' => ['nullable', 'string', 'max:40']]);
        $out = $this->service->run($lottery, $data['seed'] ?? null);

        return response()->json(['message' => __('lottery.ran', ['run' => $out['run_no'], 'seed' => $out['seed']]), 'run' => $out, 'data' => $this->detail($lottery->fresh())]);
    }

    public function move(Request $request, Lottery $lottery, LotteryResult $result): JsonResponse
    {
        $this->authorize('update', $lottery);
        abort_unless($result->lottery_id === $lottery->id, 404);
        $data = $request->validate(['lottery_teacher_id' => ['required', 'integer']]);
        $this->service->move($result, (int) $data['lottery_teacher_id']);

        return response()->json(['message' => __('lottery.moved'), 'data' => $this->detail($lottery->fresh())]);
    }

    public function approve(Request $request, Lottery $lottery): JsonResponse
    {
        $this->authorize('update', $lottery);
        $out = $this->service->approve($lottery, $request->user(), $request->boolean('notify', true));

        return response()->json(['message' => __('lottery.approved', ['count' => $out['enrolled']]), 'result' => $out, 'data' => $this->detail($lottery->fresh())]);
    }

    public function cancel(Lottery $lottery): JsonResponse
    {
        $this->authorize('update', $lottery);
        $this->service->cancel($lottery);

        return response()->json(['message' => __('lottery.cancelled'), 'data' => $this->detail($lottery->fresh())]);
    }

    private function row(Lottery $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'gender' => $l->gender?->value,
            'status' => $l->status->value,
            'status_label' => $l->status->label(),
            'package' => $l->package ? ['id' => $l->package->id, 'name' => $l->package->localizedName(app()->getLocale())] : null,
            'balance_ages' => $l->balance_ages,
            'keep_siblings' => $l->keep_siblings,
            'balance_levels' => $l->balance_levels,
            'seed' => $l->seed,
            'run_count' => $l->run_count,
            'run_at' => display_tz($l->run_at)?->toIso8601String(),
            'approved_at' => display_tz($l->approved_at)?->toIso8601String(),
            'pool_count' => $l->pool_count ?? $l->pool()->count(),
            'results_count' => $l->results_count ?? $l->results()->count(),
            'teachers_count' => $l->teachers_count ?? $l->teachers()->count(),
        ];
    }

    private function detail(Lottery $l): array
    {
        $l->load(['package', 'pool.student', 'results.student']);
        $assigned = $l->results->pluck('student_id')->flip();

        return $this->row($l) + [
            'teachers' => $this->service->summary($l)->map(fn ($t) => [
                'lottery_teacher_id' => $t['lottery_teacher_id'],
                'teacher' => $t['teacher'],
                'lesson' => $t['lesson'],
                'capacity' => $t['capacity'],
                'assigned' => $t['assigned'],
                'students' => $t['students']->map(fn (LotteryResult $r) => ['result_id' => $r->id, 'student' => new StudentSummaryResource($r->student)] + ['age_at_start' => $r->student?->birth_date?->diffInYears($l->package->start_date ?? today())]),
            ]),
            'unassigned' => $l->pool->filter(fn ($p) => $p->student && ! $assigned->has($p->student_id) && $l->results->isNotEmpty())->map(fn ($p) => new StudentSummaryResource($p->student))->values(),
            'pool' => $l->pool->map(fn ($p) => $p->student ? new StudentSummaryResource($p->student) : null)->filter()->values(),
        ];
    }
}
