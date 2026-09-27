<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionScore extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criteria_scores' => 'array', 'total_x100' => 'integer'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CompetitionParticipant::class, 'participant_id');
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_id');
    }
}
