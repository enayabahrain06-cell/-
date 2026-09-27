<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competition extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'registration_opens_at' => 'datetime', 'registration_closes_at' => 'datetime',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'results_published_at' => 'datetime',
            'registration_open_notified_at' => 'datetime', 'registration_close_notified_at' => 'datetime',
            'min_age' => 'integer', 'max_age' => 'integer', 'max_participants' => 'integer',
        ];
    }

    public function name(string $locale): string
    {
        return $locale === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(CompetitionRound::class)->orderBy('sort_order');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CompetitionParticipant::class);
    }

    public function judges(): HasMany
    {
        return $this->hasMany(CompetitionJudge::class);
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(CompetitionPrize::class)->orderBy('rank');
    }

    public function scopedLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'scope_lesson_id');
    }

    public function scopedPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'scope_package_id');
    }
}
