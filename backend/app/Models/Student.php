<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\MemorizationLevel;
use App\Enums\StudentStatus;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Student extends Model
{
    use HasFactory, HasMedia, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'status' => StudentStatus::class,
            'memorization_level' => MemorizationLevel::class,
            'locale' => Locale::class,
            'birth_date' => \App\Casts\DateOnly::class,
            'yearly_target_ayahs' => 'integer',
            'progress_surah' => 'integer', 'progress_ayah' => 'integer', 'progress_juz' => 'integer', 'memorized_ayahs' => 'integer',
        ];
    }

    public static function nextStudentNo(): string
    {
        $year = now()->format('y');
        $last = static::withTrashed()->where('student_no', 'like', "S{$year}%")->orderByDesc('student_no')->value('student_no');
        $seq = $last ? ((int) substr($last, 3)) + 1 : 1;

        return sprintf('S%s%05d', $year, $seq);
    }

    /** Age in whole years on a given date (server-side, never trusted from the client). */
    public function ageOn(\DateTimeInterface $date): int
    {
        return $this->birth_date->diffInYears($date);
    }

    public function initial(): string
    {
        return Str::substr(trim($this->full_name), 0, 1);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function lessonStudents(): HasMany
    {
        return $this->hasMany(LessonStudent::class);
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_students')->withPivot(['status', 'joined_at', 'left_at', 'current_memorization', 'current_revision'])->withTimestamps();
    }

    public function activeLessons(): BelongsToMany
    {
        return $this->lessons()->wherePivot('status', 'active');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(StudentProgress::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(StudentIssue::class);
    }

    /** Package of the student's current circle (first active enrolment), used for direction and plan. */
    public function currentPackage(): ?Package
    {
        return $this->activeLessons()->with('package')->orderBy('lesson_students.joined_at')->first()?->package;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function examAttempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function registrationRequests(): HasMany
    {
        return $this->hasMany(RegistrationRequest::class);
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }

    /** Phone that receives the student's own messages (falls back to guardian). */
    public function primaryPhone(): string
    {
        return $this->student_phone ?: $this->guardian_phone;
    }

    /** Temporary signed URL (10 minutes) for a photo variant, or null when there is no photo. */
    public function photoUrl(string $size = 'thumb'): ?string
    {
        $path = $size === 'profile' ? $this->photo_path : $this->photo_thumb_path;
        if (! $path) {
            return null;
        }

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'media.student-photo',
            now()->addMinutes((int) config('ahl.media.signed_url_minutes', 10)),
            ['student' => $this->id, 'size' => $size]
        );
    }

    /** @return array{profile: string|null, thumb: string|null} */
    public function photoUrls(): array
    {
        return ['profile' => $this->photoUrl('profile'), 'thumb' => $this->photoUrl('thumb')];
    }
}
