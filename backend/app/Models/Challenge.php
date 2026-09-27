<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => \App\Casts\DateOnly::class, 'ends_at' => \App\Casts\DateOnly::class, 'goal_value' => 'integer', 'reward_points' => 'integer', 'min_age' => 'integer', 'max_age' => 'integer', 'min_score' => 'integer'];
    }

    public function name(string $locale): string
    {
        return $locale === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }

    public function rewardBadge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, 'reward_badge_id');
    }
}
