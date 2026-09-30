<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Night;
use App\Models\NightSupervisor;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TermScope;
use App\Support\Track;
use App\Support\WeekDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Master data
 * @subgroup Nights and supervisors
 *
 * الليالي (the weekdays the centre runs) and المشرفين (supervisor accounts with their nights in the selected term).
 */
class NightController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.view') || $request->user()->can('nights.manage'), 403);

        return response()->json(['data' => Night::orderBy('sort')->get()->map(fn (Night $n) => $this->present($n))]);
    }

    public function update(Request $request, Night $night, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('nights.manage'), 403);
        foreach (['start_time', 'end_time'] as $k) {
            if ($request->filled($k)) {
                $request->merge([$k => WeekDays::time($request->input($k))]);
            }
        }
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i:s', 'after:start_time'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $old = $night->only(['is_active', 'start_time', 'end_time']);
        $night->update($data);
        $audit->record('night.updated', $night, $old, $night->only(array_keys($old)));

        return response()->json(['message' => __('nights.saved'), 'data' => $this->present($night)]);
    }

    /** Supervisor accounts (the viewer's track) and the nights each covers in the selected (or current) term. */
    public function supervisors(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('users.view') || $user->can('term_setup.view') || $user->can('term_setup.manage'), 403);
        $termId = TermScope::fromRequest($request);
        $termId = is_int($termId) ? $termId : TermScope::defaultId();
        $nights = $termId ? NightSupervisor::where('academic_term_id', $termId)->get()->groupBy('user_id') : collect();
        $order = array_flip(\App\Enums\WeekDay::values());

        $rows = User::role('supervisor')->with('teacher')->orderBy('name')->get()
            ->filter(fn (User $u) => Track::allows($user, Track::staffGender($u)))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'track' => $u->track instanceof \BackedEnum ? $u->track->value : $u->track,
                'is_active' => (bool) $u->is_active,
                'nights' => $nights->get($u->id, collect())->map(fn ($n) => $n->weekday->value)
                    ->sortBy(fn ($d) => $order[$d])->values(),
            ])->values();

        return response()->json(['data' => $rows, 'term_id' => $termId]);
    }

    private function present(Night $n): array
    {
        return [
            'id' => $n->id,
            'weekday' => $n->weekday->value,
            'label' => $n->weekday->label(),
            'is_active' => $n->is_active,
            'start_time' => $n->start_time ? substr((string) $n->start_time, 0, 5) : null,
            'end_time' => $n->end_time ? substr((string) $n->end_time, 0, 5) : null,
            'notes' => $n->notes,
        ];
    }
}
