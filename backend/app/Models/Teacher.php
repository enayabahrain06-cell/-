<?php

namespace App\Models;

use App\Enums\Gender;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    // The teacher's photo is stored as media (photo + photo_thumb) on this record.
    use HasFactory, HasMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gender' => Gender::class, 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
