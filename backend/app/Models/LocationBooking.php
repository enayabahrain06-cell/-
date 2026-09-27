<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BookingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationBooking extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source' => BookingSource::class, 'booking_date' => DateOnly::class];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
