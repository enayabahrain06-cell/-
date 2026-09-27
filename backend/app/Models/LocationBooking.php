<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BookingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationBooking extends Model
{
    protected $guarded = ['id'];

    /** Exam bookings take the exam gender. Manual and event bookings state their gender explicitly. */
    protected static function booted(): void
    {
        static::saving(function (LocationBooking $booking) {
            if ($booking->exam_id && ! $booking->gender) {
                $booking->gender = Exam::whereKey($booking->exam_id)->toBase()->value('gender');
            }
        });
    }

    protected function casts(): array
    {
        return ['source' => BookingSource::class, 'booking_date' => DateOnly::class, 'gender' => \App\Enums\Gender::class];
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
