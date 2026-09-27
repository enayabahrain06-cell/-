<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => QuestionType::class, 'options' => 'array', 'correct_answer' => 'array', 'marks' => 'integer', 'sort_order' => 'integer'];
    }

    public function isObjective(): bool
    {
        return $this->type !== QuestionType::Recitation;
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
