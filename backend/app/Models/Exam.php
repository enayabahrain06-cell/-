<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Exam gender follows its package, else its circle. */
    protected static function booted(): void
    {
        static::saving(function (Exam $exam) {
            if ($exam->isDirty(['package_id', 'lesson_id']) || ! $exam->gender) {
                $exam->gender = $exam->package_id
                    ? Package::whereKey($exam->package_id)->toBase()->value('gender')
                    : Lesson::whereKey($exam->lesson_id)->toBase()->value('gender');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ExamType::class,
            'gender' => \App\Enums\PackageGender::class,
            'status' => ExamStatus::class,
            'exam_date' => 'date',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'randomize' => 'boolean',
            'reminder_day_sent_at' => 'datetime',
            'reminder_hour_sent_at' => 'datetime',
            'results_sent_at' => 'datetime',
            'duration_minutes' => 'integer', 'total_marks' => 'integer', 'pass_mark' => 'integer',
        ];
    }

    /**
     * Store the window in UTC. Strings with an offset are converted; strings without one are read as
     * the authority's display timezone (what staff type in the form). Carbon/DateTime values keep their instant.
     */
    protected function opensAt(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(fn ($v) => self::toUtc($v));
    }

    protected function closesAt(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(fn ($v) => self::toUtc($v));
    }

    public static function toUtc(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $c = $v instanceof \DateTimeInterface
            ? \Carbon\Carbon::instance($v)
            : \Carbon\Carbon::parse((string) $v, config('ahl.display_timezone', 'Asia/Bahrain'));

        return $c->utc()->format('Y-m-d H:i:s');
    }

    public function isOpenAt(\DateTimeInterface $at): bool
    {
        return $this->status === ExamStatus::Published && $at >= $this->opens_at && $at <= $this->closes_at;
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
