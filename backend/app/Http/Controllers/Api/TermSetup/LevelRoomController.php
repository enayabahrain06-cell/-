<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Models\Lesson;
use App\Models\Level;
use App\Models\LevelRoom;
use App\Models\TimetableSlot;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 * @subgroup Level rooms
 *
 * غرف المستويات: each level's rooms in the selected term — the rooms assigned here, plus the halls of the level's
 * circles in that term and the rooms of its timetable periods (read-only, shown with their source).
 */
class LevelRoomController extends TermSetupBase
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $user = $request->user();

        $assigned = LevelRoom::with('location')->where('academic_term_id', $term->id)->get()->groupBy('level_id');
        $circles = Lesson::with('location')->whereNotNull('level_id')->whereNotNull('location_id')
            ->tap(fn ($q) => Track::scope($q, $user))->tap(fn ($q) => TermScope::via($q, $term->id))
            ->get(['id', 'name', 'level_id', 'location_id'])->groupBy('level_id');
        $slots = TimetableSlot::with('location')->where('academic_term_id', $term->id)->whereNotNull('location_id')
            ->get(['id', 'level_id', 'location_id'])->groupBy('level_id');

        $levels = Level::where('is_active', true)->ordered()->get()->map(function (Level $level) use ($assigned, $circles, $slots) {
            $rooms = [];
            $add = function ($location, string $source, ?string $circle = null, ?int $assignmentId = null) use (&$rooms) {
                if (! $location) {
                    return;
                }
                $r = $rooms[$location->id] ?? ['location' => ['id' => $location->id, 'name' => $location->name, 'capacity' => $location->capacity], 'level_room_id' => null, 'sources' => [], 'circles' => []];
                $r['sources'] = array_values(array_unique([...$r['sources'], $source]));
                if ($circle) {
                    $r['circles'][] = $circle;
                }
                if ($assignmentId) {
                    $r['level_room_id'] = $assignmentId;
                }
                $rooms[$location->id] = $r;
            };
            foreach ($assigned->get($level->id, collect()) as $a) {
                $add($a->location, 'assigned', null, $a->id);
            }
            foreach ($circles->get($level->id, collect()) as $c) {
                $add($c->location, 'circle', $c->name);
            }
            foreach ($slots->get($level->id, collect()) as $s) {
                $add($s->location, 'timetable');
            }

            return ['level' => ['id' => $level->id, 'name' => $level->name()], 'rooms' => array_values($rooms)];
        });

        return response()->json(['term' => self::term($term), 'data' => $levels]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (LevelRoom::where(collect($data)->only(['academic_term_id', 'level_id', 'location_id'])->all())->exists()) {
            throw ValidationException::withMessages(['location_id' => __('term_setup.errors.duplicate_room')]);
        }
        $row = LevelRoom::create($data);

        return response()->json(['message' => __('term_setup.saved'), 'data' => ['id' => $row->id]], 201);
    }

    public function destroy(Request $request, LevelRoom $levelRoom): JsonResponse
    {
        $this->authorizeManage($request);
        $levelRoom->delete();

        return response()->json(['message' => __('term_setup.deleted')]);
    }
}
