<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Delivery health and opt-out per WhatsApp number (E.164). */
class PhoneStatus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['failed_count' => 'integer', 'invalid_at' => 'datetime', 'opted_out_at' => 'datetime'];
    }
}
