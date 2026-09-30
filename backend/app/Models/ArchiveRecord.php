<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One archived result of a previous year, matched to a student when possible. */
class ArchiveRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['student_id' => 'integer', 'archive_batch_id' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ArchiveBatch::class, 'archive_batch_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
