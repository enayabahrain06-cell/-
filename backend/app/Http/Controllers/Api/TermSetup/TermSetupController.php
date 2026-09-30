<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Enums\WeekDay;
use App\Models\AcademicTerm;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\LevelRoom;
use App\Models\LevelSubject;
use App\Models\Location;
use App\Models\NightSupervisor;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Term setup
 *
 * Choices for the term setup forms, and copying a term's setup into another term.
 */
class TermSetupController extends TermSetupBase
{
    /** Levels, subjects, teachers, supervisors, rooms and (the term's) circles with a level, for the selected term. */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $user = $request->user();
        $term = TermScope::single($request);
        $staff = fn (string $role) => User::role($role)->where('is_active', true)->with('teacher')->orderBy('name')->get()
            ->filter(fn (User $u) => Track::allows($user, Track::staffGender($u)))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values();

        return response()->json(['data' => [
            'term' => self::term($term),
            'levels' => Level::where('is_active', true)->ordered()->get()->map(fn (Level $l) => ['id' => $l->id, 'name' => $l->name()]),
            'subjects' => Subject::where('is_active', true)->ordered()->get()->map(fn (Subject $s) => ['id' => $s->id, 'name' => $s->name(), 'code' => $s->code]),
            'teachers' => $staff('teacher'),
            'supervisors' => $staff('supervisor'),
            'halls' => Location::where('is_active', true)->tap(fn ($q) => Track::scopeLocations($q, $user))->orderBy('name')->get(['id', 'name']),
            'circles' => Lesson::whereNotNull('level_id')->tap(fn ($q) => Track::scope($q, $user))
                ->tap(fn ($q) => TermScope::via($q, $term->id))->orderBy('name')->get(['id', 'name', 'level_id']),
            // Rooms assigned to each level this term (the timetable lists them first for that level).
            'level_rooms' => LevelRoom::where('academic_term_id', $term->id)->get(['level_id', 'location_id']),
            'weekdays' => array_map(fn (WeekDay $d) => ['value' => $d->value, 'label' => $d->label()], WeekDay::cases()),
        ]]);
    }

    /**
     * Copy setup from another term into the selected one. Rows that already exist are skipped. Timetable periods
     * tied to one circle are not copied: circles belong to their own term's packages.
     */
    public function copy(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'from_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'to_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => [Rule::in(['level_rooms', 'level_subjects', 'night_supervisors', 'timetable'])],
        ]);
        if ((int) $data['from_term_id'] === (int) $data['to_term_id']) {
            throw ValidationException::withMessages(['from_term_id' => __('term_setup.errors.same_term')]);
        }
        $from = (int) $data['from_term_id'];
        $to = (int) $data['to_term_id'];
        $parts = $data['parts'];
        $n = ['rooms' => 0, 'level_subjects' => 0, 'plan_items' => 0, 'supervisors' => 0, 'slots' => 0];

        DB::transaction(function () use ($from, $to, $parts, &$n) {
            if (in_array('level_rooms', $parts, true)) {
                foreach (LevelRoom::where('academic_term_id', $from)->get() as $r) {
                    $n['rooms'] += LevelRoom::firstOrCreate(
                        ['academic_term_id' => $to, 'level_id' => $r->level_id, 'location_id' => $r->location_id],
                        ['notes' => $r->notes],
                    )->wasRecentlyCreated ? 1 : 0;
                }
            }
            if (in_array('level_subjects', $parts, true)) {
                foreach (LevelSubject::with('planItems')->where('academic_term_id', $from)->get() as $ls) {
                    $copy = LevelSubject::firstOrCreate(
                        ['academic_term_id' => $to, 'level_id' => $ls->level_id, 'subject_id' => $ls->subject_id],
                        $ls->only(['teacher_id', 'weekly_sessions', 'notes', 'sort']),
                    );
                    if (! $copy->wasRecentlyCreated) {
                        continue; // keep what the target term already has, plan included
                    }
                    $n['level_subjects']++;
                    foreach ($ls->planItems as $item) {
                        $copy->planItems()->create($item->only(['week_no', 'subject_lesson_id', 'title', 'notes', 'sort']));
                        $n['plan_items']++;
                    }
                }
            }
            if (in_array('night_supervisors', $parts, true)) {
                foreach (NightSupervisor::where('academic_term_id', $from)->get() as $ns) {
                    $n['supervisors'] += NightSupervisor::firstOrCreate(
                        ['academic_term_id' => $to, 'weekday' => $ns->weekday->value, 'user_id' => $ns->user_id],
                        ['notes' => $ns->notes],
                    )->wasRecentlyCreated ? 1 : 0;
                }
            }
            if (in_array('timetable', $parts, true)) {
                foreach (TimetableSlot::where('academic_term_id', $from)->whereNull('lesson_id')->get() as $s) {
                    $n['slots'] += TimetableSlot::firstOrCreate(
                        ['academic_term_id' => $to, 'level_id' => $s->level_id, 'lesson_id' => null, 'weekday' => $s->weekday->value, 'start_time' => $s->start_time],
                        $s->only(['end_time', 'subject_id', 'teacher_id', 'location_id', 'notes']),
                    )->wasRecentlyCreated ? 1 : 0;
                }
            }
        });
        $audit->record('term_setup.copied', AcademicTerm::find($to), [], ['from_term_id' => $from] + $n);

        return response()->json(['message' => __('term_setup.copied', $n), 'data' => $n]);
    }
}
