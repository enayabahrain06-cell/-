<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One archive upload (رفع الأرشيف): a file of previous years' records. */
class ArchiveBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rows_count' => 'integer', 'matched_count' => 'integer'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(ArchiveRecord::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
