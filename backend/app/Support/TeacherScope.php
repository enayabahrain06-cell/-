<?php

namespace App\Support;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which classes a teacher teaches (U3/D3): the class's responsible teacher (lessons.teacher_id), or the teacher of a
 * period of it in الجدول الدراسي — one of its own periods, or a whole-level period of its level in the class's term.
 * With a subject, only periods of that subject count (the class teacher always counts: they are responsible for it).
 *
 * Attendance goes by any subject (the teacher is there that night); evaluation by the subject evaluated.
 */
final class TeacherScope
{
    /** Subquery of lessons.id the user teaches. */
    public static function lessonIds(User $user, ?int $subjectId = null): Builder
    {
        $uid = $user->id;

        return Lesson::query()->select('lessons.id')->where(fn (Builder $w) => $w
            ->where('lessons.teacher_id', $uid)
            ->orWhereIn('lessons.id', DB::table('timetable_slots')->select('lesson_id')->whereNotNull('lesson_id')
                ->where('teacher_id', $uid)->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId)))
            ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('timetable_slots as ts')
                ->join('packages as tp', 'tp.academic_term_id', '=', 'ts.academic_term_id')
                ->whereColumn('tp.id', 'lessons.package_id')
                ->whereColumn('ts.level_id', 'lessons.level_id')
                ->whereNull('ts.lesson_id')
                ->where('ts.teacher_id', $uid)
                ->when($subjectId, fn ($s) => $s->where('ts.subject_id', $subjectId))));
    }

    public static function teaches(User $user, Lesson $lesson, ?int $subjectId = null): bool
    {
        return (int) $lesson->teacher_id === (int) $user->id
            || self::lessonIds($user, $subjectId)->whereKey($lesson->id)->exists();
    }
}
