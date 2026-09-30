<?php

namespace App\Models;

use App\Enums\WeekDay;
use Illuminate\Database\Eloquent\Model;

/** A night (weekday) the centre runs (الليالي), with its default times. One row per weekday. */
class Night extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekday' => WeekDay::class, 'is_active' => 'boolean', 'sort' => 'integer'];
    }
}
