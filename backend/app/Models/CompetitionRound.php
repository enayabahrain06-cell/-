<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionRound extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'round_date' => \App\Casts\DateOnly::class, 'reminder_sent_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(CompetitionScore::class, 'round_id');
    }

    /** Criteria of this round, falling back to the competition criteria. */
    public function effectiveCriteria(): array
    {
        return $this->criteria ?: ($this->competition?->criteria ?? []);
    }
}
