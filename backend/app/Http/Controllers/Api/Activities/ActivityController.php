<?php

namespace App\Http\Controllers\Api\Activities;

use App\Models\Activity;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Services\Lessons\LocationConflictDetector;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Activities
 *
 * البرامج / الرحلات: the selected term's programs or trips (?type=program|trip), create, edit, open and close.
 */
class ActivityController extends ActivityBase
{
    public function __construct(private AuditLogger $audit, private LocationConflictDetector $rooms) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSee($request);
        $type = $request->validate(['type' => ['required', Rule::in(Activity::TYPES)]])['type'];
        $term = TermScope::single($request);
        $user = $request->user();
        $list = self::withCounts(Activity::query())->where('academic_term_id', $term->id)->where('type', $type)
            ->forTrack($user)->orderByDesc('starts_on')->orderBy('name_ar')->get();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'data' => $list->map(fn ($a) => self::activity($a)),
            'options' => [
                'levels' => Level::where('is_active', true)->ordered()->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name()]),
                'rooms' => Location::where('is_active', true)->tap(fn ($q) => Track::scopeLocations($q, $user))->orderBy('name')->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name]),
                'classes' => Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
                    ->with('level')->orderBy('name')->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level' => $l->level?->name()]),
            ],
            'can' => [
                'manage' => $user->can('activities.manage'), 'register' => $user->can('activities.register'),
                'attendance' => $user->can('activities.attendance'), 'evaluate' => $user->can('activities.evaluate'),
                'record_payment' => $user->can('payments.record'), 'money' => self::canMoney($request),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('activities.manage'), 403);
        $data = $this->validated($request);
        $this->assertRoomFree($data);
        $activity = Activity::create([...$data, 'created_by' => $request->user()->id]);
        $this->audit->record('activity.created', $activity, [], $activity->only(['type', 'name_ar', 'academic_term_id', 'price_fils', 'status']));

        return response()->json(['message' => __('activities.saved.'.$activity->type), 'data' => self::activity(self::withCounts(Activity::query())->find($activity->id))], 201);
    }

    public function update(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.manage'), 403);
        $this->reach($request, $activity);
        $data = $this->validated($request, $activity);
        $registered = $activity->registrations()->where('status', 'registered')->count();
        if (($data['seats'] ?? null) !== null && $data['seats'] < $registered) {
            throw ValidationException::withMessages(['seats' => __('activities.errors.seats_below', ['count' => $registered])]);
        }
        $this->assertRoomFree([...$activity->only(['location_id', 'starts_on', 'ends_on', 'start_time', 'end_time']), ...$data], $activity->id);
        $keys = ['name_ar', 'name_en', 'starts_on', 'ends_on', 'location_id', 'place', 'seats', 'price_fils', 'gender', 'min_age', 'max_age', 'level_id', 'has_book', 'book_price_fils', 'status'];
        $old = $activity->only($keys);
        $activity->update($data);
        $this->audit->record('activity.updated', $activity, $old, $activity->only($keys));

        return response()->json(['message' => __('activities.saved.'.$activity->type), 'data' => self::activity(self::withCounts(Activity::query())->find($activity->id))]);
    }

    public function destroy(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.manage'), 403);
        $this->reach($request, $activity);
        if ($activity->registrations()->exists()) {
            throw ValidationException::withMessages(['activity' => __('activities.errors.has_registrations')]);
        }
        $this->audit->record('activity.deleted', $activity, $activity->only(['type', 'name_ar', 'academic_term_id']), []);
        $activity->delete();

        return response()->json(['message' => __('activities.deleted.'.$activity->type)]);
    }

    private function validated(Request $request, ?Activity $activity = null): array
    {
        $req = $activity ? 'sometimes' : 'required';
        $data = $request->validate([
            'type' => [$activity ? 'prohibited' : 'required', Rule::in(Activity::TYPES)],
            'academic_term_id' => [$activity ? 'prohibited' : 'required', 'integer', 'exists:academic_terms,id'],
            'name_ar' => [$req, 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'starts_on' => [$req, 'date'],
            'ends_on' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'place' => ['nullable', 'string', 'max:300'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'price_fils' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'gender' => ['nullable', Rule::in(Activity::GENDERS)],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:100'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'has_book' => ['boolean'],
            'book_title' => ['nullable', 'string', 'max:200'],
            'book_price_fils' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'status' => ['sometimes', Rule::in(Activity::STATUSES)],
        ]);
        foreach (['price_fils', 'book_price_fils'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = 0;
            }
        }
        $starts = $data['starts_on'] ?? $activity?->starts_on?->toDateString();
        if (! empty($data['ends_on']) && $starts && Carbon::parse($data['ends_on'])->lt(Carbon::parse($starts))) {
            throw ValidationException::withMessages(['ends_on' => __('activities.errors.ends_before')]);
        }
        if (! empty($data['start_time']) && ! empty($data['end_time']) && $data['end_time'] <= $data['start_time']) {
            throw ValidationException::withMessages(['end_time' => __('activities.errors.time_order')]);
        }
        $min = array_key_exists('min_age', $data) ? $data['min_age'] : $activity?->min_age;
        $max = array_key_exists('max_age', $data) ? $data['max_age'] : $activity?->max_age;
        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages(['max_age' => __('activities.errors.age_order')]);
        }
        if (array_key_exists('has_book', $data) && ! $data['has_book']) {
            $data['book_title'] = null;
            $data['book_price_fils'] = 0;
        }

        return $data;
    }

    /** A program in a room must not clash with classes, bookings or other programs there (LocationConflictDetector). */
    private function assertRoomFree(array $d, ?int $ignore = null): void
    {
        if (empty($d['location_id']) || empty($d['start_time']) || empty($d['end_time']) || empty($d['starts_on'])) {
            return;
        }
        $from = Carbon::parse($d['starts_on']);
        $to = empty($d['ends_on']) ? $from->copy() : Carbon::parse($d['ends_on']);
        $conflicts = $this->rooms->forActivity((int) $d['location_id'], $from, $to, $d['start_time'], $d['end_time'], $ignore);
        if ($conflicts) {
            throw ValidationException::withMessages(['location_id' => __('activities.errors.room_busy', ['with' => collect($conflicts)->pluck('title')->unique()->take(3)->implode('، ')])]);
        }
    }
}
