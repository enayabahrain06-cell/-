<?php

namespace App\Http\Controllers\Api\Lessons;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\Location;
use App\Models\LocationBooking;
use App\Services\Lessons\LocationConflictDetector;
use App\Support\WeekDays;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * @group Lessons & locations
 */
class LocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Location::class);

        $q = Location::withCount('lessons')
            ->tap(fn ($q) => \App\Support\Track::scopeLocations($q, $request->user()))
            ->when($request->filled('gender'), fn ($q) => $q->whereIn('gender', [$request->string('gender')->toString(), 'shared']))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name');

        return LocationResource::collection($request->boolean('all') ? $q->get() : $q->paginate((int) $request->integer('per_page', 25)));
    }

    /** Halls that are free at a given date and time slot. */
    public function free(Request $request, LocationConflictDetector $detector): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Location::class);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'string'],
            'end_time' => ['required', 'string'],
            'ignore_lesson_id' => ['nullable', 'integer'],
            'gender' => ['nullable', \App\Enums\Gender::rule()],
        ]);

        $start = WeekDays::time($data['start_time']);
        $end = WeekDays::time($data['end_time']);
        if ($end <= $start) {
            throw ValidationException::withMessages(['end_time' => __('lessons.end_after_start')]);
        }

        $free = Location::where('is_active', true)
            ->tap(fn ($q) => \App\Support\Track::scopeLocations($q, $request->user()))
            ->when(! empty($data['gender']), fn ($q) => $q->whereIn('gender', [$data['gender'], 'shared']))
            ->orderBy('name')->get()
            ->filter(fn (Location $l) => $detector->forDate($l->id, Carbon::parse($data['date']), $start, $end, $data['ignore_lesson_id'] ?? null) === []);

        return LocationResource::collection($free->values());
    }

    public function store(StoreLocationRequest $request): LocationResource
    {
        $location = Location::create($request->validated());

        return new LocationResource($location);
    }

    public function show(Location $location): LocationResource
    {
        $this->authorize('view', $location);

        return new LocationResource($location->loadCount('lessons'));
    }

    public function update(StoreLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location->fresh());
    }

    public function toggle(Location $location): LocationResource
    {
        $this->authorize('update', $location);
        $location->update(['is_active' => ! $location->is_active]);

        return new LocationResource($location);
    }

    public function destroy(Location $location): JsonResponse
    {
        $this->authorize('delete', $location);

        if ($location->lessons()->exists() || $location->overrides()->exists()) {
            throw ValidationException::withMessages(['location' => __('lessons.location_in_use')]);
        }

        $location->delete();

        return response()->json(['message' => __('api.deleted')]);
    }

    /** Flat occupancy list (sessions, overrides, bookings) for the booking calendar. */
    public function calendar(Request $request, Location $location): JsonResponse
    {
        $this->authorize('view', $location);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = isset($data['from']) ? Carbon::parse($data['from']) : today()->startOfWeek(Carbon::SATURDAY);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : $from->copy()->addDays(27);

        $items = [];

        LessonSession::with(['lesson:id,name,teacher_id,location_id', 'lesson.teacher:id,name'])
            ->where('location_id', $location->id)
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('session_date')->orderBy('start_time')
            ->get()
            ->each(function (LessonSession $s) use (&$items) {
                $items[] = [
                    'kind' => $s->location_id !== $s->lesson?->location_id ? 'override' : 'session',
                    'id' => $s->id,
                    'lesson_id' => $s->lesson_id,
                    'title' => $s->lesson?->name,
                    'teacher' => $s->lesson?->teacher?->name,
                    'date' => $s->session_date->toDateString(),
                    'start_time' => substr($s->start_time, 0, 5),
                    'end_time' => substr($s->end_time, 0, 5),
                    'status' => $s->status?->value,
                ];
            });

        // Overrides for dates whose session has not been materialised yet.
        $sessionDates = collect($items)->map(fn ($i) => $i['lesson_id'].'|'.$i['date'])->flip();
        LessonLocationOverride::with('lesson:id,name,start_time,end_time')
            ->where('location_id', $location->id)
            ->whereBetween('override_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->each(function (LessonLocationOverride $o) use (&$items, $sessionDates) {
                $key = $o->lesson_id.'|'.$o->override_date->toDateString();
                if ($sessionDates->has($key) || ! $o->lesson) {
                    return;
                }
                $items[] = [
                    'kind' => 'override', 'id' => $o->id, 'lesson_id' => $o->lesson_id, 'title' => $o->lesson->name, 'teacher' => null,
                    'date' => $o->override_date->toDateString(), 'start_time' => substr($o->lesson->start_time, 0, 5), 'end_time' => substr($o->lesson->end_time, 0, 5), 'status' => 'scheduled',
                ];
            });

        LocationBooking::where('location_id', $location->id)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->each(function (LocationBooking $b) use (&$items) {
                $items[] = [
                    'kind' => 'booking', 'id' => $b->id, 'lesson_id' => null, 'title' => $b->title, 'teacher' => null,
                    'date' => $b->booking_date->toDateString(), 'start_time' => substr($b->start_time, 0, 5), 'end_time' => substr($b->end_time, 0, 5), 'status' => $b->source?->value,
                ];
            });

        usort($items, fn ($a, $b) => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]);

        return response()->json([
            'location' => new LocationResource($location),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'items' => $items,
        ]);
    }
}
