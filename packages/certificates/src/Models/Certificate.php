<?php

namespace Ahl\Certificates\Models;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A certificate goes draft → approved (→ revoked). Only approved certificates carry a valid verification
 * QR, count prints and keep a stored PDF. The recipient and the optional context are polymorphic.
 *
 * @property string $certificate_no
 * @property string $type
 * @property CertificateStatus $status
 * @property string|null $grade
 * @property string $source
 */
class Certificate extends Model
{
    protected $table = 'certificates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'details' => 'array',
            'issued_on' => 'date',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'print_count' => 'integer',
            'source_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Certificate $c) {
            $c->certificate_no ??= static::nextNo();
            $c->verify_token ??= Str::random(32);
            $c->status ??= CertificateStatus::Draft;
            $c->source ??= 'manual';
        });
    }

    /** Next serial: prefix + two-digit year + zero-padded sequence. */
    public static function nextNo(): string
    {
        $prefix = config('certificates.number.prefix', 'C').now()->format('y');
        $digits = (int) config('certificates.number.digits', 5);
        $last = static::query()->where('certificate_no', 'like', "{$prefix}%")->orderByDesc('certificate_no')->value('certificate_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, $digits, '0', STR_PAD_LEFT);
    }

    public function isDraft(): bool
    {
        return $this->status === CertificateStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === CertificateStatus::Approved;
    }

    public function isRevoked(): bool
    {
        return $this->status === CertificateStatus::Revoked;
    }

    /** Draft or approved: blocks a duplicate for the same achievement. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', '!=', CertificateStatus::Revoked->value);
    }

    public function scopeForRecipient(Builder $q, Model $recipient): Builder
    {
        return $q->where('recipient_type', $recipient->getMorphClass())->where('recipient_id', $recipient->getKey());
    }

    public function scopeFromSource(Builder $q, string $source, int|string|null $sourceId = null): Builder
    {
        return $q->where('source', $source)->when($sourceId !== null, fn ($w) => $w->where('source_id', $sourceId));
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Certificates::templateModel(), 'template_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'issued_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'approved_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'revoked_by');
    }

    public function typeLabel(?string $locale = null): ?string
    {
        return Certificates::label('types', $this->type, $locale);
    }

    public function gradeLabel(?string $locale = null): ?string
    {
        return Certificates::label('grades', $this->grade, $locale);
    }

    public function sourceLabel(?string $locale = null): ?string
    {
        return Certificates::label('sources', $this->source, $locale);
    }

    /** @return class-string<Model> */
    protected static function userModel(): string
    {
        return config('certificates.user_model') ?? config('auth.providers.users.model');
    }
}
