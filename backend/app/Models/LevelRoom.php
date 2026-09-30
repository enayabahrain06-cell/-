<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A room assigned to a level for one term (غرف المستويات). */
class LevelRoom extends Model
{
    protected $guarded = ['id'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
