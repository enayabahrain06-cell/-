<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only reference row (seeded by its migration from App\Support\Quran). */
class QuranSurah extends Model
{
    protected $primaryKey = 'number';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['number' => 'integer', 'ayah_count' => 'integer', 'juz_start' => 'integer'];
    }
}
