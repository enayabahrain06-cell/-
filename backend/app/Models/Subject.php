<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A taught subject (المادة): Quran, fiqh, aqeedah, seerah, akhlaq... Quran is a system subject (code "quran")
 * that cannot be deleted; the memorization ledger keeps working on its own and does not depend on this row.
 */
class Subject extends Model
{
    public const QURAN = 'quran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean', 'sort' => 'integer'];
    }

    /** Id of the Quran subject: the default for evaluations and exams that name no subject. */
    public static function quranId(): ?int
    {
        return static::where('code', self::QURAN)->value('id');
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }
}
