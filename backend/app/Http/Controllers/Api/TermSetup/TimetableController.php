<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Enums\WeekDay;
use App\Models\AcademicTerm;
use App\Models\Lesson;
use App\Models\LevelSubject;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\Lessons\ClassSchedule;
use App\Services\Lessons\LocationConflictDetector;
use App\Services\Lessons\SessionSync;
use App\Support\TermScope;
use App\Support\Track;
use App\Support\WeekDays;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 * @subgroup Timetable
 *
 * الجدول الدراسي: the only source of when classes meet (U1). A period belongs to a level (every class of the level
 * in that term) or to one class (with or without a level). Saving a period re-syncs the sessions of the classes it
 * touches. A class cannot have two periods at once; a busy teacher or room saves with a warning (the room check is
 * the shared LocationConflictDetector, so bookings and one-day moves count too).
 * Filters: level_id, lesson_id, teacher_id, weekday. Periods of a class follow the viewer's gender track.
 */
class TimetableController extends TermSetupBase
{
    private const WITH = ['level', 'lesson', 'subject', 'teacher', 'location'];

    public function __construct(private ClassSchedule $schedule, private SessionSync $sessions, private LocationConflictDetector $rooms) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $user = $request->user();
        $order = array_flip(WeekDay::values());

        $rows = TimetableSlot::with(self::WITH)->where('academic_term_id', $term->id)
            ->when($request->filled('level_id'), fn ($q) => $q->where(fn ($w) => $w->where('level_id', $request->integer('level_id'))
                ->orWhereIn('lesson_id', Lesson::where('level_id', $request->integer('level_id'))->select('id'))))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('weekday'), fn ($q) => $q->where('weekday', $request->string('weekday')))
            ->where(fn ($w) => $w->whereNull('lesson_id')->orWhereHas('lesson', fn ($l) => Track::scope($l, $user)))
            ->orderBy('start_time')->get()
            ->sortBy(fn ($s) => $order[$s->weekday->value] * 10000 + (int) str_replace(':', '', substr((string) $s->start_time, 0, 5)))
            ->values();

        return response()->json(['term' => self::term($term), 'data' => $rows->map(fn ($s) => self::slot($s))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $slot = new TimetableSlot($this->validated($request));
        $warnings = $this->check($slot);
        DB::transaction(function () use ($slot) {
            $slot->save();
            $this->resync($this->schedule->classesOf($slot));
        });

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::slot($slot->load(self::WITH)), 'warnings' => $warnings], 201);
    }

    public function update(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->authorizeManage($request);
        $before = $this->schedule->classesOf($timetableSlot);
        $timetableSlot->fill($this->validated($request, $timetableSlot));
        $warnings = $this->check($timetableSlot);
        DB::transaction(function () use ($timetableSlot, $before) {
            // Edited here, it is no longer the class form's simple schedule.
            $timetableSlot->source = null;
            $timetableSlot->save();
            $this->resync($before->merge($this->schedule->classesOf($timetableSlot)));
        });

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::slot($timetableSlot->fresh()->load(self::WITH)), 'warnings' => $warnings]);
    }

    public function destroy(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->authorizeManage($request);
        $classes = $this->schedule->classesOf($timetableSlot);
        DB::transaction(function () use ($timetableSlot, $classes) {
            $timetableSlot->delete();
            $this->resync($classes);
        });

        return response()->json(['message' => __('term_setup.deleted')]);
    }

    /** @param Collection<int, Lesson> $classes */
    private function resync(Collection $classes): void
    {
        foreach ($classes->unique('id') as $lesson) {
            $lesson = $lesson->fresh();
            $this->schedule->syncCopy($lesson);
            $this->sessions->apply($lesson);
        }
    }

    private function validated(Request $request, ?TimetableSlot $slot = null): array
    {
        foreach (['start_time', 'end_time'] as $k) {
            if ($request->filled($k)) {
                $request->merge([$k => WeekDays::time($request->input($k))]);
            }
        }
        $req = $slot ? 'sometimes' : 'required';
        $data = $request->validate([
            'academic_term_id' => [$req, 'integer', 'exists:academic_terms,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'weekday' => [$req, Rule::enum(WeekDay::class)],
            'start_time' => [$req, 'date_format:H:i:s'],
            'end_time' => [$req, 'date_format:H:i:s', 'after:start_time'],
            'subject_id' => [$req, 'integer', 'exists:subjects,id'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $termId = (int) ($data['academic_term_id'] ?? $slot?->academic_term_id);
        $lessonId = array_key_exists('lesson_id', $data) ? $data['lesson_id'] : $slot?->lesson_id;
        $levelId = array_key_exists('level_id', $data) ? $data['level_id'] : $slot?->level_id;

        if ($lessonId) {
            $lesson = Lesson::with('package:id,academic_term_id')->find($lessonId);
            // A class's period follows the class's level and must be in the class's own term.
            if ($levelId && (int) $lesson->level_id !== (int) $levelId) {
                throw ValidationException::withMessages(['lesson_id' => __('term_setup.errors.circle_level')]);
            }
            if ((int) $lesson->package?->academic_term_id !== $termId) {
                throw ValidationException::withMessages(['lesson_id' => __('term_setup.errors.circle_term')]);
            }
            $data['level_id'] = $lesson->level_id;
        } elseif (! $levelId) {
            throw ValidationException::withMessages(['level_id' => __('term_setup.errors.level_or_class')]);
        }
        if (! empty($data['teacher_id']) && ! User::whereKey($data['teacher_id'])->role('teacher')->exists()) {
            throw ValidationException::withMessages(['teacher_id' => __('term_setup.errors.teacher_role')]);
        }
        // No teacher given: the level subject's default teacher for this term.
        if (! $slot && empty($data['teacher_id']) && ($data['level_id'] ?? null)) {
            $data['teacher_id'] = LevelSubject::where(['academic_term_id' => $termId, 'level_id' => $data['level_id'], 'subject_id' => $data['subject_id']])->value('teacher_id');
        }

        return $data;
    }

    /**
     * Blocks a clash for any class the period touches; returns warnings for a teacher or room booked twice.
     *
     * @return list<string>
     */
    private function check(TimetableSlot $slot): array
    {
        $weekday = $slot->weekday instanceof WeekDay ? $slot->weekday->value : (string) $slot->weekday;
        $mine = $this->schedule->classesOf($slot)->pluck('id')->all();
        $same = TimetableSlot::with(['level', 'lesson', 'subject', 'teacher'])
            ->where('academic_term_id', $slot->academic_term_id)->where('weekday', $weekday)
            ->when($slot->exists, fn ($q) => $q->whereKeyNot($slot->id))
            ->get()
            ->filter(fn (TimetableSlot $o) => WeekDays::overlaps($slot->start_time, $slot->end_time, $o->start_time, $o->end_time));
        $times = fn (TimetableSlot $o) => ['from' => substr((string) $o->start_time, 0, 5), 'to' => substr((string) $o->end_time, 0, 5)];

        // A class sits in one period at a time: its own periods and its level's whole-level periods.
        foreach ($same as $o) {
            if ($mine && array_intersect($mine, $this->schedule->classesOf($o)->pluck('id')->all())) {
                throw ValidationException::withMessages(['start_time' => __('term_setup.errors.level_clash', ['subject' => $o->subject->name()] + $times($o))]);
            }
        }
        // Two whole-level periods of the same level clash even before the level has classes.
        if (! $slot->lesson_id && ($o = $same->first(fn ($o) => ! $o->lesson_id && (int) $o->level_id === (int) $slot->level_id))) {
            throw ValidationException::withMessages(['start_time' => __('term_setup.errors.level_clash', ['subject' => $o->subject->name()] + $times($o))]);
        }

        $warnings = [];
        if ($slot->teacher_id && ($o = $same->firstWhere('teacher_id', (int) $slot->teacher_id))) {
            $warnings[] = __('term_setup.warnings.teacher', ['name' => $o->teacher?->name, 'level' => $o->lesson?->name ?? $o->level?->name()] + $times($o));
        }
        // Room: the shared clash check over the term's dates (other classes' periods, one-day moves, bookings).
        $room = $slot->location_id ?? ($slot->lesson_id ? Lesson::whereKey($slot->lesson_id)->value('location_id') : null);
        if ($room) {
            $term = AcademicTerm::find($slot->academic_term_id);
            $from = $term?->start_date ? Carbon::parse($term->start_date->toDateString())->max(today()) : today();
            $to = $term?->end_date ? Carbon::parse($term->end_date->toDateString()) : null;
            $clash = $this->rooms->forRecurring((int) $room, [$weekday], $from, $to, $slot->start_time, $slot->end_time, null, $mine, $slot->id)[0] ?? null;
            if ($clash) {
                $warnings[] = __('term_setup.warnings.room', ['name' => \App\Models\Location::whereKey($room)->value('name'), 'level' => $clash['title'],
                    'from' => substr($clash['start_time'], 0, 5), 'to' => substr($clash['end_time'], 0, 5)]);
            }
        }

        return $warnings;
    }
}
