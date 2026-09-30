<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Enums\WeekDay;
use App\Models\Lesson;
use App\Models\LevelSubject;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\TermScope;
use App\Support\Track;
use App\Support\WeekDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 * @subgroup Timetable
 *
 * الجدول الدراسي: periods per level (or one of its circles) and weekday in the selected term.
 * A level cannot have two periods at once (a circle's period clashes with the level-wide ones and its own);
 * a teacher or room booked twice is saved with a warning, like circle hall conflicts.
 * Filters: level_id, teacher_id, weekday. Periods of a circle follow the viewer's gender track.
 */
class TimetableController extends TermSetupBase
{
    private const WITH = ['level', 'lesson', 'subject', 'teacher', 'location'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $user = $request->user();
        $order = array_flip(WeekDay::values());

        $rows = TimetableSlot::with(self::WITH)->where('academic_term_id', $term->id)
            ->when($request->filled('level_id'), fn ($q) => $q->where('level_id', $request->integer('level_id')))
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
        $slot->save();

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::slot($slot->load(self::WITH)), 'warnings' => $warnings], 201);
    }

    public function update(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->authorizeManage($request);
        $timetableSlot->fill($this->validated($request, $timetableSlot));
        $warnings = $this->check($timetableSlot);
        $timetableSlot->save();

        return response()->json(['message' => __('term_setup.saved'), 'data' => self::slot($timetableSlot->fresh()->load(self::WITH)), 'warnings' => $warnings]);
    }

    public function destroy(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->authorizeManage($request);
        $timetableSlot->delete();

        return response()->json(['message' => __('term_setup.deleted')]);
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
            'level_id' => [$req, 'integer', 'exists:levels,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'weekday' => [$req, Rule::enum(WeekDay::class)],
            'start_time' => [$req, 'date_format:H:i:s'],
            'end_time' => [$req, 'date_format:H:i:s', 'after:start_time'],
            'subject_id' => [$req, 'integer', 'exists:subjects,id'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $levelId = $data['level_id'] ?? $slot?->level_id;
        $lessonId = array_key_exists('lesson_id', $data) ? $data['lesson_id'] : $slot?->lesson_id;
        if ($lessonId && (int) Lesson::whereKey($lessonId)->value('level_id') !== (int) $levelId) {
            throw ValidationException::withMessages(['lesson_id' => __('term_setup.errors.circle_level')]);
        }
        if (! empty($data['teacher_id']) && ! User::whereKey($data['teacher_id'])->role('teacher')->exists()) {
            throw ValidationException::withMessages(['teacher_id' => __('term_setup.errors.teacher_role')]);
        }
        // No teacher given: the level subject's default teacher for this term.
        if (! $slot && empty($data['teacher_id'])) {
            $data['teacher_id'] = LevelSubject::where(['academic_term_id' => $data['academic_term_id'], 'level_id' => $levelId, 'subject_id' => $data['subject_id']])->value('teacher_id');
        }

        return $data;
    }

    /**
     * Blocks a clash inside the level; returns warnings for a teacher or room booked twice.
     *
     * @return list<string>
     */
    private function check(TimetableSlot $slot): array
    {
        $same = TimetableSlot::with(['level', 'subject', 'teacher', 'location'])
            ->where('academic_term_id', $slot->academic_term_id)
            ->where('weekday', $slot->weekday instanceof WeekDay ? $slot->weekday->value : $slot->weekday)
            ->when($slot->exists, fn ($q) => $q->whereKeyNot($slot->id))
            ->get()
            ->filter(fn (TimetableSlot $o) => WeekDays::overlaps($slot->start_time, $slot->end_time, $o->start_time, $o->end_time));
        $times = fn (TimetableSlot $o) => ['from' => substr((string) $o->start_time, 0, 5), 'to' => substr((string) $o->end_time, 0, 5)];

        // (int) on both sides: some drivers return integer columns as strings.
        $clash = $same->first(fn (TimetableSlot $o) => (int) $o->level_id === (int) $slot->level_id
            && ($o->lesson_id === null || $slot->lesson_id === null || (int) $o->lesson_id === (int) $slot->lesson_id));
        if ($clash) {
            throw ValidationException::withMessages(['start_time' => __('term_setup.errors.level_clash', ['subject' => $clash->subject->name()] + $times($clash))]);
        }

        $warnings = [];
        if ($slot->teacher_id && ($o = $same->firstWhere('teacher_id', (int) $slot->teacher_id))) {
            $warnings[] = __('term_setup.warnings.teacher', ['name' => $o->teacher?->name, 'level' => $o->level->name()] + $times($o));
        }
        if ($slot->location_id && ($o = $same->firstWhere('location_id', (int) $slot->location_id))) {
            $warnings[] = __('term_setup.warnings.room', ['name' => $o->location?->name, 'level' => $o->level->name()] + $times($o));
        }

        return $warnings;
    }
}
