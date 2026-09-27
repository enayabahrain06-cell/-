<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeParticipant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'completed_at' => 'datetime', 'rewarded_at' => 'datetime', 'progress_computed_at' => 'datetime', 'nudged_half_at' => 'datetime', 'nudged_deadline_at' => 'datetime', 'progress_value' => 'integer', 'progress_pct' => 'integer'];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
