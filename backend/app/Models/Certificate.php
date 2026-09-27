<?php

namespace App\Models;

use App\Enums\CertificateGrade;
use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A certificate goes draft → approved (→ revoked). Only approved certificates reach the family,
 * carry a valid verification QR and count prints. The PDF lives in media (collection = certificate).
 */
class Certificate extends Model
{
    use HasFactory, HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CertificateType::class,
            'status' => CertificateStatus::class,
            'grade' => CertificateGrade::class,
            'source' => CertificateSource::class,
            'details' => 'array',
            'issued_on' => 'date',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Certificate $c) {
            $c->certificate_no ??= static::nextNo();
            $c->verify_token ??= Str::random(32);
            $c->status ??= CertificateStatus::Draft;
            $c->source ??= CertificateSource::Manual;
        });
    }

    public static function nextNo(): string
    {
        $prefix = 'C'.now()->format('y');
        $last = static::where('certificate_no', 'like', "{$prefix}%")->orderByDesc('certificate_no')->value('certificate_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%05d', $prefix, $seq);
    }

    public function isDraft(): bool
    {
        return $this->status === CertificateStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === CertificateStatus::Approved;
    }

    /** Draft or approved: blocks a duplicate for the same achievement. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', '!=', CertificateStatus::Revoked->value);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
