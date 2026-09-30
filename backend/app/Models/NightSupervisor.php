<?php

namespace App\Models;

use App\Enums\WeekDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supervisor on duty on one weekday (night) of a term (مشرفو الليالي). */
class NightSupervisor extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekday' => WeekDay::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
