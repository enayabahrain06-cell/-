<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Enums\MemorizationLevel;
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
        // An exam without a subject is a Quran exam (every exam before subjects existed was).
        static::creating(function (Exam $exam) {
            $exam->subject_id ??= Subject::quranId();
        });
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
            'level_bands' => 'array',
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

    /** Exams for enrolled students: everything except registration placement tests. */
    public function scopeForStudents(\Illuminate\Database\Eloquent\Builder $q): void
    {
        $q->where('type', '!=', ExamType::Placement->value);
    }

    public function isPlacement(): bool
    {
        return $this->type === ExamType::Placement;
    }

    /** The placement test a family must take for this package right now, if any: published and inside its window. */
    public static function activePlacementFor(int $packageId, ?\DateTimeInterface $at = null): ?self
    {
        $at ??= now();

        return static::where('type', ExamType::Placement->value)->where('package_id', $packageId)
            ->where('status', ExamStatus::Published->value)->where('opens_at', '<=', $at)->where('closes_at', '>=', $at)
            ->orderByDesc('opens_at')->first();
    }

    /** Recommended level for a percentage: the highest band whose minimum the score reaches. */
    public function levelForPercent(float $percent): ?MemorizationLevel
    {
        $band = collect($this->level_bands ?? [])->sortByDesc('min')->first(fn ($b) => $percent >= (float) $b['min']);

        return $band ? MemorizationLevel::tryFrom($band['level']) : null;
    }

    public function isOpenAt(\DateTimeInterface $at): bool
    {
        return $this->status === ExamStatus::Published && $at >= $this->opens_at && $at <= $this->closes_at;
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
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

    /** Exam-pass certificates (source exam, source_id = exam id). */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'source_id')->where('source', \App\Enums\CertificateSource::Exam->value);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
