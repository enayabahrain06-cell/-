<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    use HasFactory, HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime',
            'question_order' => 'array',
            'auto_score' => 'integer', 'manual_score' => 'integer', 'total_score' => 'integer',
            'passed' => 'boolean',
            'attempt_no' => 'integer',
            'recommended_level' => \App\Enums\MemorizationLevel::class,
        ];
    }

    /** Placement attempts are anonymous: the family holds a random token and only its hash is stored. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('access_token', self::hashToken($token))->first();
    }

    public function registrationRequest(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class);
    }

    public function isExpiredAt(\DateTimeInterface $at): bool
    {
        return $this->expires_at !== null && $at > $this->expires_at;
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
