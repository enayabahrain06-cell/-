<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A book of the term (الكتب), for one level or for every student of the term. */
class Book extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_fils' => 'integer', 'stock' => 'integer', 'academic_term_id' => 'integer', 'level_id' => 'integer', 'subject_id' => 'integer'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(BookDelivery::class);
    }
}
