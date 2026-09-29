<?php

namespace App\Certificates;

use Ahl\Certificates\Models\Certificate;
use Ahl\Certificates\Support\DefaultHost;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Student;
use App\Models\User;
use App\Support\Track;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How the certificates package fits this system: settings from the settings store, the gender track and
 * teachers' own circles limit the list, students are searched by name and number, dates show the Hijri
 * calendar, and the verification page lives on the SPA.
 */
class AhlCertificateHost extends DefaultHost
{
    public function setting(string $key, mixed $default = null): mixed
    {
        return setting("certificates.{$key}", $default);
    }

    /** Staff see their gender track; teachers (no students.manage / certificates.approve) only their own circles. */
    public function scopeVisible(Builder $query, Authenticatable $user): void
    {
        /** @var User $user */
        Track::scopeVia($query, $user, 'student');
        if (! $user->can('students.manage') && ! $user->can('certificates.approve')) {
            $query->whereHas('student.lessonStudents', fn ($w) => $w->where('status', 'active')->whereIn('lesson_id', $user->lessons()->select('id')));
        }
    }

    public function searchRecipients(Builder $query, string $like): void
    {
        $query->orWhereHas('student', fn ($st) => $st->where('full_name', 'like', $like)->orWhere('student_no', 'like', $like));
    }

    /** The student card the SPA already uses elsewhere, plus the package's id / type / name. */
    public function presentRecipient(Model $recipient): array
    {
        $card = $recipient instanceof Student ? (new StudentSummaryResource($recipient))->resolve(request()) : [];

        return parent::presentRecipient($recipient) + $card;
    }

    /**
     * {lesson} is the circle: an alias of {context} still filled for templates written before the package.
     * It is not offered in the editor (placeholderKeys), where {context} does the same.
     */
    public function placeholders(Certificate $certificate, string $locale): array
    {
        return ['lesson' => $certificate->context_id ? (string) ($certificate->context?->name ?? '') : ''];
    }

    public function issuer(string $locale): string
    {
        return (string) setting($locale === 'en' ? 'authority.name_en' : 'authority.name_ar', config('ahl.authority.name_'.($locale === 'en' ? 'en' : 'ar')));
    }

    public function secondaryDate(CarbonInterface $date, string $locale): ?string
    {
        return setting('locale.show_hijri', true) ? (hijri_date($date, $locale === 'en' ? 'en' : 'ar') ?: null) : null;
    }

    public function displayTime(?CarbonInterface $time): ?CarbonInterface
    {
        return display_tz($time);
    }
}
