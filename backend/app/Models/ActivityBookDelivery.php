<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The activity's book handed to a registered student; its price, when charged, is the registration's book invoice. */
class ActivityBookDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delivered_at' => \App\Casts\DateOnly::class, 'activity_id' => 'integer', 'student_id' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
