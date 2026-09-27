<?php

namespace App\Models;

use App\Enums\ProgressType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProgress extends Model
{
    protected $table = 'student_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => ProgressType::class, 'recorded_on' => 'date', 'surah_number' => 'integer', 'from_ayah' => 'integer', 'to_ayah' => 'integer', 'ayah_count' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
