<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionPrize extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rank' => 'integer', 'points' => 'integer'];
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
