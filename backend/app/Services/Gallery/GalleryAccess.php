<?php

namespace App\Services\Gallery;

use App\Enums\LessonStudentStatus;
use App\Enums\PackageGender;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\CompetitionParticipant;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who sees and changes what in معرض الصور. These are photos of children, so every file request goes through here.
 *
 * - Staff with gallery.view see every album of their gender track, whatever its visibility.
 * - gallery.manage (super_admin, supervisor): create, edit, share with guardians, السماح بالتحميل, delete, download.
 * - gallery.upload (teachers): create albums linked to a class they teach and upload into them. Those albums stay
 *   staff-only; a teacher edits their own album and deletes the photos they uploaded.
 * - Guardians (and students with their own account) see an album only after it is shared: `linked` when one of
 *   their children is in the linked class / level / competition, `all_guardians` when one of their children is in
 *   the album's gender track. They download only when the album allows it.
 */
final class GalleryAccess
{
    public static function manager(User $user): bool
    {
        return $user->can('gallery.manage');
    }

    public static function staff(User $user): bool
    {
        return $user->can('gallery.view');
    }

    /** Staff albums query of this user's track. */
    public static function staffAlbums(User $user): Builder
    {
        return Track::scope(Album::query(), $user);
    }

    public static function canView(User $user, Album $album): bool
    {
        if (self::staff($user)) {
            return Track::allows($user, $album->gender);
        }

        return self::familyAllowed($user, $album);
    }

    /** May this user put photos into the album? */
    public static function canUpload(User $user, Album $album): bool
    {
        if (! Track::allows($user, $album->gender)) {
            return false;
        }
        if (self::manager($user)) {
            return true;
        }

        return $user->can('gallery.upload') && self::teachesLinkedClass($user, $album);
    }

    /** Title, description, date, cover and order. */
    public static function canEdit(User $user, Album $album): bool
    {
        if (self::manager($user)) {
            return Track::allows($user, $album->gender);
        }

        return (int) $album->created_by === (int) $user->id && self::canUpload($user, $album);
    }

    /** Visibility and السماح بالتحميل: managers only, never teachers. */
    public static function canShare(User $user, Album $album): bool
    {
        return self::manager($user) && Track::allows($user, $album->gender);
    }

    public static function canDelete(User $user, Album $album): bool
    {
        return self::canShare($user, $album);
    }

    public static function canDeletePhoto(User $user, AlbumPhoto $photo): bool
    {
        $album = $photo->album;
        if (self::manager($user)) {
            return Track::allows($user, $album->gender);
        }

        return (int) $photo->uploaded_by === (int) $user->id && self::canUpload($user, $album);
    }

    public static function canDownload(User $user, Album $album): bool
    {
        if (self::staff($user)) {
            return self::manager($user) && Track::allows($user, $album->gender);
        }

        return $album->allow_download && self::familyAllowed($user, $album);
    }

    /** May this teacher create an album linked to this class? Managers may link anything in their track. */
    public static function canCreateFor(User $user, ?string $linkType, ?int $linkId): bool
    {
        if (self::manager($user)) {
            return true;
        }
        if (! $user->can('gallery.upload') || $linkType !== 'lesson' || ! $linkId) {
            return false;
        }
        $lesson = Lesson::find($linkId);

        return $lesson !== null && TeacherScope::teaches($user, $lesson);
    }

    private static function teachesLinkedClass(User $user, Album $album): bool
    {
        if ($album->link_type !== 'lesson' || ! $album->link_id) {
            return false;
        }
        $lesson = Lesson::find($album->link_id);

        return $lesson !== null && TeacherScope::teaches($user, $lesson);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Families
    // -----------------------------------------------------------------------------------------------------------

    /** The signed-in student, or a guardian's children. */
    public static function family(User $user): Collection
    {
        return Student::where(fn ($q) => $q->where('user_id', $user->id)->orWhere('guardian_user_id', $user->id))->get();
    }

    public static function familyAllowed(User $user, Album $album): bool
    {
        if (! $album->isShared()) {
            return false;
        }
        $family = self::family($user);
        if ($family->isEmpty()) {
            return false;
        }

        return self::sharedWith($album, $family);
    }

    /** Is the shared album meant for one of these students? */
    public static function sharedWith(Album $album, Collection $students): bool
    {
        if ($album->visibility === 'all_guardians') {
            return $students->contains(fn (Student $s) => self::genderMatches($album, $s));
        }
        if ($album->visibility !== 'linked' || ! $album->link_type) {
            return false;
        }

        return self::linkedStudentIds($album)->whereIn('students.id', $students->pluck('id'))->exists();
    }

    /** Shared albums of a family, newest first. */
    public static function familyAlbums(Collection $students): Collection
    {
        if ($students->isEmpty()) {
            return collect();
        }

        return Album::where('visibility', '!=', 'staff')->orderByDesc('album_date')->orderByDesc('id')->get()
            ->filter(fn (Album $a) => self::sharedWith($a, $students))->values();
    }

    private static function genderMatches(Album $album, Student $student): bool
    {
        return $album->gender === PackageGender::Mixed->value || $album->gender === $student->gender?->value;
    }

    /**
     * Students in the linked record: the class's active students, the active students of the level's classes in the
     * album's term, or a competition's participants. Empty when the album has no link.
     */
    public static function linkedStudentIds(Album $album): Builder
    {
        $ids = match ($album->link_type) {
            'lesson' => LessonStudent::select('student_id')->where('lesson_id', $album->link_id)->where('status', LessonStudentStatus::Active->value),
            'level' => LessonStudent::select('student_id')->where('status', LessonStudentStatus::Active->value)
                ->whereIn('lesson_id', Lesson::select('lessons.id')->where('level_id', $album->link_id)
                    ->tap(fn ($q) => TermScope::via($q, $album->academic_term_id))),
            'competition' => CompetitionParticipant::select('student_id')->where('competition_id', $album->link_id),
            default => null,
        };

        return $ids === null
            ? Student::query()->whereRaw('1 = 0')
            : Student::query()->whereIn('students.id', $ids);
    }

    /** Students of the album's link who withheld photo consent (عدم الموافقة على التصوير). */
    public static function consentWithheld(Album $album): Collection
    {
        return self::linkedStudentIds($album)->where('photo_consent_withheld', true)->orderBy('full_name')->get();
    }
}
