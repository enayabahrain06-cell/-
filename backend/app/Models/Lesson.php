<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\LessonStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** A lesson always belongs to its package gender track (male, female, or the mixed early-years exception). */
    protected static function booted(): void
    {
        static::saving(function (Lesson $lesson) {
            if ($lesson->package_id && ($lesson->isDirty('package_id') || ! $lesson->gender)) {
                $lesson->gender = Package::whereKey($lesson->package_id)->toBase()->value('gender');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'gender' => \App\Enums\PackageGender::class,
            'days' => 'array',
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'capacity' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lessonStudents(): HasMany
    {
        return $this->hasMany(LessonStudent::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'lesson_students')->withPivot(['status', 'joined_at', 'left_at', 'current_memorization', 'current_revision'])->withTimestamps();
    }

    public function activeStudents(): BelongsToMany
    {
        return $this->students()->wherePivot('status', 'active');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(LessonLocationOverride::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function activeStudentCount(): int
    {
        return $this->lessonStudents()->where('status', 'active')->count();
    }
}
