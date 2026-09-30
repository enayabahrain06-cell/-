<?php

namespace App\Services\Notes;

use App\Enums\LessonStudentStatus;
use App\Models\AcademicTerm;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\Note;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who reaches what in the notes screens (U9). Managers (lessons.manage) reach every class, level and level subject
 * of the term within their gender track. Teachers reach the classes they teach (TeacherScope), the levels of those
 * classes, and the level subjects they teach (default teacher of the row, or a period of that subject in a class of
 * the level). General notes belong to the whole term.
 */
final class NoteReach
{
    public static function manager(User $user): bool
    {
        return $user->can('lessons.manage');
    }

    /** The term's classes this user reaches. */
    public static function lessons(User $user, AcademicTerm $term): Builder
    {
        return Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! self::manager($user), fn ($q) => $q->whereIn('lessons.id', TeacherScope::lessonIds($user)));
    }

    /** @return Collection<int, Level> */
    public static function levels(User $user, AcademicTerm $term): Collection
    {
        if (self::manager($user)) {
            return Level::query()->ordered()->get();
        }
        $ids = self::lessons($user, $term)->whereNotNull('level_id')->pluck('level_id')->unique()->all();

        return Level::whereIn('id', $ids ?: [0])->ordered()->get();
    }

    /** @return Collection<int, LevelSubject> */
    public static function levelSubjects(User $user, AcademicTerm $term): Collection
    {
        $rows = LevelSubject::with(['level', 'subject'])->where('academic_term_id', $term->id)->orderBy('level_id')->orderBy('sort')->orderBy('id')->get();
        if (self::manager($user)) {
            return $rows;
        }

        return $rows->filter(fn (LevelSubject $ls) => (int) $ls->teacher_id === (int) $user->id
            || Lesson::whereIn('id', TeacherScope::lessonIds($user, $ls->subject_id))->where('level_id', $ls->level_id)
                ->tap(fn ($q) => TermScope::via($q, $term->id))->exists())->values();
    }

    public static function studentInReach(User $user, Student $student, AcademicTerm $term, ?Lesson $lesson = null): bool
    {
        if (! Track::allows($user, $student->gender)) {
            return false;
        }
        $lessons = self::lessons($user, $term)->select('lessons.id');
        if ($lesson) {
            $lessons->whereKey($lesson->id);
        }
        if (self::manager($user) && ! $lesson) {
            return true;
        }

        return LessonStudent::where('student_id', $student->id)->where('status', LessonStudentStatus::Active->value)
            ->whereIn('lesson_id', $lessons)->exists();
    }

    /** May this user write a note with these targets? */
    public static function canWrite(User $user, Note $note, AcademicTerm $term): bool
    {
        if (! $user->can('notes.manage')) {
            return false;
        }

        return match ($note->scope) {
            'general' => true,
            'student' => $note->student && self::studentInReach($user, $note->student, $term, $note->lesson),
            'level' => $note->level_id && self::levels($user, $term)->contains('id', $note->level_id),
            'level_subject' => $note->level_subject_id && self::levelSubjects($user, $term)->contains('id', $note->level_subject_id),
            default => false,
        };
    }

    /** Authors edit and delete their own notes; managers any note of their reach. */
    public static function canChange(User $user, Note $note): bool
    {
        if (! $user->can('notes.manage')) {
            return false;
        }
        if ($note->author_id !== null && (int) $note->author_id === (int) $user->id) {
            return true;
        }

        return self::manager($user) && (! $note->student || Track::allows($user, $note->student->gender));
    }
}
