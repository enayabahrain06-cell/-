<?php

namespace App\Models;

use Ahl\Certificates\Models\Certificate as BaseCertificate;
use App\Enums\MediaCollection;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The certificates package model with this system's relations: every recipient is a student and the
 * context is the circle (lesson). The approved PDF lives in media (collection = certificate).
 */
class Certificate extends BaseCertificate
{
    use HasMedia;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'recipient_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'context_id');
    }

    /** Media gates resolve the owner through student_id. */
    public function getStudentIdAttribute(): ?int
    {
        return $this->recipient_type === (new Student)->getMorphClass() ? (int) $this->recipient_id : null;
    }

    public function pdfMedia(): ?Media
    {
        return $this->mediaIn(MediaCollection::Certificate);
    }
}
