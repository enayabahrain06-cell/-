<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionParticipant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'rewarded_at' => 'datetime', 'result_notified_at' => 'datetime', 'final_rank' => 'integer', 'final_score_x100' => 'integer', 'seed_no' => 'integer'];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(CompetitionScore::class, 'participant_id');
    }
}
