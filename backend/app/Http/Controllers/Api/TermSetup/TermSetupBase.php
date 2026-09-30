<?php

namespace App\Http\Controllers\Api\TermSetup;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\LevelSubject;
use App\Models\NightSupervisor;
use App\Models\PlanItem;
use App\Models\SubjectLesson;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Shared by the term setup controllers (اعدادات الفصل): permission checks and the JSON shapes.
 * term_setup.view reads (teachers see the plan and timetable); term_setup.manage edits.
 */
abstract class TermSetupBase extends Controller
{
    protected function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('term_setup.view') || $request->user()->can('term_setup.manage'), 403);
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('term_setup.manage'), 403);
    }

    protected static function term(AcademicTerm $t): array
    {
        return ['id' => $t->id, 'name' => $t->name(), 'start_date' => $t->start_date?->toDateString(), 'end_date' => $t->end_date?->toDateString(), 'is_current' => $t->is_current];
    }

    protected static function person(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }

    protected static function levelSubject(LevelSubject $ls): array
    {
        return [
            'id' => $ls->id,
            'academic_term_id' => $ls->academic_term_id,
            'level' => ['id' => $ls->level->id, 'name' => $ls->level->name()],
            'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name(), 'code' => $ls->subject->code],
            'teacher' => self::person($ls->teacher),
            'weekly_sessions' => $ls->weekly_sessions,
            'notes' => $ls->notes,
            'sort' => $ls->sort,
            'plan_items_count' => $ls->plan_items_count ?? null,
        ];
    }

    protected static function subjectLesson(SubjectLesson $l): array
    {
        return [
            'id' => $l->id,
            'subject' => ['id' => $l->subject->id, 'name' => $l->subject->name()],
            'level' => $l->level ? ['id' => $l->level->id, 'name' => $l->level->name()] : null,
            'title' => $l->title,
            'description' => $l->description,
            'sort' => $l->sort,
            'is_active' => $l->is_active,
        ];
    }

    protected static function planItem(PlanItem $p): array
    {
        return [
            'id' => $p->id,
            'level_subject_id' => $p->level_subject_id,
            'week_no' => $p->week_no,
            'subject_lesson_id' => $p->subject_lesson_id,
            'title' => $p->title,
            'display_title' => $p->displayTitle(),
            'notes' => $p->notes,
            'sort' => $p->sort,
            'target_ayahs' => $p->target_ayahs,
        ];
    }

    protected static function supervisor(NightSupervisor $n): array
    {
        return [
            'id' => $n->id,
            'weekday' => $n->weekday->value,
            'weekday_label' => $n->weekday->label(),
            'user' => $n->user ? ['id' => $n->user->id, 'name' => $n->user->name, 'phone' => $n->user->phone] : null,
            'notes' => $n->notes,
        ];
    }

    protected static function slot(TimetableSlot $s): array
    {
        return [
            'id' => $s->id,
            'academic_term_id' => $s->academic_term_id,
            'level' => $s->level ? ['id' => $s->level->id, 'name' => $s->level->name()] : null,
            'lesson' => $s->lesson ? ['id' => $s->lesson->id, 'name' => $s->lesson->name] : null,
            'source' => $s->source,
            'weekday' => $s->weekday->value,
            'weekday_label' => $s->weekday->label(),
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'subject' => ['id' => $s->subject->id, 'name' => $s->subject->name()],
            'teacher' => self::person($s->teacher),
            'location' => $s->location ? ['id' => $s->location->id, 'name' => $s->location->name] : null,
            'notes' => $s->notes,
        ];
    }
}
